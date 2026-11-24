#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-0.1.1}"
MODULE_ID="codematrix.integrationinspector"
MODULE_DIR="$ROOT/local/modules/$MODULE_ID"
UPDATE_DIR="$ROOT/updates/$VERSION"
DIST_DIR="$ROOT/dist"
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT
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
    "$MODULE_DIR/lib/Storage/SchemaInstaller.php" \
    "$MODULE_DIR/lib/Storage/Migrations/SchemaMigration.php" \
    "$MODULE_DIR/lib/Storage/Migrations/SchemaVersionStore.php" \
    "$MODULE_DIR/lib/Storage/Migrations/MigrationLock.php" \
    "$MODULE_DIR/lib/Storage/Migrations/InitialSchemaVerifier.php" \
    "$MODULE_DIR/lib/Storage/Migrations/MySqlSchemaVersionStore.php" \
    "$MODULE_DIR/lib/Storage/Migrations/MySqlMigrationLock.php" \
    "$MODULE_DIR/lib/Storage/Migrations/MySqlSchemaInspector.php" \
    "$MODULE_DIR/lib/Storage/Migrations/InitialSchemaMigration.php" \
    "$MODULE_DIR/lib/Storage/Migrations/MigrationRunner.php"; do
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

# Normalize timestamps and archive entry order for repeatable builds.
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-1704067200}"
find "$PACKAGE_DIR" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +

rm -f "$ARCHIVE"
(
    cd "$PACKAGE_DIR"
    find . -mindepth 1 -print | sed 's#^\./##' | LC_ALL=C sort | zip -X -q "$ARCHIVE" -@
)

unzip -tq "$ARCHIVE"
unzip -Z1 "$ARCHIVE" | grep -Fxq "updater.php"
unzip -Z1 "$ARCHIVE" | grep -Fxq "description.ru"
unzip -Z1 "$ARCHIVE" | grep -Fxq "install/version.php"
for relative_file in \
    "lib/Storage/SchemaInstaller.php" \
    "lib/Storage/Migrations/SchemaMigration.php" \
    "lib/Storage/Migrations/SchemaVersionStore.php" \
    "lib/Storage/Migrations/MigrationLock.php" \
    "lib/Storage/Migrations/InitialSchemaVerifier.php" \
    "lib/Storage/Migrations/MySqlSchemaVersionStore.php" \
    "lib/Storage/Migrations/MySqlMigrationLock.php" \
    "lib/Storage/Migrations/MySqlSchemaInspector.php" \
    "lib/Storage/Migrations/InitialSchemaMigration.php" \
    "lib/Storage/Migrations/MigrationRunner.php"; do
    unzip -Z1 "$ARCHIVE" | grep -Fxq "$relative_file"
done

echo "Built and validated: $ARCHIVE"
