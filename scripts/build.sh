#!/usr/bin/env bash
#
# Builds clean, distributable zips: dist/contactlift-<version>.zip always,
# dist/contactlift-pro-<version>.zip too once plugins/contactlift-pro exists.
#
# SAFETY WARNING — read before testing an output zip in wp-env:
#   Never run `wp plugin install <zip> --force` (or "Replace current with
#   uploaded") inside wp-env while this project's dev copy is bind-mounted
#   as an active plugin. WordPress/WP-CLI can match plugins by their
#   "Plugin Name" header rather than by folder — since the dev mount and a
#   built zip share that header, this has deleted an active bind-mounted
#   plugin source on a sibling project in this line, twice. Use
#   scripts/check-build.sh instead of installing a build manually — it
#   verifies a zip via a throwaway copy under an unmistakable name and
#   removes it again in the same run.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="${ROOT}/dist"
VERSION=$(grep -m1 '^ \* Version:' "${ROOT}/plugins/contactlift/contactlift.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')

fail() { echo "BUILD FAILED: $1" >&2; exit 1; }

build_plugin() {
    local slug="$1"
    local src="${ROOT}/plugins/${slug}"

    if [ ! -d "${src}" ]; then
        echo "==> Skipping ${slug}: plugins/${slug} does not exist yet"
        return 0
    fi

    local stage
    stage="$(mktemp -d)/${slug}"

    echo "==> Staging ${slug} into ${stage}"
    mkdir -p "${stage}"

    # The Freemius SDK is the one vendored dependency that must ship (Pro
    # loads vendor/freemius/start.php at runtime); every other vendor path is
    # dev tooling. rsync applies the first matching rule, so the includes
    # have to come before the excludes.
    rsync -a "${src}/" "${stage}/" \
        --exclude '.git' \
        --exclude 'node_modules' \
        --include 'vendor/' \
        --include 'vendor/freemius/' \
        --include 'vendor/freemius/**' \
        --exclude 'vendor' \
        --exclude 'vendor/**' \
        --exclude 'tests' \
        --exclude '*.log' \
        --exclude '.DS_Store' \
        --exclude '.phpunit.result.cache'

    echo "==> Sanity checks for ${slug}"

    local main_file="${stage}/${slug}.php"
    [ -f "${main_file}" ] || fail "main plugin file ${slug}.php not found in staged copy"

    local text_domain
    text_domain=$(grep -i '^[[:space:]]*\*[[:space:]]*Text Domain:' "${main_file}" | head -1 | sed -E 's/.*Text Domain:[[:space:]]*//I' | tr -d '\r')
    [ "${text_domain}" = "${slug}" ] || fail "Text Domain header ('${text_domain}') does not match slug ('${slug}')"

    [ -f "${stage}/readme.txt" ] || fail "readme.txt missing"
    grep -qi 'Tested up to:' "${stage}/readme.txt" || fail "readme.txt is missing a 'Tested up to:' line"

    if [ "${slug}" = "contactlift" ] && find "${stage}" -iname '*pro*' -not -path '*/assets/*' | grep -q .; then
        fail "a Pro-named file leaked into the free build"
    fi

    if find "${stage}" -iname '.wp-env.json' -o -iname 'phpcs.xml*' -o -iname 'phpunit*.xml*' -o -iname 'composer.json' -o -iname 'composer.lock' | grep -q .; then
        fail "dev tooling leaked into the staged copy"
    fi

    if [ "${slug}" = "contactlift-pro" ]; then
        # Placeholder mode = either Freemius constant still points at its
        # placeholder. Such a zip runs fine (licensing is simply inert) but
        # must never be sold; real mode needs the SDK actually in the zip.
        if grep -qE "^const FREEMIUS_PRODUCT_ID[[:space:]]*=[[:space:]]*FREEMIUS_PLACEHOLDER;" "${main_file}" \
            || grep -qE "^const FREEMIUS_PUBLIC_KEY[[:space:]]*=[[:space:]]*FREEMIUS_KEY_PLACEHOLDER;" "${main_file}"; then
            echo "WARNING: ${slug} still has placeholder Freemius credentials — licensing is DISABLED in this zip; it is NOT releasable." >&2
            [ "${CLIFT_RELEASE:-0}" != "1" ] || fail "CLIFT_RELEASE=1 but the Freemius constants in ${slug}.php are still placeholders"
        else
            [ -f "${stage}/vendor/freemius/start.php" ] || fail "Freemius credentials are set but vendor/freemius/start.php is not in the build — download the SDK into plugins/${slug}/vendor/freemius/ first (docs/owner-setup.md)"
        fi
    fi

    mkdir -p "${DIST}"
    local zip_path="${DIST}/${slug}-${VERSION}.zip"
    rm -f "${zip_path}"

    (
        cd "$(dirname "${stage}")"
        zip -rq "${zip_path}" "${slug}" -x '*.DS_Store'
    )

    rm -rf "$(dirname "${stage}")"

    local checksum
    checksum=$(shasum -a 256 "${zip_path}" | awk '{print $1}')
    echo "${checksum}  $(basename "${zip_path}")" > "${zip_path}.sha256"

    echo "==> Built ${zip_path}"
    echo "    SHA-256: ${checksum}"
}

build_plugin "contactlift"
build_plugin "contactlift-pro"

echo "==> Done. See ${DIST}/"
