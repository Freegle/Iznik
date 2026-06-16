#!/bin/bash
set -e

SWAGGER=/home/edward/FreegleDockerWSL/iznik-server-go/swagger/bin/swagger

if [ ! -f "$SWAGGER" ]; then
    SWAGGER=$(go env GOPATH)/bin/swagger
fi

if [ ! -f "$SWAGGER" ]; then
    echo "Swagger binary not found. Installing via go install..."
    go install github.com/go-swagger/go-swagger/cmd/swagger@latest
    SWAGGER=$(go env GOPATH)/bin/swagger
fi

cd "$(dirname "$0")"
mkdir -p swagger

echo "Generating Swagger documentation..."
$SWAGGER generate spec -o ./swagger/swagger.json --scan-models -m

echo "Validating..."
$SWAGGER validate ./swagger/swagger.json

echo "Done. swagger.json generated and validated."
echo "Swagger UI will be available at /swagger/ when the service is running."
