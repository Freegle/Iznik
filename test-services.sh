#!/bin/bash

echo "Testing Freegle Docker Services..."
echo "=================================="

# Test each service
services=(
    "mailpit.localhost:Mailpit"
    "tusd.localhost:TusD"
    "status.localhost:Status"
    "phpmyadmin.localhost:PhpMyAdmin"
    "freegle.localhost:Freegle"
    "modtools.localhost:ModTools"
    "apiv1.localhost:API_v1"
)

for service in "${services[@]}"; do
    IFS=':' read -r host name <<< "$service"
    echo -n "$name: "
    
    if curl -H "Host: $host" -s -o /dev/null -w "%{http_code}" http://localhost:82 | grep -q "200\|301\|302"; then
        echo "✓ Online"
    elif curl -H "Host: $host" -s -o /dev/null -w "%{http_code}" http://localhost:82 | grep -q "502"; then
        echo "⚠ Starting..."
    else
        echo "✗ Offline"
    fi
done

echo ""
echo "API v2 (separate port):"
echo -n "API_v2: "
if curl -H "Host: apiv2.localhost" -s -o /dev/null -w "%{http_code}" http://localhost:8192 | grep -q "200\|301\|302"; then
    echo "✓ Online"
elif curl -H "Host: apiv2.localhost" -s -o /dev/null -w "%{http_code}" http://localhost:8192 | grep -q "502"; then
    echo "⚠ Starting..."
else
    echo "✗ Offline"
fi

echo ""
echo "Direct access URLs:"
echo "Status Monitor: http://localhost:8081"
echo "Traefik Dashboard: http://localhost:8080"