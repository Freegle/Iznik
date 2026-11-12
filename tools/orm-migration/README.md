# Raw SQL inventory and ratchet

Tooling for section 7.1 of
[`plans/database-migration-evaluation-2026-07.md`](../../plans/database-migration-evaluation-2026-07.md).
It finds every raw SQL site in production Go and Laravel code, records each one in
`manifest.json` with a status, and fails CI when a site appears that the manifest does not know.
It needs Node only.

```
node tools/orm-migration/extract.mjs update [--raise-baseline]   # regenerate manifest.json
node tools/orm-migration/extract.mjs check [--base REF]          # the CI ratchet
node tools/orm-migration/extract.mjs summary                     # counts by status
node tools/orm-migration/extract.mjs list [--status raw] [--file TEXT]
node tools/orm-migration/extract.mjs orphans                      # keep-raw markers with no site
node --test tools/orm-migration/test/*.test.mjs                  # self-tests
```

CI runs the self-tests and `check --base origin/master` in the `check-raw-sql-ratchet` job, beside
`check-raw-sql`. The two are different. `check-raw-sql` looks at the lines a branch adds and accepts
a written reason. The ratchet compares the whole tree with the manifest.

## What counts as a site

Production code only. Tests are counted per file under `testFixtures` and not listed one by one;
adding a test fixture never fails the ratchet.

| Surface | Go | Laravel |
|---|---|---|
| `statement` | `.Raw`, `.Exec`, the `database/sql` calls (`Query`, `QueryRow`, `Prepare` and their context forms), `RetryExec`, `RetryExecResult`, `RetryQuery`, `ExecInsertGetID`, and `.Select` in a function that replaces GORM's clause builders | `DB::select`, `selectOne`, `scalar`, `cursor`, `insert`, `update`, `delete`, `statement`, `unprepared`, `affectingStatement`, also on `DB::connection(...)` |
| `fragment` | `Where`, `Or`, `Not`, `Select`, `Order`, `Joins`, `Group`, `Having`, `Table` with a string argument that holds a dialect feature | `whereRaw`, `orWhereRaw`, `selectRaw`, `fromRaw`, `groupByRaw`, `havingRaw`, `orHavingRaw`, `orderByRaw`, `rawIndex`, `rawColumn` |
| `expression` | `gorm.Expr` and `clause.Expr` with a dialect feature or an unreadable argument | `DB::raw` with a dialect feature or an unreadable argument |
| `clause` | `clause.OnConflict{...}` and `clause.Insert{Modifier: ...}`: GORM builds the statement, and PostgreSQL needs it written differently | not used |
| `result` | `.LastInsertId()` on a driver result | `->lastInsertId()` on a PDO handle |
| `pdo` | not used | `prepare`, `exec`, `query` on a PDO handle, or given text that starts like SQL |

A "dialect feature" is anything the other engine spells differently: `ON DUPLICATE KEY`, `IFNULL`,
`DATE_FORMAT`, `INTERVAL n UNIT`, `ST_*`, `JSON_*`, `LAST_INSERT_ID`, `GROUP_CONCAT`, `FIELD`, index
hints and the rest of the list in `lib/sql.mjs`. The list also holds differences that raise no
error on PostgreSQL but change the answer: `LEAST` and `GREATEST` with a NULL, `CONCAT` with a NULL,
`LENGTH` (bytes or characters), `IS NULL AS x` (integer or boolean), and the ones PostgreSQL rejects
(`VALUES(col)` in an upsert, `UPDATE` and `DELETE` with `ORDER BY` or `LIMIT`, `CAST(x AS SIGNED)`,
`HAVING` on a select-list alias, `SUBSTRING_INDEX`, `LOCATE`). `NOW()`, `NULL`, `COUNT(*)` and arithmetic are the
same on both engines, so a bare expression holding only those is not a site. A statement is always a
site. A bare expression or fragment that carries a `keep-raw:` marker is a site too, because the
marker is a person saying it is raw SQL.

The SQL text is folded from string literals, `+` and `.` concatenation, package and class
constants, and `Sprintf` with constant arguments. What cannot be folded becomes `{{expr}}` and the
site is `dynamic`. A statement held in a variable is a site with `sqlSource: variable`.

## What is not tracked

The manifest is the list of sites the scanner can recognise. It is not a proof that nothing else
runs SQL, and the port should not treat it as one.

- A GORM chain fragment (`Where`, `Joins`, `Order`, `Select`) with no listed dialect feature is not
  a site. Comparisons such as `deleted = 0`, row comparisons and `EXISTS` subqueries are the main
  cases where the other engine differs without a keyword to find.
- A Go query on a receiver that is not named as above, or a statement passed through a helper the
  scanner does not know, is missed. `RetryExec`, `RetryExecResult`, `RetryQuery` and
  `ExecInsertGetID` are known.
- Eloquent's own upsert methods (`upsert`, `insertOrIgnore`, `updateOrInsert`) and the Laravel
  query builder's dialect-specific grammar are not sites. Neither are GORM's `Create` and `Save`.
  They need a conflict target or different SQL on PostgreSQL.
- A column named like a PostgreSQL reserved word (`user`) and used unquoted is not flagged.
- `extract.mjs orphans` lists `keep-raw:` markers that no site picked up. They sit on code the
  scanner does not read as raw SQL, so they are not in the manifest.

## Statuses

Each site has exactly one.

| Status | Meaning |
|---|---|
| `raw` | Not yet triaged or converted. The default. |
| `in-progress` | A conversion is under way. Kept by hand in the manifest. |
| `converted` | The raw SQL is gone and a test names the site's id as a quoted string. |
| `keep-raw` | Stays raw on purpose. Needs a reason. |
| `excluded` | Runs against another engine (the SQLite stores and R-tree index). Needs a reason. |
| `retired` | Gone from the code with no parity test. Kept as a record. |
| `test-fixture` | Counted per file, not listed. |

Where a status comes from, in order:

1. A rule in `rules.json` (a file or directory, optionally one function or surface, or one id).
2. A `// keep-raw: <reason>` comment on the line, or within 25 lines above it (the same window
   `.claude/check-raw-sql.sh` uses). One marker covers every site in that window, up to the next
   marker, so a marker over several statements gives them all the same reason. The comment text, with any comment lines that continue it,
   is the reason, and the entry says `reasonSource: annotation`.
3. A keep-raw or in-progress decision already recorded in the manifest by hand.
4. Otherwise `raw`.

## The ratchet

`check` regenerates the inventory and fails when:

- a site is in the code and not in the manifest (new raw SQL);
- a manifest entry no longer matches the code (its SQL changed or it was removed);
- an entry's recorded SQL, status or reason differs from what the code and rules give;
- a `keep-raw` or `excluded` entry has no reason;
- a `converted` entry has no test naming its id;
- the count of `raw` plus `in-progress` sites is above `ratchet.baseline`, or the baseline is
  higher than the count (it is always kept tight);
- with `--base REF`, the baseline is higher than the one on that branch.

Line numbers are recorded but never compared, so an edit above a site does not fail the check.

To add raw SQL, put a `keep-raw: <reason>` comment above it, run `update`, and commit
`manifest.json`. The site arrives as `keep-raw`, so the baseline does not move. `update` refuses to
raise the baseline; `--raise-baseline` exists for a reviewed exception and is visible in the diff.
Converting a site and running `update` lowers the baseline by itself.

A site's id is a hash of its file and its normalised SQL, so changing a query changes its id.
`update` carries the status and reason across when it is plainly the same site (same file, function
and method), so editing a deliberate query needs a regenerate and not a new justification.

## Golden stores and approved diffs

The parity harness (docs/developers/reference/parity-harness.md) compares a converted site with its
`goldenSql`. The Go and Laravel tests run in containers that cannot read this directory, so `update` also
writes the part of the manifest each stack needs: `iznik-server-go/parity/goldens.json` and
`iznik-batch/tests/fixtures/orm-parity/goldens.json` (`lib/parity-store.mjs`). `check` fails when either is
stale, when an `approvedDiff` lacks its `sql` or `reason`, and when the two canonicaliser corpus copies
(`iznik-server-go/parity/canonical-corpus.json` and `iznik-batch/tests/Support/OrmHarness/canonical-corpus.json`)
differ.

```
node tools/orm-migration/extract.mjs approve ID --sql 'SELECT ...' --reason 'Why this is the same statement.'
```

records an approved diff on a manifest entry. It survives `update`.

`iznik-server-go/parity/` is test support, so its fixture SQL is counted with the tests and is not a site.

## Files

- `extract.mjs`: the command line.
- `lib/go-scan.mjs`, `lib/php-scan.mjs`: the scanners. They read tokens, not lines.
- `lib/sql.mjs`: normalising, dialect features, complexity and conversion wave.
- `lib/inventory.mjs`: walking the repository, merging with the manifest, the ratchet.
- `lib/parity-store.mjs`: the golden stores, approved diff checks and the corpus copy check.
- `rules.json`: file-level decisions.
- `manifest.json`: the inventory. One site per line so a diff reads as one line per change.
- `services/laravel/`: the earlier Laravel inventory and the harness inputs the Laravel tests
  still read. It is separate from this manifest.
