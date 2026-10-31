#!/usr/bin/env bash
# List the org's VMs (id, name, package, state, created), or --packages for the sizes on offer.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"; . "$here/lib.sh"
kp_require_tools; kp_token
if [ "${1:-}" = "--packages" ]; then kp_list_packages; else kp_check_token; kp_list_vms; fi
