#!/bin/bash
# Set up test databases via Laravel migrations (single source of truth)
#
# Laravel migrations in iznik-batch are the authoritative schema definition.
# Test databases are created by running migrations, then cloned via mysqldump.

# Support both CircleCI (~/project) and local (~/FreegleDocker) paths
if [ -d "$HOME/FreegleDocker" ]; then
    cd "$HOME/FreegleDocker"
fi
echo "Setting up test database and environment..."

# Use dynamic container name prefix (freegle-ci on CI, freegle locally)
PREFIX="${COMPOSE_PROJECT_NAME:-freegle}"

# Verify required containers are still running
echo "Verifying required containers..."
for service in apiv1 percona batch; do
    container="${PREFIX}-${service}"
    if ! docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null | grep -q "true"; then
    echo "Container $container is not running!"
    echo ""
    echo "=== Container status ==="
    docker ps -a --filter "name=$container" --format "table {{.Names}}\t{{.Status}}\t{{.State}}"
    echo ""
    echo "=== Container logs (last 50 lines) ==="
    docker logs "$container" --tail 50 2>&1 || echo "Could not get logs"
    echo ""
    echo "=== All container statuses ==="
    docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.State}}" | head -30
    exit 1
    fi
    echo "$container is running"
done

# 1. Create database and run Laravel migrations (single source of truth)
# On self-hosted runner, drop and recreate the database to clear stale test data
# from previous CI runs. Cloud CI always starts fresh.
if [ "${SELF_HOSTED_RUNNER:-}" = "true" ]; then
  echo "Self-hosted runner: dropping iznik database to ensure clean state..."
  docker exec "${PREFIX}-apiv1" sh -c "mysql -h percona -u root -piznik -e 'DROP DATABASE IF EXISTS iznik;'"
fi
echo "Creating iznik database and running Laravel migrations..."
docker exec "${PREFIX}-apiv1" sh -c "mysql -h percona -u root -piznik -e 'CREATE DATABASE IF NOT EXISTS iznik;'"
docker exec "${PREFIX}-batch" php artisan migrate --force --no-interaction 2>&1
echo "Laravel migrations complete"

# 2. Set SQL mode (disable ONLY_FULL_GROUP_BY)
echo "Setting SQL mode..."
docker exec "${PREFIX}-apiv1" sh -c "mysql -h percona -u root -piznik \
  -e \"SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\" && \
  mysql -h percona -u root -piznik \
  -e \"SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));\""

# 3. Run testenv.php for fixture data (still needs apiv1 PHP classes)
# Clear any stale LoggedPDO "host down" markers first. On self-hosted runner
# apiv1's /tmp persists across CI runs; a stale /tmp/iznik.dbstatus.*.down
# causes doConnect() to short-circuit every retry for 60s, so testenv.php
# fatals with "Sleep for all DB hosts down" and fixture data is never
# created — cascading into AUTO_INCREMENT-dependent PHPUnit failures.
echo "Setting up test environment (FreeglePlayground group, test users, etc.)..."
docker exec "${PREFIX}-apiv1" sh -c "rm -f /tmp/iznik.dbstatus.*.down"
docker exec "${PREFIX}-apiv1" sh -c "cd /var/www/iznik && php install/testenv.php" || {
  echo "testenv.php failed — aborting test database setup"
  exit 1
}

# 4. Create iznik_go_test by cloning schema from migrated iznik DB
echo "Setting up iznik_go_test database for Go tests..."
docker exec "${PREFIX}-apiv1" sh -c "\
    mysql -h percona -u root -piznik -e 'DROP DATABASE IF EXISTS iznik_go_test; CREATE DATABASE iznik_go_test;' && \
    mysqldump -h percona -u root -piznik --no-data --routines --triggers iznik | \
      mysql -h percona -u root -piznik iznik_go_test"
echo "iznik_go_test ready (cloned from migrated iznik)"

# 5. Create iznik_phpunit_test by cloning schema from migrated iznik DB
echo "Setting up iznik_phpunit_test database for PHPUnit tests..."
docker exec "${PREFIX}-apiv1" sh -c "\
    mysql -h percona -u root -piznik -e 'DROP DATABASE IF EXISTS iznik_phpunit_test; CREATE DATABASE iznik_phpunit_test;' && \
    mysqldump -h percona -u root -piznik --no-data --routines --triggers iznik | \
      mysql -h percona -u root -piznik iznik_phpunit_test"
echo "iznik_phpunit_test ready (cloned from migrated iznik)"

echo "Test database and environment ready!"
