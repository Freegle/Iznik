#!/bin/bash
# Tests for check-role-literals.sh, covering all three modes: the editor hook
# (JSON on stdin), --diff (a throwaway git repo and a real commit) and --all.
# Usage: .claude/check-role-literals.test.sh
set -uo pipefail

HOOK="$(cd "$(dirname "$0")" && pwd)/check-role-literals.sh"
PASS=0
FAIL=0

ok() { PASS=$((PASS + 1)); }
bad() { FAIL=$((FAIL + 1)); echo "FAIL $1"; [ -n "${2:-}" ] && echo "     $(echo "$2" | head -1)"; }

# editor <name> <allow|block> <file path> <added text>
editor() {
  local name=$1 expect=$2 file=$3 text=$4 json out rc got=allow
  json=$(jq -nc --arg f "$file" --arg t "$text" '{tool_input: {file_path: $f, new_string: $t}}')
  out=$(printf '%s' "$json" | "$HOOK" 2>&1)
  rc=$?
  [ $rc -ne 0 ] && got=block
  if [ "$got" = "$expect" ]; then ok; else bad "[editor] $name: expected $expect, got $got (rc=$rc)" "$out"; fi
}

G=x/iznik-server-go/foo.go
GT=x/iznik-server-go/test/foo_test.go
P=x/iznik-batch/app/Services/S.php
PT=x/iznik-batch/tests/Unit/T.php
C=x/iznik-nuxt3/components/C.vue
J=x/iznik-nuxt3/stores/s.js

echo "== editor mode: must block =="
editor 'go sql systemrole'        block $G  'db.Raw("SELECT id FROM users WHERE systemrole = '"'"'Support'"'"'")'
editor 'go sql union'             block $G  '"WHERE u1.systemrole = '"'"'User'"'"' AND m.role IN ('"'"'Moderator'"'"','"'"'Owner'"'"')"'
editor 'go compare'               block $G  'if u.Systemrole == "Admin" {'
editor 'go assign'                block $G  'ljuser.Systemrole = "User"'
editor 'go map key'               block $G  'order := map[string]int{"User": 0, "Moderator": 1}'
editor 'go test user role'        block $GT 'id := CreateTestUser(t, prefix, "Moderator")'
editor 'go test membership role'  block $GT 'CreateTestMembership(t, u, g, "Owner")'
editor 'go test insert'           block $GT 'db.Exec("INSERT INTO memberships (userid, groupid, role) VALUES (?, ?, '"'"'Member'"'"')", a, b)'
editor 'go bound arg literal'     block $G  'db.Where("role = ?", "Owner")'
editor 'go assertion'             block $GT 'assert.Equal(t, "Moderator", getActualRole(g, u))'
editor 'php systemrole sql'       block $P  '$q->whereRaw("systemrole = '"'"'Support'"'"'");'
editor 'php where'                block $P  '->where('"'"'role'"'"', '"'"'Owner'"'"')'
editor 'php whereIn'              block $P  '->whereIn('"'"'memberships.role'"'"', ['"'"'Moderator'"'"', '"'"'Owner'"'"'])'
editor 'php array key'            block $P  "\$u = User::factory()->create(['systemrole' => 'Admin']);"
editor 'php test factory'         block $PT '$this->createMembership($mod, $group, ['"'"'role'"'"' => '"'"'Moderator'"'"']);'
editor 'php in_array'             block $P  "in_array(\$user->systemrole, ['Moderator', 'Support', 'Admin'], true)"
editor 'vue compare'              block $C  '<div v-if="role === '"'"'Owner'"'"'">'
editor 'vue systemrole'           block $C  "me.systemrole === 'Admin' ||"
editor 'vue option value'         block $C  '<option value="Moderator">Moderator</option>'
editor 'js includes'              block $J  "['Moderator', 'Owner'].includes(authStore.member(g.groupid))"
editor 'js request body'          block $J  "await api.post({ userid, groupid, role: 'Moderator' })"
editor 'js query string'          block $J  'const url = `/memberships?groupid=1&role=Owner`'
editor 'js role function'         block $J  "if (hasRole('Owner')) {"

echo "== editor mode: must allow =="
editor 'copy in vue template'     allow $C  '<h2>Moderator tools</h2><p>Ask a Moderator or the Owner of the group.</p>'
editor 'copy in vue label'        allow $C  '<b-button>Contact the Admin</b-button>'
editor 'copy in js text'          allow $J  "const heading = 'Moderator'"
editor 'mail line php'            allow $P  "\$line = 'A Moderator has approved your post.';"
editor 'mail blade'               allow x/iznik-batch/resources/views/m.blade.php 'Our Support team and Admin staff will help. Member since 2020.'
editor 'log type is another enum' allow $P  "->where('type', 'User')->where('subtype', 'Created')"
editor 'go constant definition'   allow $G  'ROLE_OWNER = "Owner"'
editor 'php constant definition'  allow $P  "public const ROLE_MEMBER = 'Member';"
editor 'js constant definition'   allow $J  "export const SYSTEMROLE_ADMIN = 'Admin'"
editor 'go constants used'        allow $G  'if u.Systemrole == utils.SYSTEMROLE_ADMIN {'
editor 'go test constants used'   allow $GT 'id := CreateTestUser(t, prefix, utils.SYSTEMROLE_MODERATOR)'
editor 'php constants used'       allow $P  "->whereIn('role', [Membership::ROLE_MODERATOR, Membership::ROLE_OWNER])"
editor 'vue constants used'       allow $C  '<div v-if="role === ROLE_OWNER">'
editor 'comment mentioning role'  allow $G  '// Group-level mods have systemrole='"'"'Moderator'"'"' synced.'
editor 'escape hatch'             allow $G  'role := "Support" // role-literal-ok: support case message role, not a membership role'
editor 'migration exempt'         allow x/iznik-batch/database/migrations/2025_m.php "DB::table('users')->where('systemrole', 'Admin')->update([]);"
editor 'routing go exempt'        allow x/iznik-routing-go/a.go 'if role == "Admin" {'
editor 'node_modules exempt'      allow x/iznik-nuxt3/node_modules/p/i.js "if (role === 'Owner') {}"
editor 'other tree exempt'        allow x/docs/a.js "if (role === 'Owner') {}"
editor 'markdown exempt'          allow x/iznik-nuxt3/README.md "role === 'Owner'"

echo "== diff mode =="
REPO=$(mktemp -d)
trap 'rm -rf "$REPO"' EXIT
(
  cd "$REPO" || exit 1
  git init -q .
  git config user.email t@t.t
  git config user.name t
  git config core.autocrlf false
  mkdir -p iznik-batch/app iznik-batch/database/migrations iznik-nuxt3/components iznik-server-go
  echo '<?php' > iznik-batch/app/Base.php
  # A literal already on master must not fail a branch that does not touch it.
  printf 'package m\nfunc old() { _ = "systemrole = '"'"'Admin'"'"'" }\n' > iznik-server-go/old.go
  git add -A && git commit -qm base
  git branch -M master
)

scenario() {
  (
    cd "$REPO" || exit 1
    git checkout -q master
    git branch -qD feature >/dev/null 2>&1
    git clean -qfd
    git checkout -qb feature
    mkdir -p iznik-batch/app iznik-batch/database/migrations iznik-nuxt3/components iznik-server-go
  )
}

# diffcase <name> <allow|block> <file> <content>
diffcase() {
  local name=$1 expect=$2 file=$3 content=$4 out rc got=allow
  scenario
  (cd "$REPO" && printf '%s\n' "$content" > "$file" && git add -A && git commit -qm change)
  out=$(cd "$REPO" && "$HOOK" --diff master 2>&1)
  rc=$?
  [ $rc -ne 0 ] && got=block
  if [ "$got" = "$expect" ]; then ok; else bad "[diff] $name: expected $expect, got $got (rc=$rc)" "$out"; fi
}

diffcase 'go sql systemrole added'   block iznik-server-go/a.go 'package m
func f() { db.Raw("SELECT 1 FROM users WHERE systemrole = '"'"'Support'"'"'") }'
diffcase 'php systemrole added'      block iznik-batch/app/N.php "<?php
\$q->where('systemrole', 'Support');"
diffcase 'php sql string added'      block iznik-batch/app/N.php "<?php
\$s = \"SELECT id FROM users WHERE systemrole = 'Support'\";"
diffcase 'go test user role added'   block iznik-server-go/a_test.go 'package m
func TestX() { id := CreateTestUser(t, "p", "Admin") }'
diffcase 'component compare added'   block iznik-nuxt3/components/A.vue '<script setup>
const ok = role === '"'"'Owner'"'"'
</script>'
diffcase 'option value added'        block iznik-nuxt3/components/A.vue '<template><select><option value="Moderator">Moderator</option></select></template>'
diffcase 'laravel where added'       block iznik-batch/app/N.php "<?php
\$q->where('role', 'Owner');"
diffcase 'copy added'                allow iznik-nuxt3/components/A.vue '<template><h1>Moderator</h1><p>Contact an Admin</p></template>'
diffcase 'mail copy added'           allow iznik-batch/app/N.php "<?php
\$line = 'A Moderator replied to your message';"
diffcase 'constant definition added' allow iznik-server-go/a.go 'package m
const ROLE_OWNER = "Owner"'
diffcase 'migration added'           allow iznik-batch/database/migrations/m.php "<?php
DB::table('users')->where('systemrole', 'Admin')->update([]);"
diffcase 'constants used added'      allow iznik-batch/app/N.php "<?php
\$q->where('role', Membership::ROLE_OWNER);"
diffcase 'escape hatch added'        allow iznik-batch/app/N.php "<?php
\$r = ['role' => 'Support']; // role-literal-ok: support case message role"

# An existing literal that the branch does not touch is not the branch's problem.
scenario
(cd "$REPO" && printf '<?php\n$x = 1;\n' > iznik-batch/app/Other.php && git add -A && git commit -qm other)
out=$(cd "$REPO" && "$HOOK" --diff master 2>&1); rc=$?
if [ $rc -eq 0 ]; then ok; else bad "[diff] untouched existing literal blocked" "$out"; fi

echo "== all mode =="
scenario
(cd "$REPO" && printf '<?php\n$q->where("role", "Owner");\n' > iznik-batch/app/A.php && printf 'x = 1\n' > iznik-batch/app/B.php && git add -A && git commit -qm all)
out=$(cd "$REPO" && "$HOOK" --all 2>&1); rc=$?
if [ $rc -ne 0 ] && echo "$out" | grep -q 'iznik-batch/app/A.php:2:' && echo "$out" | grep -q 'iznik-server-go/old.go:2:' && echo "$out" | grep -q '^Total: 2 '; then ok; else bad "[all] whole tree finds both hits with a total" "$out"; fi
out=$(cd "$REPO" && "$HOOK" --all iznik-batch 2>&1); rc=$?
if [ $rc -ne 0 ] && echo "$out" | grep -q '^Total: 1 ' && ! echo "$out" | grep -q 'old.go'; then ok; else bad "[all] path prefix limits the scan" "$out"; fi
out=$(cd "$REPO" && "$HOOK" --all iznik-nuxt3 2>&1); rc=$?
if [ $rc -eq 0 ]; then ok; else bad "[all] clean prefix exits 0" "$out"; fi

echo
echo "passed: $PASS, failed: $FAIL"
[ "$FAIL" -eq 0 ]
