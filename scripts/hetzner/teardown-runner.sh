#!/usr/bin/env bash
set -euo pipefail
HETZNER_TOKEN="${HETZNER_TOKEN:?set HETZNER_TOKEN}"
SID="${1:?usage: teardown-runner.sh <server_id>}"
curl -s -X DELETE "https://api.hetzner.cloud/v1/servers/$SID" -H "Authorization: Bearer $HETZNER_TOKEN" -w "\nHTTP %{http_code}\n"
echo "deleted server $SID"
