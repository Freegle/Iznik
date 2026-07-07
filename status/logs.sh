#!/bin/bash
echo "Content-Type: text/plain"
echo "Access-Control-Allow-Origin: *"
echo ""

container="${QUERY_STRING#container=}"
container=$(echo "$container" | sed 's/[^a-zA-Z0-9._-]//g')

if [ -z "$container" ]; then
    echo "Container name required"
    exit 1
fi

docker logs --tail=20 "$container" 2>&1