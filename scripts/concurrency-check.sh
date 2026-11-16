#!/usr/bin/env bash
#
# Real-concurrency check for two acceptance tests the PHPUnit suite can only
# approximate sequentially:
#
#   AT12  "Two simultaneous callback retries with the same UUID"
#         -> exactly one stored request and exactly one notification claim
#   AT18  "Parallel report increments"
#         -> the aggregate count equals the number of increments, on one row
#
# Spawns WORKERS separate PHP processes (separate DB connections) that all
# start at the same instant against the dev site's database, then verifies the
# result. Tagged, throwaway rows only — removed again on exit.
#
# Run inside the wp-env cli container:
#   npm run test:concurrency
#
# MEMORY WARNING: Docker Desktop's VM is often only ~2 GB shared by every
# container. Workers therefore boot WordPress core only (--skip-plugins), and
# the default is 8 of them. Sixteen workers that each loaded the full plugin
# set once got both MariaDB containers OOM-killed on a 2 GB VM — raise
# WORKERS only if you have raised Docker's memory first.
#
set -euo pipefail

WORKERS="${WORKERS:-8}"
PER_WORKER="${PER_WORKER:-25}"
LEAD="${LEAD:-8}"  # seconds for every worker to boot before the shared start gun

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$(mktemp -d)"
UUID="$(cat /proc/sys/kernel/random/uuid)"
# WP-CLI can print PHP deprecation notices after the real output, so pull the
# value out by shape instead of trusting "the last line".
num() { grep -E '^[0-9]+$' | tail -1; }
PREFIX="$(wp db prefix 2>/dev/null | grep -E '^[A-Za-z0-9_]+$' | tail -1)"
FAILED=0

cleanup() {
    wp db query "DELETE FROM ${PREFIX}clift_daily_stats WHERE bar_id = 'stress-at18'" >/dev/null 2>&1 || true
    wp db query "DELETE FROM ${PREFIX}clift_callbacks WHERE request_uuid = '${UUID}'" >/dev/null 2>&1 || true
    rm -rf "${OUT}"
}
trap cleanup EXIT

cleanup_before() {
    wp db query "DELETE FROM ${PREFIX}clift_daily_stats WHERE bar_id = 'stress-at18'" >/dev/null 2>&1 || true
}
cleanup_before

check() { # label, expected, actual
    if [ "$2" = "$3" ]; then
        echo "  PASS  $1 (= $3)"
    else
        echo "  FAIL  $1 — expected $2, got $3"
        FAILED=1
    fi
}

START=$(( $(date +%s) + LEAD ))
echo "==> ${WORKERS} parallel workers x ${PER_WORKER} increments, shared start in ${LEAD}s"

for i in $(seq 1 "${WORKERS}"); do
    wp --skip-plugins --skip-themes eval-file "${SRC}/concurrency-worker.php" "${START}" "${PER_WORKER}" "${UUID}" > "${OUT}/w${i}.out" 2>&1 &
done
wait

ALL="${OUT}"/w*.out

created=$(cat ${ALL} | grep -c '^RESULT:created$' || true)
duplicate=$(cat ${ALL} | grep -c '^RESULT:duplicate$' || true)
other=$(cat ${ALL} | grep '^RESULT:' | grep -vcE '^RESULT:(created|duplicate)$' || true)
claims=$(cat ${ALL} | grep -c '^CLAIM:yes$' || true)
finished=$(cat ${ALL} | grep -c '^DONE$' || true)
errors=$(cat ${ALL} | grep -ciE 'fatal error|parse error|uncaught|WordPress database error' || true)

rows=$(wp db query "SELECT COUNT(*) FROM ${PREFIX}clift_callbacks WHERE request_uuid = '${UUID}'" --skip-column-names | num)
attempts=$(wp db query "SELECT COALESCE(MAX(attempts), 0) FROM ${PREFIX}clift_callbacks WHERE request_uuid = '${UUID}'" --skip-column-names | num)
stat_rows=$(wp db query "SELECT COUNT(*) FROM ${PREFIX}clift_daily_stats WHERE bar_id = 'stress-at18'" --skip-column-names | num)
stat_sum=$(wp db query "SELECT COALESCE(SUM(click_count), 0) FROM ${PREFIX}clift_daily_stats WHERE bar_id = 'stress-at18'" --skip-column-names | num)

echo "==> AT12 — simultaneous callback retries, same UUID and payload"
check "workers that finished"                  "${WORKERS}"                 "${finished}"
check "workers that created the request"       "1"                          "${created}"
check "workers told it was a duplicate"        "$(( WORKERS - 1 ))"         "${duplicate}"
check "unexpected insert results"              "0"                          "${other}"
check "stored rows for this UUID"              "1"                          "${rows}"
check "workers that won the notification claim" "1"                         "${claims}"
check "notification attempts recorded"         "1"                          "${attempts}"

echo "==> AT18 — parallel increments of one counter"
check "stat rows (one dimension set)"          "1"                          "${stat_rows}"
check "aggregate click_count"                  "$(( WORKERS * PER_WORKER ))" "${stat_sum}"

echo "==> No PHP/database errors in any worker"
check "error lines"                            "0"                          "${errors}"

if [ "${FAILED}" -ne 0 ]; then
    echo "CONCURRENCY CHECK FAILED"
    exit 1
fi

echo "CONCURRENCY CHECK PASSED"
