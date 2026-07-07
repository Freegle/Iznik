#!/bin/bash
# Retry wrapper script for flaky network operations
# Usage: retry.sh [options] <command...>
# Options:
#   -n <attempts>  Number of attempts (default: 5)
#   -d <seconds>   Delay between attempts (default: 10)
# Examples:
#   retry.sh apt-get update
#   retry.sh -n 3 curl -fsSL https://example.com
#   retry.sh -n 10 -d 30 apt-get install -y nginx

MAX_ATTEMPTS=5
DELAY=10

while getopts "n:d:" opt; do
    case $opt in
        n) MAX_ATTEMPTS=$OPTARG ;;
        d) DELAY=$OPTARG ;;
    esac
done
shift $((OPTIND-1))

for i in $(seq 1 $MAX_ATTEMPTS); do
    echo "Attempt $i/$MAX_ATTEMPTS: $@"
    if "$@"; then
        exit 0
    fi
    if [ $i -lt $MAX_ATTEMPTS ]; then
        echo "Attempt $i failed, waiting ${DELAY}s before retry..."
        sleep $DELAY
    fi
done

echo "All $MAX_ATTEMPTS attempts failed"
exit 1
