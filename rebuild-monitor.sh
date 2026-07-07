#!/bin/bash

# Monitor for rebuild requests from status container
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "Starting rebuild monitor..."

while true; do
    # Look for rebuild request files
    for request_file in rebuild-*.json; do
        # Skip if no files match the pattern
        [[ ! -f "$request_file" ]] && continue
        
        echo "Processing rebuild request: $request_file"
        
        # Read the request
        service=$(jq -r '.service' "$request_file" 2>/dev/null)
        container=$(jq -r '.container' "$request_file" 2>/dev/null)
        
        if [[ "$service" == "null" ]] || [[ "$container" == "null" ]]; then
            echo "Invalid request file, skipping: $request_file"
            rm -f "$request_file"
            continue
        fi
        
        # Container names have project prefix (freegle-xxx) but compose
        # service names don't (xxx). Strip prefix if present.
        svc=$(echo "$service" | sed 's/^freegle-//')
        echo "Rebuilding service: $svc ($container)"

        # Execute the rebuild.
        # stop/rm may fail if container is already stopped — that's OK.
        # Only build→up needs to succeed.
        docker-compose stop "$svc" 2>/dev/null || true
        docker-compose rm -f "$svc" 2>/dev/null || true
        if docker-compose build "$svc" && \
           docker-compose up --no-deps -d "$svc"; then
            echo "Rebuild completed successfully for $service"
        else
            echo "Rebuild failed for $service"
        fi
        
        # Remove the request file to signal completion
        rm -f "$request_file"
    done
    
    # Wait before checking again
    sleep 2
done