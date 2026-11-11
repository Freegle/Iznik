#!/usr/bin/env bash
# Manage the broker's burn pool from this machine, through ssh to the docker host and the
# broker's own API inside its container (the admin password never leaves the host).
#
#   broker-pool.sh list                 every credential: id, name, plan, pools
#   broker-pool.sh add <id> [pool]      put a credential in the pool (default burn); it leaves the shared pool
#   broker-pool.sh remove <id> [pool]   take it out again
#   broker-pool.sh check                ask the gateway for the pool from the host (404 = pool empty, 401 = up)
set -euo pipefail
if [ -z "${BROKER_SSH_HOST_ADMIN:-}" ] && [ -f ./.env ]; then
  BROKER_SSH_HOST_ADMIN="$(grep -E '^BROKER_SSH_HOST_ADMIN=' ./.env | cut -d= -f2- | tr -d "\"'" || true)"
fi
HOST="${BROKER_SSH_HOST_ADMIN:?set BROKER_SSH_HOST_ADMIN (user@docker-host) in the environment or .env}"
cmd="${1:?list|add|remove|check}"; id="${2:-}"; pool="${3:-burn}"
node_in_container() { ssh -o BatchMode=yes -o ConnectTimeout=10 "$HOST" "docker exec claude-broker node -e '$1'"; }
case "$cmd" in
  list) node_in_container 'fetch("http://127.0.0.1:3399/api/export",{headers:{"x-broker-password":process.env.BROKER_PASSWORD}}).then(r=>r.json()).then(j=>j.credentials.forEach(c=>console.log(c.id,"|",c.name,"|",c.plan||"",c.enabled===false?"| disabled":"","| pools:",(c.pools||[]).join(",")||"shared")))' ;;
  add|remove)
    [ -n "$id" ] || { echo "credential id needed"; exit 2; }
    node_in_container "(async()=>{const h={\"x-broker-password\":process.env.BROKER_PASSWORD,\"content-type\":\"application/json\"};const j=await (await fetch(\"http://127.0.0.1:3399/api/export\",{headers:h})).json();const c=j.credentials.find(x=>x.id===\"$id\");if(!c){console.log(\"no such credential\");process.exit(1)}let p=new Set(c.pools||[]);\"$cmd\"===\"add\"?p.add(\"$pool\"):p.delete(\"$pool\");const r=await fetch(\"http://127.0.0.1:3399/api/tokens/$id\",{method:\"PUT\",headers:h,body:JSON.stringify({pools:[...p]})});console.log(r.status,c.name,\"pools:\",[...p].join(\",\")||\"shared\")})()" ;;
  check) ssh -o BatchMode=yes "$HOST" "curl -s -o /dev/null -w 'pool $pool via loopback: http=%{http_code}\n' http://127.0.0.1:3399/api/gateway/p/$pool/v1/models || echo 'broker not published on loopback yet (run broker-host-setup.sh apply)'" ;;
esac
