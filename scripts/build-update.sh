#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-0.1.1}"
MODULE_ID="codematrix.integrationinspector"
MODULE_DIR="$ROOT/local/modules/$MODULE_ID"
UPDATE_DIR="$ROOT/updates/$VERSION"
DIST_DIR="$ROOT/dist"
STAGE_DIR="$(mktemp -d)"
PACKAGE_DIR="$STAGE_DIR/$VERSION"
ARCHIVE="$DIST_DIR/$VERSION.zip"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Invalid version: $VERSION" >&2
    exit 1
fi

for file in \
    "$MODULE_DIR/install/version.php" \
    "$UPDATE_DIR/updater.php" \
    "$UPDATE_DIR/description.ru" \
    "$MODULE_DIR/lib/Storage/Migrations/MigrationRunner.php" \
    "$MODULE_DIR/lib/Storage/Migrations/InitialSchemaMigration.php"; do
    if [[ ! -f "$file" ]]; then
        echo "Required package source is missing: $file" >&2
        exit 1
    fi
done

if ! grep -Eq "'VERSION'[[:space:]]*=>[[:space:]]*'$VERSION'" "$MODULE_DIR/install/version.php"; then
    echo "Module version metadata does not match $VERSION" >&2
    exit 1
fi

mkdir -p "$PACKAGE_DIR" "$DIST_DIR"
cp -a "$MODULE_DIR/." "$PACKAGE_DIR/"
cp "$UPDATE_DIR/updater.php" "$PACKAGE_DIR/updater.php"
cp "$UPDATE_DIR/description.ru" "$PACKAGE_DIR/description.ru"

# Normalize timestamps so repeated builds from the same commit are reproducible.
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-1704067200}"
find "$PACKAGE_DIR" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +

rm -f "$ARCHIVE"
(
    cd "$PACKAGE_DIR"
    zip -X -q -r "$ARCHIVE" .
)

unzip -tq "$ARCHIVE"
unzip -Z1 "$ARCHIVE" | grep -Fxq "updater.php"
unzip -Z1 "$ARCHIVE" | grep -Fxq "description.ru"
unzip -Z1 "$ARCHIVE" | grep -Fxq "install/version.php"
unzip -Z1 "$ARCHIVE" | grep -Fxq "lib/Storage/Migrations/MigrationRunner.php"
unzip -Z1 "$ARCHIVE" | grep -Fxq "lib/Storage/Migrations/InitialSchemaMigration.php"

echo "Built and validated: $ARCHIVE"
