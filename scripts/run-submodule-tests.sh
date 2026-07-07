#!/bin/bash
# Run tests for a specific component using the FreegleDocker environment
# Usage: ./scripts/run-submodule-tests.sh <php|go|playwright> <path-to-pr-code>
#
# This script mirrors the build-and-test flow in .circleci/config.yml
# The only differences are:
#   a) We replace the component directory with PR code
#   b) We only run the relevant test type

set -e

TEST_TYPE=$1
PR_CODE_PATH=$2

if [ -z "$TEST_TYPE" ] || [ -z "$PR_CODE_PATH" ]; then
    echo "Usage: $0 <php|go|playwright> <path-to-pr-code>"
    exit 1
fi

echo "=== Running $TEST_TYPE tests ==="
echo "PR code path: $PR_CODE_PATH"

# Determine which directory to replace
case $TEST_TYPE in
    php)
        COMPONENT_DIR="iznik-server"
        ;;
    go)
        COMPONENT_DIR="iznik-server-go"
        ;;
    playwright)
        COMPONENT_DIR="iznik-nuxt3"
        ;;
    *)
        echo "Unknown test type: $TEST_TYPE (use 'php', 'go', or 'playwright')"
        exit 1
        ;;
esac

# Replace component with PR code
echo "Replacing $COMPONENT_DIR with PR code..."
rm -rf "$COMPONENT_DIR"
cp -r "$PR_CODE_PATH" "$COMPONENT_DIR"

# === start-services (from .circleci/config.yml) ===
# Create secrets files
echo "Creating secrets files..."
mkdir -p secrets
echo "placeholder" > secrets/lovejunk-api.txt
echo "placeholder" > secrets/lovejunk-secret.txt
echo "placeholder" > secrets/partner-key.txt
echo "placeholder" > secrets/partner-name.txt
echo "placeholder" > secrets/image-domain.txt

# Fix memory overcommit for Redis
sudo sysctl vm.overcommit_memory=1 || true

# Set environment variables (same as FreegleDocker CI)
export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1

# Start services in detached mode (use base file only, not override)
echo "Starting Docker services..."
docker-compose -f docker-compose.yml up -d

echo "Waiting for basic services to start..."
sleep 30

echo "Service status:"
docker-compose -f docker-compose.yml ps

# === wait-for-basic-services (from .circleci/config.yml) ===
echo "Waiting for API v1 and v2 services to be healthy..."

start_time=$(date +%s)
timeout_duration=600  # 10 minutes for basic services

while true; do
    current_time=$(date +%s)
    elapsed=$((current_time - start_time))

    if [ $elapsed -gt $timeout_duration ]; then
        echo "❌ Timeout waiting for API services after 10 minutes"
        docker-compose -f docker-compose.yml logs
        exit 1
    fi

    # Check if status service is responding
    if curl -f -s http://localhost:8081 > /dev/null 2>&1; then
        echo "✅ Status service is responding!"

        # Get health status from status service
        health_response=$(curl -s http://localhost:8081/api/status/all 2>/dev/null || echo '{}')

        # Check if API v1 and v2 are healthy
        apiv1_status=$(echo "$health_response" | jq -r '.apiv1.status // "unknown"')
        apiv2_status=$(echo "$health_response" | jq -r '.apiv2.status // "unknown"')

        if [ "$apiv1_status" = "success" ] && [ "$apiv2_status" = "success" ]; then
            echo "✅ API v1 and v2 services are healthy!"
            break
        else
            elapsed_min=$((elapsed / 60))
            echo "[${elapsed_min}m] API v1: $apiv1_status, API v2: $apiv2_status - waiting..."
        fi
    else
        echo "Status service not yet responding..."
    fi

    sleep 10
done

echo "🎉 API services are ready!"

# === Run tests (same as .circleci/config.yml) ===
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
chmod +x "$SCRIPT_DIR/run-tests.sh"

case $TEST_TYPE in
    go)
        echo "🧪 Running Go tests..."
        "$SCRIPT_DIR/run-tests.sh" go
        ;;
    php)
        echo "🐘 Running PHPUnit tests..."
        "$SCRIPT_DIR/run-tests.sh" php
        ;;
    playwright)
        # === wait-for-prod-container (from .circleci/config.yml) ===
        echo "⏳ Waiting for production containers to be healthy..."
        start_time=$(date +%s)
        timeout_duration=1200  # 20 minutes

        while true; do
            current_time=$(date +%s)
            elapsed=$((current_time - start_time))

            if [ $elapsed -gt $timeout_duration ]; then
                echo "❌ Timeout waiting for production containers after 20 minutes"
                exit 1
            fi

            # Check both prod containers health
            health_response=$(curl -s http://localhost:8081/api/status/all 2>/dev/null || echo '{}')
            freegle_prod_status=$(echo "$health_response" | jq -r '.["freegle-prod"].status // "unknown"')
            modtools_prod_status=$(echo "$health_response" | jq -r '.["modtools-prod"].status // "unknown"')

            if [ "$freegle_prod_status" = "success" ] && [ "$modtools_prod_status" = "success" ]; then
                echo "✅ Both production containers are healthy!"
                break
            else
                elapsed_min=$((elapsed / 60))
                echo "[${elapsed_min}m] Freegle Prod: $freegle_prod_status | ModTools Prod: $modtools_prod_status - waiting..."
                sleep 15
            fi
        done

        echo "🎭 Running Playwright tests..."
        "$SCRIPT_DIR/run-tests.sh" playwright
        ;;
esac

echo "=== Tests completed successfully ==="
