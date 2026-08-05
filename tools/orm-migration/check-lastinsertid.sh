#!/usr/bin/env bash
#
# check-lastinsertid.sh - has a statement that depends on LAST_INSERT_ID been
# converted?
#
# Only the SQL FUNCTION is unsafe. "id = LAST_INSERT_ID(id)" in an upsert
# returns the existing row's id on conflict, and "SELECT LAST_INSERT_ID()" as a
# separate statement reads session state that belongs to whichever connection
# the pool hands out. Those depend on the session and stay raw.
#
# Reading sql.Result.LastInsertId() in Go is NOT unsafe, and this gate used to
# say it was. That cost 58 sites, each with a keep-raw entry and a porting note
# explaining how much better they would be on Postgres.
#
# The distinction is the one go-sql-driver/mysql#377 had to make. Its first
# answer is "No, it is not - the connection pooling by database/sql makes it
# unsafe", which is corrected: Result.LastInsertId is "perfectly safe to use
# concurrently because it's stored in the result and has nothing to do with the
# connections", while a following SELECT LAST_INSERT_ID() "might use a different
# connection because of the pooling".
#
# The driver agrees. The id is parsed from the OK packet into mysqlResult at exec
# time (packets.go handleOkPacket); Exec returns a COPY of that struct
# (connection.go, "copied := mc.result"); and clearResult nils the slice, so the
# next statement appends to a fresh array rather than overwriting the previous
# one. Checked under concurrency in test/orm_insertid_test.go, where 24
# goroutines insert at once and each id must name the row that goroutine wrote.
#
# The list is generated from the merge-base with master, because converting a
# site removes its SQL and the question stops being answerable from the code.
#
# Regenerate after rebasing onto a new master:
#   tools/orm-migration/check-lastinsertid.sh --regenerate
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
LIST="$SCRIPT_DIR/lastinsertid-sites.json"
MANIFEST="${RATCHET_MANIFEST:-$REPO_ROOT/iznik-server-go/ormharness/manifest.json}"
LOOKAHEAD="${LOOKAHEAD:-12}"

if [ "${1:-}" = "--regenerate" ]; then
  MB="$(cd "$REPO_ROOT" && git merge-base HEAD origin/master)"
  WORK="$(mktemp -d)"
  trap 'rm -rf "$WORK"' EXIT
  (cd "$REPO_ROOT" && git archive "$MB" | tar -x -C "$WORK")
  (cd "$SCRIPT_DIR" && go run . -root "$WORK/iznik-server-go" -repo "$WORK" -out "$WORK/base.json" >/dev/null 2>&1)
  node -e '
    const fs = require("fs"), path = require("path");
    const [work, listPath, lookahead, mb] = process.argv.slice(1);
    const m = JSON.parse(fs.readFileSync(work + "/base.json", "utf8"));
    const out = {};

    // What is actually unsafe is LAST_INSERT_ID in the SQL TEXT, and nothing
    // else. This gate used to flag any INSERT whose Go code went on to call
    // sql.Result.LastInsertId(), on the theory that the id was connection-scoped
    // session state. That was wrong, and it cost 58 sites.
    //
    // The distinction is the one go-sql-driver/mysql#377 had to make. The first
    // answer there is "No, it is not - the connection pooling by database/sql
    // makes it unsafe", and it is corrected: Result.LastInsertId is "perfectly
    // safe to use concurrently because it is stored in the result and has
    // nothing to do with the connections", whereas issuing SELECT
    // LAST_INSERT_ID() afterwards "might use a different connection because of
    // the pooling".
    //
    // Reading the driver agrees: the value is parsed out of the OK packet into
    // mysqlResult at exec time, Exec returns a copy of that struct, and
    // clearResult nils the slice so the next statement allocates a fresh array
    // rather than overwriting it. Verified concurrently in
    // test/orm_insertid_test.go, where 24 goroutines insert at once and each
    // id is checked to name the row that goroutine wrote.
    //
    // So the list is now the statements that mention LAST_INSERT_ID themselves:
    // the "id = LAST_INSERT_ID(id)" upsert idiom, which returns the EXISTING
    // row id on conflict and genuinely depends on session state, and any
    // SELECT LAST_INSERT_ID(). Those stay raw.
    for (const [id, s] of Object.entries(m.sites)) {
      if (s.status === "test-fixture") continue;
      if (!/LAST_INSERT_ID/i.test(s.goldenSql || "")) continue;
      out[id] = {file: s.file, line: s.line, sql: (s.goldenSql || "").slice(0, 90)};
    }

    fs.writeFileSync(listPath, JSON.stringify({
      _comment: "Sites whose SQL TEXT uses LAST_INSERT_ID - the upsert idiom id = LAST_INSERT_ID(id), or a separate SELECT LAST_INSERT_ID(). Generated from " + mb + " by check-lastinsertid.sh --regenerate. These depend on connection-scoped session state and stay raw. Reading sql.Result.LastInsertId() in Go is a DIFFERENT thing and is safe - see the header comment.",
      sites: out,
    }, null, 2) + "\n");
    console.log(`check-lastinsertid: regenerated ${listPath} with ${Object.keys(out).length} sites from ${mb}`);
  ' "$WORK" "$LIST" "$LOOKAHEAD" "$MB"
  exit 0
fi

if [ ! -f "$LIST" ]; then
  echo "check-lastinsertid: FAIL: $LIST is missing; run with --regenerate" >&2
  exit 1
fi

# EDITING THE JS BELOW: it is embedded in a single-quoted shell string, so an
# apostrophe anywhere inside it - including in a // comment - closes that string
# and bash starts executing the rest of the JS as shell. The failure looks
# nothing like the cause: you get "//: Is a directory" and a syntax error on a
# line several below the apostrophe. Write "the id of the row", not "the row's
# id". (This comment is bash, not JS, which is why it can use one safely.)
node -e '
const fs = require("fs");
const [listPath, manifestPath] = process.argv.slice(1);
const list = JSON.parse(fs.readFileSync(listPath, "utf8")).sites;
const m = JSON.parse(fs.readFileSync(manifestPath, "utf8"));

// A converted LAST_INSERT_ID site is only a problem if the conversion reads the
// id the naive way. GORM skips its own "@id" writeback when RowsAffected is 0,
// which MySQL reports for a duplicate hit that changed nothing - so a plain
// row["@id"] conversion silently loses the id exactly where this idiom exists to
// provide one.
//
// Clauses(gorm.WithResult()) does not have that problem: it hands back the
// sql.Result the driver returns, whose LastInsertId has no RowsAffected test.
// Measured in test/orm_insertid_test.go, where a no-op upsert reports
// rowsAffected=0 and still returns the right id.
//
// So the gate asks which technique was used rather than forbidding conversion
// outright. Forbidding it was the original mistake: this list started at 48
// sites on a misunderstanding, and every narrowing since has come from testing
// the mechanism instead of reasoning about it.
// Scoped to the STATEMENT, not the file. A file-level search would let one
// correctly converted site vouch for a broken one beside it, and
// chat/chatroom.go has four of these sites in one file - exactly the shape
// where that goes wrong silently.
//
// The window starts at the site marker and runs to the next site marker, which
// is tight enough that a neighbouring site cannot supply the evidence for this
// one, with a character cap so an unmarked run of code cannot stretch it.
//
// The cap counts CODE, not prose. It used to count raw characters from the
// marker, which meant the length of the explanatory comment decided the
// verdict: the tryst comment grew past 1200 characters while documenting
// why the statement changed, the window ran out inside the comment, and the
// gate reported a correctly-converted site as "converted without
// gorm.WithResult()" - the exact false alarm the history of this file is full of
// (a 12-line lookahead once missed comment.go by one line). Comments on this
// project are deliberately long, so any gate whose answer depends on their
// length is measuring the wrong thing.
const usesWithResult = (file, id) => {
  let src;
  try { src = fs.readFileSync(file, "utf8"); } catch { return false; }
  const at = src.indexOf("ORM migration site " + id);
  if (at < 0) return false;
  const next = src.indexOf("ORM migration site ", at + 1);
  const region = src.slice(at, next < 0 ? src.length : next);
  const code = region
    .split("\n")
    .filter((line) => !line.trim().startsWith("//"))
    .join("\n");
  return /WithResult\(\)/.test(code.slice(0, 1200));
};

let flagged = 0, missing = 0, viaWithResult = 0;
for (const [id, info] of Object.entries(list)) {
  const site = m.sites[id];
  if (!site) { missing++; continue; }
  if (site.status !== "converted" && site.status !== "in-progress") continue;
  if (usesWithResult(info.file, id)) { viaWithResult++; continue; }
  console.log(`LASTINSERTID  ${id}  ${info.file}:${info.line}  status=${site.status}`);
  console.log(`              ${info.sql}`);
  console.log(`              converted without gorm.WithResult(): a no-op duplicate hit reports`);
  console.log(`              RowsAffected 0 and GORM then skips the id writeback entirely.`);
  flagged++;
}
console.log(`check-lastinsertid: ${Object.keys(list).length} LAST_INSERT_ID sites, ${flagged} wrongly converted, ${viaWithResult} converted safely via gorm.WithResult(), ${missing} no longer in the manifest`);
process.exit(flagged ? 1 : 0);
' "$LIST" "$MANIFEST"
