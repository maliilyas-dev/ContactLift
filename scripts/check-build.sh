#!/usr/bin/env bash
#
# Builds both plugins and runs the official "Plugin Check" tool against the
# REAL distributable zips inside the local wp-env container — safely.
#
# Why this script exists: the dev copies of both plugins are bind-mounted
# into wp-env as ACTIVE plugins (see .wp-env.json). Two things must never
# happen, matching the standing hazard documented for the sibling FormBridge
# project in this line:
#   1. `wp plugin install <zip> --force` must never run in this environment —
#      WordPress/WP-CLI can match plugins by their "Plugin Name" header
#      rather than by folder, which has deleted an active bind-mounted
#      plugin's source files on the sibling project, twice.
#   2. A second, checkable copy of a plugin must never be left sitting in
#      wp-content/plugins/ after the check — two folders both displaying the
#      same Plugin Name is exactly what caused that mistake.
#
# So this script: builds fresh zips, plain-unzips each into the container
# under an unmistakable throwaway name, runs `wp plugin check` (works
# without activation — the copy is NEVER activated), prints the result, and
# deletes the throwaway copy again before exiting. Pass a slug
# ("contactlift" or "contactlift-pro") to check just one; with no argument,
# both are checked.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Building fresh zips"
bash "${ROOT}/scripts/build.sh"

CLI="$(docker ps --format '{{.Names}}' | grep -E 'cli-1' | grep -v tests | head -1 || true)"
if [ -z "${CLI}" ]; then
    echo "Could not find a running wp-env CLI container. Is wp-env started?" >&2
    exit 1
fi
echo "==> Using container: ${CLI}"

check_plugin() {
    local slug="$1"
    local zip_path="${ROOT}/dist/${slug}-${VERSION}.zip"

    if [ ! -f "${zip_path}" ]; then
        echo "==> Skipping ${slug}: ${zip_path} was not built"
        return 0
    fi

    local check_dir="zzz-CHECK-ONLY-DELETE-ME-${slug}"

    cleanup() {
        docker exec "${CLI}" sh -c "rm -rf '/var/www/html/wp-content/plugins/${check_dir}'" >/dev/null 2>&1 || true
    }
    trap cleanup EXIT
    # Always start clean in case a previous run was interrupted before cleanup.
    cleanup

    echo "==> [${slug}] Copying zip into container"
    docker cp "${zip_path}" "${CLI}:/tmp/${slug}.zip"

    echo "==> [${slug}] Extracting as an INACTIVE throwaway copy (never via 'wp plugin install')"
    docker exec "${CLI}" sh -c "
        set -e
        mkdir -p '/var/www/html/wp-content/plugins/${check_dir}'
        cd '/var/www/html/wp-content/plugins/${check_dir}'
        unzip -q '/tmp/${slug}.zip'
        mv '${slug}'/* '${slug}'/.[!.]* . 2>/dev/null || true
        rmdir '${slug}' 2>/dev/null || true
        rm -f '/tmp/${slug}.zip'
    "

    echo "==> [${slug}] Running Plugin Check (no activation needed)"
    # --slug tells Plugin Check the real intended slug for its text-domain-vs-slug
    # comparison, since the throwaway folder is deliberately NOT named that (see
    # the warning above) — without it, every use of the text domain would show as
    # a false-positive TextDomainMismatch against the throwaway folder name.
    docker exec "${CLI}" wp plugin check "${check_dir}" --slug="${slug}" || true

    echo "==> [${slug}] Done — throwaway copy will now be removed"
    cleanup
    trap - EXIT
}

VERSION=$(grep -m1 '^ \* Version:' "${ROOT}/plugins/contactlift/contactlift.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')

if [ $# -ge 1 ]; then
    check_plugin "$1"
else
    check_plugin "contactlift"
    check_plugin "contactlift-pro"
fi
