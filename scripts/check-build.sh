#!/usr/bin/env bash
#
# Builds the free plugin and runs the official "Plugin Check" tool against
# the REAL distributable zip inside the local wp-env container — safely.
#
# Why this script exists: the dev copy of this plugin is bind-mounted into
# wp-env as an ACTIVE plugin (see .wp-env.json). Two things must never
# happen, matching the standing hazard documented for the sibling FormBridge
# project in this line:
#   1. `wp plugin install <zip> --force` must never run in this environment —
#      WordPress/WP-CLI can match plugins by their "Plugin Name" header
#      rather than by folder, which has deleted an active bind-mounted
#      plugin's source files on the sibling project, twice.
#   2. A second, checkable copy of the plugin must never be left sitting in
#      wp-content/plugins/ after the check — two folders both displaying the
#      same Plugin Name is exactly what caused that mistake.
#
# So this script: builds a fresh zip, plain-unzips it into the container
# under an unmistakable throwaway name, runs `wp plugin check` (works
# without activation — the copy is NEVER activated), prints the result, and
# deletes the throwaway copy again before exiting.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="contactlift"
VERSION=$(grep -m1 '^ \* Version:' "${ROOT}/plugins/contactlift/contactlift.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')
CHECK_DIR="zzz-CHECK-ONLY-DELETE-ME-${SLUG}"

echo "==> Building fresh zip"
bash "${ROOT}/scripts/build.sh"

CLI="$(docker ps --format '{{.Names}}' | grep -E 'cli-1' | grep -v tests | head -1 || true)"
if [ -z "${CLI}" ]; then
    echo "Could not find a running wp-env CLI container. Is wp-env started?" >&2
    exit 1
fi
echo "==> Using container: ${CLI}"

cleanup() {
    docker exec "${CLI}" sh -c "rm -rf '/var/www/html/wp-content/plugins/${CHECK_DIR}'" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Always start clean in case a previous run was interrupted before cleanup.
cleanup

echo "==> Copying zip into container"
docker cp "${ROOT}/dist/${SLUG}-${VERSION}.zip" "${CLI}:/tmp/${SLUG}.zip"

echo "==> Extracting as an INACTIVE throwaway copy (never via 'wp plugin install')"
docker exec "${CLI}" sh -c "
    set -e
    mkdir -p '/var/www/html/wp-content/plugins/${CHECK_DIR}'
    cd '/var/www/html/wp-content/plugins/${CHECK_DIR}'
    unzip -q '/tmp/${SLUG}.zip'
    mv '${SLUG}'/* '${SLUG}'/.[!.]* . 2>/dev/null || true
    rmdir '${SLUG}' 2>/dev/null || true
    rm -f '/tmp/${SLUG}.zip'
"

echo "==> Running Plugin Check (no activation needed)"
# --slug tells Plugin Check the real intended slug for its text-domain-vs-slug
# comparison, since the throwaway folder is deliberately NOT named that (see
# the warning above) — without it, every use of the text domain would show as
# a false-positive TextDomainMismatch against the throwaway folder name.
docker exec "${CLI}" wp plugin check "${CHECK_DIR}" --slug="${SLUG}" || true

echo "==> Done — throwaway copy will now be removed"
# `trap cleanup EXIT` above deletes ${CHECK_DIR} on exit, success or failure.
