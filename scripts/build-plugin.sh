#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="dist/staging/advanced-divi-forms"

# Build in an empty directory, so removed files cannot leak into later ZIPs.
if [[ -e dist/staging || -e dist/advanced-divi-forms.zip ]]; then
  echo "::error::Build output already exists; use a clean workspace"
  exit 1
fi

mkdir -p "$PLUGIN_DIR"
cp advanced-divi-forms.php "$PLUGIN_DIR/"

for dir in includes templates assets; do
  if [[ -d "$dir" ]]; then
    cp -r "$dir" "$PLUGIN_DIR/"
  fi
done

(
  cd dist/staging
  zip -qr ../advanced-divi-forms.zip advanced-divi-forms
)

unzip -t dist/advanced-divi-forms.zip
