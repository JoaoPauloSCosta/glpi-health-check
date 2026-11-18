#!/usr/bin/env sh
#
# Builds the release archive of the Health Check plugin.
#
#   tools/build-release.sh [git-ref]
#
# The archive is created with "git archive", so it only contains committed files;
# files marked "export-ignore" in .gitattributes (tools/, .gitignore...) are left out.
# Output: dist/glpi-healthcheck-<version>.tar.bz2, with a single "healthcheck/" root directory.
#
# Requirements: git, bzip2, php (for the validation step).

set -eu

cd "$(dirname "$0")/.."

REF="${1:-HEAD}"
PHP_BIN="${PHP_BIN:-php}"

VERSION=$(git show "$REF:setup.php" | sed -n "s/^define('PLUGIN_HEALTHCHECK_VERSION', '\([^']*\)');/\1/p")
if [ -z "$VERSION" ]; then
    echo "Unable to read PLUGIN_HEALTHCHECK_VERSION from setup.php at $REF" >&2
    exit 1
fi

if [ "$REF" = "HEAD" ] && [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Warning: uncommitted changes are NOT included in the archive (built from HEAD)." >&2
fi

mkdir -p dist
ARCHIVE="dist/glpi-healthcheck-$VERSION.tar.bz2"

git archive --format=tar --prefix=healthcheck/ "$REF" | bzip2 -9 > "$ARCHIVE"

echo "Built $ARCHIVE from $REF"

"$PHP_BIN" tools/validate-package.php "$ARCHIVE"

# Checksum to publish next to the archive
if command -v sha256sum >/dev/null 2>&1; then
    (cd dist && sha256sum "$(basename "$ARCHIVE")" > "$(basename "$ARCHIVE").sha256")
    echo "Checksum: $ARCHIVE.sha256"
fi
