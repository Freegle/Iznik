#!/bin/bash
# Generate MySQL client configs from .env file

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASEDIR="$(dirname "$SCRIPT_DIR")"

# Source .env file if it exists
if [ -f "$BASEDIR/.env" ]; then
    export $(grep -v '^#' "$BASEDIR/.env" | xargs)
fi

# Use default if not set
MYSQL_PASSWORD="${MYSQL_PRODUCTION_ROOT_PASSWORD:-iznik}"

# Generate iznik-server-my.cnf
cat > "$BASEDIR/iznik-server-my.cnf" << MYCNF
[client]
host=percona
port=3306
user=root
password=$MYSQL_PASSWORD
MYCNF

echo "Generated $BASEDIR/iznik-server-my.cnf with password from .env"
