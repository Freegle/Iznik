#!/bin/bash

# Make sure the script is executable (on Linux/macOS)
# chmod +x generate-swagger.sh

# Check for locally downloaded swagger binary first
LOCAL_SWAGGER_PATH="./swagger/bin/swagger"
LOCAL_SWAGGER_PATH_WIN="./swagger/bin/swagger.exe"

SWAGGER_CMD=""

# Check for globally installed swagger first (preferred method)
if command -v swagger &> /dev/null; then
    SWAGGER_CMD="swagger"
    echo "Using globally installed swagger"
elif command -v swagger.exe &> /dev/null; then
    SWAGGER_CMD="swagger.exe" 
    echo "Using globally installed swagger.exe"
# Fall back to local binaries if available
elif [[ -x "$LOCAL_SWAGGER_PATH" ]]; then
    SWAGGER_CMD="$LOCAL_SWAGGER_PATH"
    echo "Using local swagger binary: $SWAGGER_CMD"
elif [[ -x "$LOCAL_SWAGGER_PATH_WIN" ]]; then
    SWAGGER_CMD="$LOCAL_SWAGGER_PATH_WIN"
    echo "Using local swagger binary: $SWAGGER_CMD"
else
    # No swagger found, install it via go install
    echo "Swagger command not found. Installing via go install..."
    
    if command -v go &> /dev/null; then
        go install github.com/go-swagger/go-swagger/cmd/swagger@latest
        
        # Check if installation was successful
        if command -v swagger &> /dev/null; then
            SWAGGER_CMD="swagger"
            echo "Successfully installed go-swagger"
        else
            echo "go install completed but swagger not found in PATH"
            echo "Make sure $(go env GOPATH)/bin is in your PATH"
            exit 1
        fi
    else
        echo "Error: Go not found. Please install Go first or manually install go-swagger"
        exit 1
    fi
fi

echo "Generating Swagger documentation..."

# Make sure the swagger directory exists (works on both Windows and Unix)
mkdir -p swagger 2>/dev/null || mkdir swagger 2>/dev/null

# Where the finished spec lands. Overridable so callers (notably the test in
# test/swagger_test.go) can generate somewhere disposable instead of over the
# committed spec.
SWAGGER_OUT="${SWAGGER_OUT:-./swagger/swagger.json}"

# Generate the swagger spec
echo "Generating spec with all files..."
# Generate the spec with appropriate parameters based on the swagger version

# Generate to a TEMPORARY file, never straight over the destination. Writing in
# place means a bad run has already destroyed the previous spec by the time you
# can inspect it, and the only guard used to be "are paths empty", which does not
# fire when the run drops models rather than routes. Measured 2026-08-09: a run
# on an unchanged tree emitted 4 definitions where the committed spec had 115,
# which the old flow would have written out silently.
TMP_SPEC="$(mktemp -t swagger-spec-XXXXXX.json)"
trap 'rm -f "$TMP_SPEC"' EXIT

# Exclude test files, which might cause issues
$SWAGGER_CMD generate spec \
  -o "$TMP_SPEC" \
  --scan-models \
  --include=".*" \
  --exclude=".*/vendor/.*" \
  --exclude=".*/test/.*" \
  -m

if [ $? -ne 0 ]; then
    echo "❌ Failed to generate Swagger spec - please check your swagger command"
    echo "   The existing spec at $SWAGGER_OUT has NOT been touched."
    exit 1
fi

if grep -q "\"paths\": {}" "$TMP_SPEC"; then
    echo "❌ ERROR: Generated spec doesn't contain any API paths"
    echo "Make sure your route annotations are correct (see README.md for guidance)"
    echo "   The existing spec at $SWAGGER_OUT has NOT been touched."
    exit 1
fi

# Refuse a run that loses a large share of what the current spec documents.
# Node rather than jq/python: it is already a dependency across this monorepo,
# and a missing interpreter must not block generation, so an absent node only
# warns.
if [ -f "$SWAGGER_OUT" ] && command -v node >/dev/null 2>&1; then
    node -e '
      const fs = require("fs");
      const [oldPath, newPath] = process.argv.slice(1);
      const count = (p, k) => Object.keys(JSON.parse(fs.readFileSync(p, "utf8"))[k] || {}).length;
      let worst = null;
      for (const key of ["paths", "definitions", "responses"]) {
        const before = count(oldPath, key), after = count(newPath, key);
        // Only a big loss is suspicious; removing one retired route is normal.
        if (before > 0 && after < before * 0.75) {
          worst = `${key}: ${before} -> ${after}`;
          console.error(`   ${key}: ${before} -> ${after}  (lost ${before - after})`);
        }
      }
      process.exit(worst ? 1 : 0);
    ' "$SWAGGER_OUT" "$TMP_SPEC"

    if [ $? -ne 0 ]; then
        echo "❌ ERROR: the generated spec drops more than a quarter of what the current one documents."
        echo "   Refusing to overwrite $SWAGGER_OUT. Inspect the candidate at:"
        echo "     $TMP_SPEC"
        echo "   (copy it elsewhere before this script exits, or it will be cleaned up)"
        echo "   If the loss is genuinely intended, install it by hand."
        trap - EXIT
        exit 1
    fi
fi

mkdir -p "$(dirname "$SWAGGER_OUT")" 2>/dev/null
cp "$TMP_SPEC" "$SWAGGER_OUT"
echo "✅ Swagger spec generated successfully at $SWAGGER_OUT"

# Validate the swagger spec
echo "Validating the generated spec..."
$SWAGGER_CMD validate "$SWAGGER_OUT"

if [ $? -eq 0 ]; then
    echo "✅ Swagger spec validation passed"
else
    echo "⚠️ Swagger spec validation has warnings"
fi

echo "The Swagger UI is available at http://localhost:8192/swagger/ when the server is running."