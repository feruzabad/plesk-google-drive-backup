#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/src/meta.xml" | head -1)"
VERSION="${VERSION:-1.0.0}"
BUILD="$ROOT/build"
EXT="$BUILD/extension"
COMPOSER_DIR="$BUILD/composer"
DIST="$ROOT/dist"

run_composer() {
    if command -v composer >/dev/null 2>&1; then
        (cd "$COMPOSER_DIR" && composer "$@")
        return
    fi
    if command -v docker >/dev/null 2>&1; then
        docker run --rm \
            --user "$(id -u):$(id -g)" \
            -e COMPOSER_HOME=/tmp/composer \
            -v "$ROOT":/app \
            -w /app/build/composer \
            composer:2 composer "$@"
        return
    fi
    echo "Neither composer nor docker is available." >&2
    exit 1
}

run_php() {
    if command -v php >/dev/null 2>&1; then
        php "$@"
        return
    fi
    if command -v docker >/dev/null 2>&1; then
        docker run --rm \
            --user "$(id -u):$(id -g)" \
            --entrypoint php \
            -v "$ROOT":/app \
            -w /app \
            composer:2 "$@"
        return
    fi
    echo "Neither php nor docker is available." >&2
    exit 1
}

rm -rf "$BUILD" "$DIST"
mkdir -p "$EXT" "$COMPOSER_DIR" "$DIST"

# Copy the extension payload (meta.xml must be at the ZIP root).
rsync -a --exclude '.DS_Store' "$ROOT/src/" "$EXT/"
cp "$ROOT/DESCRIPTION.md" "$ROOT/CHANGES.md" "$ROOT/LICENSE" "$EXT/"
if [ -f "$ROOT/README.md" ]; then cp "$ROOT/README.md" "$EXT/"; fi
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$COMPOSER_DIR/" 2>/dev/null || cp "$ROOT/composer.json" "$COMPOSER_DIR/"

# Install production dependencies in a staging composer project.
run_composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# Prune the generated services package before regenerating the classmap.
run_php scripts/prune-google-services.php build/composer/vendor

# Regenerate the optimized autoloader from the pruned tree.
run_composer dump-autoload --no-dev --optimize --no-interaction

# Bundle the runtime vendor tree inside the extension.
rm -rf "$EXT/plib/vendor"
cp -R "$COMPOSER_DIR/vendor" "$EXT/plib/vendor"

# Remove development leftovers from the package.
find "$EXT" -name '.DS_Store' -delete
find "$EXT" -name '*.map' -delete

(
    cd "$EXT"
    zip -qr "$DIST/gdrive-backup-$VERSION.zip" .
)

echo "Built $DIST/gdrive-backup-$VERSION.zip"
du -h "$DIST/gdrive-backup-$VERSION.zip"
