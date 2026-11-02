#!/usr/bin/env bash
# Destroy VMs by id or name. Safety: only names starting tb-builder- or circleci-runner- are touched.
# Usage: destroy-vm.sh <id-or-name>... | --all-builders
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"; . "$here/lib.sh"
kp_require_tools; kp_token
vms="$(kp_list_vms)"
targets=()
if [ "${1:-}" = "--all-builders" ]; then mapfile -t targets < <(echo "$vms" | awk -F'\t' '$2 ~ /^tb-builder-/ {print $1}')
else for a in "$@"; do targets+=("$(echo "$vms" | awk -F'\t' -v a="$a" '$1==a || $2==a {print $1}' | head -1)"); done; fi
for id in "${targets[@]}"; do
  [ -n "$id" ] || continue
  n="$(echo "$vms" | awk -F'\t' -v i="$id" '$1==i {print $2}')"
  case "$n" in tb-builder-*|circleci-runner-*) kp_delete_vm "$id";; *) kp_log "refusing to delete $n ($id): not a builder or runner";; esac
done
