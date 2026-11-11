#!/bin/bash
# Tests for `freegle worktree setup` and `freegle worktree teardown`, which configure
# and dismantle a worktree some other tool created (Orca runs them as repo hooks).
# Builds a throwaway repo with stub docker commands, so nothing real is started.
# Run: bash test-freegle-worktree-setup.sh
set -uo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/freegle"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fails=0
check() {
    local desc="$1" expected="$2" actual="$3"
    if [[ "$expected" == "$actual" ]]; then
        echo "PASS: $desc"
    else
        echo "FAIL: $desc"
        echo "      expected: [$expected]"
        echo "      actual:   [$actual]"
        fails=$(( fails + 1 ))
    fi
}

# Stub docker and docker-compose: record each call, start nothing.
mkdir -p "$TMP/bin"
for cmd in docker docker-compose; do
    printf '#!/bin/sh\necho "%s $*" >> "%s/calls"\n' "$cmd" "$TMP" > "$TMP/bin/$cmd"
    chmod +x "$TMP/bin/$cmd"
done
export PATH="$TMP/bin:$PATH"

main="$TMP/main"
mkdir -p "$main"
cp "$SRC" "$main/freegle"
cat > "$main/docker-compose.yml" <<'EOF'
services:
  a:
    ports: ["${PORT_ALPHA:-8080}:80", "${PORT_BETA:-8081}:81", "${PORT_TRAEFIK_HTTP:-80}:80", "${PORT_STATUS:-8081}:8081"]
EOF
printf 'COMPOSE_PROJECT_NAME=freegle\nCOMPOSE_FILE=docker-compose.yml:docker-compose.ports.yml\n' > "$main/.env"
git -C "$main" init -q -b master
git -C "$main" config core.autocrlf false
git -C "$main" -c user.email=t@t -c user.name=t add freegle docker-compose.yml
git -C "$main" -c user.email=t@t -c user.name=t commit -q -m init
# The worktree another tool made: a mixed-case name, no .env of its own.
wt="$TMP/workspaces/Brave_Otter"
git -C "$main" worktree add -q "$wt" -b brave-otter

# 1. setup configures the worktree in place
out=$(cd "$wt" && "$main/freegle" worktree setup 2>&1)
check "setup exits 0" "0" "$?"
check "project named from the directory" "COMPOSE_PROJECT_NAME=freegle-brave-otter" \
    "$(grep '^COMPOSE_PROJECT_NAME=' "$wt/.env")"
check "ports overlay dropped" "COMPOSE_FILE=docker-compose.yml" "$(grep '^COMPOSE_FILE=' "$wt/.env")"
alpha=$(grep '^PORT_ALPHA=' "$wt/.env" | cut -d= -f2)
beta=$(grep '^PORT_BETA=' "$wt/.env" | cut -d= -f2)
check "ports moved off the defaults" "yes" "$([[ $alpha -ge 12000 && $beta -ge 12000 && $alpha != "$beta" ]] && echo yes || echo no)"
check "Loki volume is the worktree's own" "LOKI_VOLUME=freegle-brave-otter-loki-data" \
    "$(grep '^LOKI_VOLUME=' "$wt/.env")"
check "containers started" "1" "$(grep -c '^docker-compose up -d' "$TMP/calls")"
check "main checkout's .env untouched" "COMPOSE_PROJECT_NAME=freegle" "$(grep '^COMPOSE_PROJECT_NAME=' "$main/.env")"

# 2. setup is idempotent: a configured worktree keeps its ports
out=$(cd "$wt" && "$main/freegle" worktree setup 2>&1)
check "second setup exits 0" "0" "$?"
check "second setup says so" "Worktree already configured as compose project freegle-brave-otter." "$out"
check "ports kept" "$alpha" "$(grep '^PORT_ALPHA=' "$wt/.env" | cut -d= -f2)"

# 3. setup refuses the main checkout
out=$(cd "$main" && "$main/freegle" worktree setup 2>&1)
check "setup on main checkout fails" "1" "$?"
check "main checkout's .env still untouched" "COMPOSE_PROJECT_NAME=freegle" "$(grep '^COMPOSE_PROJECT_NAME=' "$main/.env")"

# 4. teardown stops the stack with its volumes and hands the files back
: > "$TMP/calls"
out=$("$main/freegle" worktree teardown "$wt" 2>&1)
check "teardown exits 0" "0" "$?"
check "stack stopped with volumes" "1" "$(grep -c '^docker-compose down -v' "$TMP/calls")"
check "root-owned files handed back" "1" "$(grep -c "^docker run --rm -v $wt:/w:rw alpine chown -R" "$TMP/calls")"

# 5. teardown refuses the main checkout
: > "$TMP/calls"
out=$("$main/freegle" worktree teardown "$main" 2>&1)
check "teardown on main checkout fails" "1" "$?"
check "nothing stopped for main checkout" "0" "$(wc -l < "$TMP/calls" | tr -d ' ')"

# 6. slug
FREEGLE_LIB_ONLY=1 source "$main/freegle"
set +e
check "slug lowercases and dashes" "brave-otter-2" "$(worktree_slug 'Brave_Otter 2')"
check "slug of nothing usable" "worktree" "$(worktree_slug '___')"

echo ""
if (( fails > 0 )); then
    echo "$fails test(s) failed"
    exit 1
fi
echo "All tests passed"
