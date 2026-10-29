
#!/usr/bin/env bash
set -euo pipefail

FILE="advanced-divi-forms.php"

if [[ ! -f "$FILE" ]]; then
  echo "::error::Plugin file missing"
  exit 1
fi

# Read WordPress plugin header version.
HEADER_VERSION=$(
  sed -nE \
    's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' \
    "$FILE" | head -1
)

# Read PHP constant.
CONSTANT_VERSION=$(
  sed -nE \
    "s/^[[:space:]]*define[[:space:]]*\([[:space:]]*'ADF_PLUGIN_VERSION'[[:space:]]*,[[:space:]]*'([0-9]+\.[0-9]+\.[0-9]+)'[[:space:]]*\)[[:space:]]*;.*$/\1/p" \
    "$FILE" | head -1
)

echo "Header version:   $HEADER_VERSION"
echo "Constant version: $CONSTANT_VERSION"

if [[ -z "$HEADER_VERSION" || -z "$CONSTANT_VERSION" ]]; then
  echo "::error::Missing or invalid plugin version"
  exit 1
fi

if [[ "$HEADER_VERSION" != "$CONSTANT_VERSION" ]]; then
  echo "::error::Header and constant versions differ"
  exit 1
fi

if [[ "${1:-}" == "pr" ]]; then
  git fetch origin main

  OLD_VERSION=$(
    git show origin/main:"$FILE" |
      sed -nE \
        's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' |
      head -1
  )

  if [[ -z "$OLD_VERSION" ]]; then
    echo "::error::Could not read main version"
    exit 1
  fi

  echo "Main version: $OLD_VERSION"

  if [[ "$HEADER_VERSION" == "$OLD_VERSION" ]] ||
     [[ "$(printf '%s\n' "$OLD_VERSION" "$HEADER_VERSION" |
       sort -V | tail -1)" != "$HEADER_VERSION" ]]; then
    echo "::error::Plugin version must increase"
    exit 1
  fi
fi

if [[ "${1:-}" == "release" ]]; then
  TAG_VERSION="${GITHUB_REF_NAME#v}"

  if [[ "$TAG_VERSION" != "$HEADER_VERSION" ]]; then
    echo "::error::Release tag and plugin version differ"
    exit 1
  fi
fi

echo "Version checks passed"

