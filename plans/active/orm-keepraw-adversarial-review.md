# ORM Migration Keep-Raw Review — Decision Document

## 1. Headline count

Of the **257** keep-raw sites reviewed:

| Verdict | Sites | % | Meaning |
|---|---:|---:|---|
| **CONVERTIBLE now** | **136** | 53% | Proven chain, existing precedent or a written test, no new infrastructure needed |
| **NEEDS-HARNESS-WORK** | **82** | 32% | GORM *can* express the SQL — confirmed by source trace and/or test — but needs a golden edit, a small one-time harness addition, an extractor fix, or (in a few cases) a small code change first |
| **GENUINELY-RAW** | **24** | 9% | A real GORM/clause-vocabulary limitation, correctly kept raw (though 16 of the 24 have the *wrong reason* written down — see §3) |
| **UNCLASSIFIED** | **15** | 6% | Same shape as the proven-CONVERTIBLE bucket (plain single-row INSERT) but not individually re-verified by the adversarial pass — recommend a quick per-site check before batching them in with Tier 1 |

**Bottom line: 216 of 257 (84%) are not blocked by anything GORM can't do.** Only 24 sites (9%) reflect a genuine capability gap. The keep-raw list, as written, overstates the blocker rate by roughly 9x.

Two additional real production SQL statements (`message/markseen.go:104,128`, called via `database.RetryExec`) are **invisible to the extractor entirely** — not in the 257, not converted, not counted anywhere. That's a gap in the inventory itself, separate from convertibility.

---

## 2. Mechanism → verdict → count → reason

### Runtime-varying SQL (86 sites)

| Mechanism | Verdict | Sites | Reason |
|---|---|---:|---|
| Finite optional-filter fragments (bool/enum toggles) | CONVERTIBLE | 55 | shapes.json pattern already shipped (3 sibling sites in image.go); every toggle traced to source and confirmed bounded |
| Hand-built IN-list, list is the only variable part | CONVERTIBLE | 5 | GORM's native `IN ?` slice-bind + harness's `collapseInLists` already normalize this |
| Same IN-list class, but a second hidden shape axis (4-way filter switch / conditional JOIN) | NEEDS-HARNESS-WORK | 2 | Whole-statement golden is still `{{built at runtime}}`; needs its own shapes.json entries; donations.go site also has a real `NOT IN (NULL)`-on-empty-list correctness hazard to guard against |
| Extractor wrongly flagged dynamic (Go const / const-returning func) | CONVERTIBLE | 5 | Same self-inflicted-caution bug already found 3x in this project; text is genuinely fixed, single golden |
| Same, but a 2nd const-driven WHERE fragment (`stratumSQL`) was missed — 4 real shapes | NEEDS-HARNESS-WORK | 2 | Needs 4-shape `AssertGoldenShapes`, not 1 golden |
| Same, but the "fixed" value is a live-recomputed aggregate (`avg`) spliced via `%f` | NEEDS-HARNESS-WORK | 3 | Needs an actual code change (move into a bind arg) plus a numeric-precision test — not just a golden |
| Dynamic SET, per-field closure or unconditional unrolled batch | CONVERTIBLE | 3 | `.Table().Where().Update(col,val)` per field already proven a few lines away in the same file; `handleMerge`'s 41 statements are unconditional, not caller-selected — unroll individually |
| Dynamic SET, one statement with 10–17 independently-optional fields | NEEDS-HARNESS-WORK | 2 | GORM can build it; exhaustive shape coverage is 2^10–2^17 — no precedent at this scale, needs a policy call on partial coverage |
| Per-group UNION ALL, branch count = `len(groupIDs)`, unbounded | NEEDS-HARNESS-WORK | 5 | shapes.json has no parametrized-shape primitive; needs a new assertion type |
| GDPR export pipeline, raw `*sql.DB` → GORM `.Rows()` | NEEDS-HARNESS-WORK | 4 | `db.Raw(...).Rows()` is a drop-in for the stream target, but without `.Clauses(dbresolver.Write)` it reproduces the *exact* pattern behind a confirmed live production incident (read-replica lag returning the wrong id) — needs the write-pin plus a dedicated routing-regression test |

### INSERT whose generated id is read back (58 sites)

| Mechanism | Verdict | Sites | Reason |
|---|---|---:|---|
| Plain single-row INSERT, isolated, literal | CONVERTIBLE | 15 | 13 sibling sites already shipped this exact pattern |
| Same, coordinate text built via `fmt.Sprintf` before Exec | NEEDS-HARNESS-WORK | 5 | Needs `gorm.Expr(...)` not a plain bind, and a decision on `%f`-truncation vs full float64 precision |
| Same, table/column name built at runtime (incl. shared `ExecInsertGetID` helper, 11 call sites) | NEEDS-HARNESS-WORK | 4 | Each call site needs its own inventory entry; the helper has no single golden |
| `ON DUPLICATE KEY UPDATE ... id=LAST_INSERT_ID(id)` | NEEDS-HARNESS-WORK | 10 | `RowsAffected==0` on a no-op dupe hit (guaranteed on every dupe for 3 of the 10) skips the id writeback; needs `gorm.WithResult()`, not the ordinary `row["@id"]` convention |
| INSERT ... SELECT template copy (modconfig.go) | NEEDS-HARNESS-WORK | 2 | Same clause-override mechanism proven for the 5 sibling sites below (§ Four-small, E5) — this category's own GENUINELY-RAW verdict is contradicted by that proof |
| SELECT...FOR UPDATE + INSERT sharing one raw `*sql.Tx` | GENUINELY-RAW | 5 | See §3 |
| ODKU with no id-forcing at all (tryst.go) | GENUINELY-RAW | 2 | See §3 |
| Remaining plain-insert sites, not individually re-verified | UNCLASSIFIED | 15 | Same shape as row 1; do a quick per-site check before batching |

### Spatial (38 sites)

| Mechanism | Verdict | Sites | Reason |
|---|---|---:|---|
| ST_* as literal SELECT-column text, `.Scan()`-terminated | CONVERTIBLE | 12 | Shipped precedent (user.go:408) |
| Same, `.First()`-terminated | NEEDS-HARNESS-WORK | 2 | `First()` injects `ORDER BY`+`LIMIT` and `RaiseErrorOnNotFound` the golden/caller don't have — needs `Find()` + an explicit `RowsAffected==0` check, a caller-side change |
| ST_Contains/ST_SRID/POLYGON as bind-heavy WHERE/JOIN-ON | CONVERTIBLE | 7 | GORM never parses SQL content; proven via a shipped sibling (chatmessage.go:615) |
| Same, extractor's `dynamic:true` unexamined | NEEDS-HARNESS-WORK | 1 | Likely another const-fold gap — resolve the flag first |
| UPDATE assignment via `gorm.Expr(ST_GeomFromText/ST_SIMPLIFY)`, SRID as a plain bind | CONVERTIBLE | 1 | Shipped precedent same file |
| Same, SRID spliced via `fmt.Sprintf("%d", const)` — golden has literal unresolved `%d` | NEEDS-HARNESS-WORK | 2 | Extractor can't const-fold numeric Go consts across packages (`utils.SRID`); fix the extractor once, benefits multiple sites |
| Bound ORDER BY (`ST_Distance_Sphere`) | CONVERTIBLE | 1 | Shipped precedent (message.go:4388) |
| Bare scalar SELECT, no FROM (EXISTS/ST_IsValid) | **CONVERTIBLE** | 2 | **Wrongly marked GENUINELY-RAW by this category's own reviewer** — the identical mechanism was proven convertible with passing tests by a *different* category (`BuildClauses={"SELECT"}` override); apply the same technique |
| Same, plus the SRID `%d` extractor gap | NEEDS-HARNESS-WORK | 1 | Combination of the two fixes above |
| Multi-table DELETE ... FROM t1 JOIN t2 | CONVERTIBLE | 1 | `clause.Delete{Modifier}` override, same convention already used for INSERT IGNORE |
| CREATE TEMPORARY TABLE ... AS SELECT (DDL) | GENUINELY-RAW | 1 | See §3 (merged with Four-small's DDL) |
| Top-level UNION (spatial predicate inside) | **CONVERTIBLE** | 2 | **Wrongly marked GENUINELY-RAW** — same `BuildClauses={"SELECT"}` technique proven elsewhere applies unchanged; this reviewer just didn't try it |
| WHERE fragment from Go helpers splicing literal ints | NEEDS-HARNESS-WORK | 5 | GORM isn't the blocker; `collapseInLists` only handles bind placeholders, not literal-int lists — switch the helpers to binds or write a bespoke shape declaration |

### Multi-table UPDATE/DELETE...JOIN (15) + REPLACE INTO (11) = 26 sites

| Mechanism | Verdict | Sites | Reason |
|---|---|---:|---|
| Genuine multi-table UPDATE...JOIN | CONVERTIBLE | 4 | `Table()`-verbatim JOIN text + explicit `clause.Set`, verified byte-level against `Canonical()` by hand |
| Same, but needs a live-DB `sync.Once` gate bypassed to exercise its 2nd shape | NEEDS-HARNESS-WORK | 1 | No shapes.json entry exists; the gate isn't a pure toggle |
| Mislabeled: single-table dynamic-SET UPDATE, no JOIN at all | CONVERTIBLE | 4 | `Updates(map)` is the established, already-shipped pattern (user.go:2418); sort-order risk checked, doesn't apply |
| Mislabeled: plain SELECT (2 have real JOINs) with a literal (non-bind) IN-list | NEEDS-HARNESS-WORK | 5 | "Harness already handles it" is false as recorded — `collapseInLists` only matches literal `?` runs, not the `{{expr}}`/`%s` these 5 goldens actually contain; needs golden edits + `Find()` not `Scan()` |
| CTE-heavy SELECT (5 joins, `ROW_NUMBER()`, 11 binds) | NEEDS-HARNESS-WORK | 1 | Mechanically possible but large enough to need its own dedicated test, not a mechanical substitution |
| REPLACE INTO | NEEDS-HARNESS-WORK | 11 | `clause.Insert` always renders `"INSERT "+Modifier` — `Modifier:"REPLACE"` alone produces `INSERT REPLACE INTO`; fix is a one-time `db.ClauseBuilders["INSERT"]` override, structurally proven, not yet wired up |

### Four small categories: bare EXISTS(9), top-level UNION(5), INSERT...SELECT(5), DDL(2) = 21 sites

| Mechanism | Verdict | Sites | Reason |
|---|---|---:|---|
| Bare SELECT EXISTS(...), no top-level FROM | CONVERTIBLE | 9 | `Statement.BuildClauses = []string{"SELECT"}` suppresses GORM's automatic FROM injection; proven with passing tests |
| Top-level UNION, nothing wrapping it | CONVERTIBLE | 3 | Same trick; 1 of 3 still needs its own dedicated test (currently only generalized from the other 2) |
| Same category, actually `ST_UNION()` not SQL UNION — blocked only by the SRID extractor gap | NEEDS-HARNESS-WORK | 1 | Miscategorized; ordinary SELECT once the numeric-const-fold gap is fixed |
| Same category, real variable-arm derived-table UNION with a literal-spliced id | NEEDS-HARNESS-WORK | 1 | No fixed golden (arm count varies at runtime); needs Layer-2 result-parity, not Layer-1 text matching |
| INSERT ... SELECT via clause-name-override | NEEDS-HARNESS-WORK | 5 (+2 from Insert-id category) | Mechanism proven sound by source-trace, **but the tests as written don't pass** — GORM's empty-slice dry-run guard fires before the SQL comparison ever runs; fix the test (non-empty `Dest`), then it's solid |
| DDL: CREATE/DROP TEMPORARY TABLE | GENUINELY-RAW | 2 | See §3 |

### Reach-cap / retry / not-applicable (28 sites, + 2 uninventoried)

| Mechanism | Verdict | Sites | Reason |
|---|---|---:|---|
| `AuthorReachCapWhere` via `db.Scopes()` | NEEDS-HARNESS-WORK | 2 | Mechanism (reusable scope, correct bind order) is sound, but GORM wraps any bare-AND/OR raw fragment in a redundant outer paren pair when composed with other Where/Scopes calls; `Canonical()` doesn't normalize that away — needs a sign-off or a restructure |
| 8 sites swept in by a file-wide (no function-scoping) keep-raw rule, unrelated to the fragment | CONVERTIBLE | 6 | IN-list/EXISTS-subquery/scalar-JSON_EXTRACT patterns, all separately precedented elsewhere in the shipped codebase |
| Same sweep, 2 sites have a literal (non-bind) IN-list in the recorded golden | NEEDS-HARNESS-WORK | 2 | Needs golden regenerated to use `?`/`(?)` first — no `dynamic:true` site has ever cleared Layer 1 in this manifest |
| Generic RetryQuery/RetryExec/RetryExecResult wrappers | GENUINELY-RAW | 3 | See §3 |
| Real production SQL invisible to the extractor (`markseen.go` via `database.RetryExec`) | *not in the 257* | 2 | Extractor only recognizes literal `.Raw`/`.Exec` selectors, not calls through this wrapper — **add to the inventory**, looks like ordinary ODKU shape |
| MAX_EXECUTION_TIME optimizer-hint SELECTs | NEEDS-HARNESS-WORK | 3 | GORM carries the hint through verbatim, but `Canonical()` strips **all** `/* */` comments on both sides before comparison, so the existing tests are structurally blind to whether the hint survived — needs a hint-specific substring/regex assertion added |
| ormharness dev-tooling pass-through (`runRawSQL`, `ExplainTree`) | GENUINELY-RAW | 2 | See §3 |
| ormharness dev-tooling ordinary queries (scope-correct exclusion) | GENUINELY-RAW | 2 | See §3 |
| ormshadow `ShadowRead` pass-through | GENUINELY-RAW | 1 | See §3 |
| ormshadow `CurrentBatchState`, mis-scoped "not applicable" reason on an ordinary static SELECT | CONVERTIBLE | 1 | Same directory-wide-rule bug; shipped precedent exists (item/reusebenefit.go) |
| userdump/sqlite.go SQLite-file-writing mechanics | GENUINELY-RAW | 6 | See §3 |

---

## 3. Corrected `keep-raw.json` reason text for the 24 GENUINELY-RAW sites

**Row-lock shared transaction (5 sites, `chat/chatroom.go` `GetOrCreateUser2ModChat`):**
> This function runs `SELECT ... FOR UPDATE` and the following INSERT on one shared raw `*sql.Tx` (via `db.DB().Begin()`) to hold a row lock across both statements — the unique key (user1, user2, chattype) doesn't prevent duplicate rows when user2 is NULL, since MySQL treats NULLs as distinct in unique indexes. GORM's `Create()`/`First()` can't participate in a lock acquired by a separate raw statement without a full `db.Transaction()` rewrap of the whole function. Convertible only as part of a larger transaction-handling redesign of this function, not a per-statement wave conversion.

**ODKU with no id-forcing (2 sites, `tryst/tryst.go` `CreateTryst`):**
> The ON DUPLICATE KEY UPDATE clause here is `arrangedat = NOW()` with no `id = LAST_INSERT_ID(id)` forcing, so on a duplicate-key hit MySQL's `LAST_INSERT_ID()` doesn't report the existing row's id — the current raw code already returns 0 in that case. This is a pre-existing application-level bug, not a GORM limitation; a mechanical port would reproduce it. Fixing it is a deliberate behaviour change (add id-forcing to the UPDATE clause), tracked separately — not a wave conversion.

**DDL: CREATE/DROP TEMPORARY TABLE, including CREATE ... AS SELECT (3 sites: 58213463d5dc, cdbd40c98336, dd475ec31446):**
> This statement is DDL, not DML. GORM's clause vocabulary has no CREATE/DROP TABLE clause type — this isn't a workaround gap, GORM's own Migrator falls back to `db.Exec()` with literal SQL for exactly this reason. This code already calls `db.Exec()`, the GORM-native mechanism for DDL. Not a candidate for chain conversion by design.

**Generic retry wrappers (3 sites, `database/retry.go`):**
> `RetryQuery`/`RetryExec`/`RetryExecResult`'s `sql string, args ...interface{}` parameters are supplied by an arbitrary caller at runtime — there is no fixed shape at these definition sites to compile into a chain; the SQL belongs to (and should be converted at) each call site. NOTE: at least 2 real production call sites (`message/markseen.go:104,128`) are currently invisible to the extractor because it only recognizes literal `.Raw`/`.Exec` selectors, not calls through this wrapper — add those 2 statements to the manifest before this reason is considered complete.

**ormharness dev-tooling pass-through, `runRawSQL`/`ExplainTree` (2 sites):**
> These execute a caller-supplied SQL string by design (parity comparison / EXPLAIN) — no fixed shape to convert without defeating the tool's purpose. `ormharness` has zero non-test importers and never links into the production binary, so nothing here is hidden production SQL.

**ormharness dev-tooling ordinary queries, `replayFetchTableRows`/`replayPrimaryKeyColumns` (2 sites):**
> These are mechanically simple, GORM-convertible queries — GORM is NOT incapable of them. Excluded solely because this package never runs against production traffic; converting dev-only test tooling isn't in scope. Re-evaluate if this code is ever reused on a production path.

**ormshadow `ShadowRead` pass-through (1 site):**
> `ShadowRead` executes whatever raw SQL a call site under Layer-3 shadow evaluation already used, unchanged, so its result can be compared against a new GORM read. Each shadowed site's real SQL is already inventoried and converted separately under its own manifest entry. Converting this call would mean hardcoding one query (breaking every reuse) or building a generic replay abstraction — a separate redesign, not a per-site conversion. (This reason was previously also mis-applied, via a directory-wide rule, to `CurrentBatchState` in the same file — that site is an ordinary static SELECT and has been reclassified CONVERTIBLE.)

**userdump/sqlite.go SQLite export mechanics (6 sites):**
> This builds a per-request ephemeral SQLite file via plain `database/sql` + `modernc.org/sqlite` — a different database driver entirely from the MySQL/Percona connection GORM is configured against. No `gorm.io/driver/sqlite` is wired into this codebase, so there is no dialector for this target even in principle. This does NOT hide any MySQL reads — the MySQL-side queries feeding this export are separately inventoried under "Runtime-varying SQL" and are themselves convertible; only the SQLite-file-writing mechanics are excluded here.

---

## 4. Conversion order — cheapest and safest first

**Tier 1 — convert now, one golden each, zero new infrastructure (~55 sites):** plain single-row INSERTs (15), clean IN-lists (5), const-mistaken-for-dynamic (5), ST_* literal SELECT text (12), ST_Contains bind-heavy WHERE/JOIN (7), the single-site bound-ORDER-BY and DELETE-JOIN wins (2), the reach-cap sweep-ins (6), ormshadow `CurrentBatchState` (1), UPDATE-via-gorm.Expr (1).

**Tier 2 — apply an already-proven-but-not-yet-generalized override (~27 sites):** `BuildClauses={"SELECT"}` for bare-EXISTS and top-level-UNION, including the two Spatial-category sites wrongly marked GENUINELY-RAW (9+3+2+2=16); `Table()`-verbatim JOIN + explicit `clause.Set` for genuine UPDATE...JOIN (4); `Updates(map)` for mislabeled dynamic-SET UPDATEs (4); per-field closure/unroll pattern for the two helper.go SET sites and handleMerge (3). Each of these is "apply a technique that already has a passing test in this repo," so they're safe and fast once someone does the replication.

**Tier 3 — shapes.json authoring, bounded and known (~62 sites):** the 55-site optional-filter bucket, plus the two 4-shape sites (getHappinessMembers, rippling analytics stratumSQL). Mechanically simple, just volume — declare N goldens per site, largest is ~32 shapes (membership.go).

**Tier 4 — one-time harness infrastructure that unlocks a batch (~33 sites):** `db.ClauseBuilders["INSERT"]` override for REPLACE INTO (11) and the INSERT...SELECT clause-override, once its broken test is fixed to use a non-empty `Dest` (5+2=7); `gorm.WithResult()` convention for the ON DUPLICATE KEY UPDATE `LAST_INSERT_ID(id)` trap (10); paren-normalization sign-off for `AuthorReachCapWhere` scopes (2); hint-specific assertion for MAX_EXECUTION_TIME (3).

**Tier 5 — manifest/golden data edits (~11 sites):** the mislabeled plain-SELECT-with-literal-IN-list sites (5), markPinned/myGroupsMessages (2), plus fixing the extractor's numeric-const-fold gap once (benefits `utils.SRID` sites: 3 Spatial + 1 Four-small).

**Tier 6 — small code changes, not just tests (~10 sites):** move `avg` into a bind instead of `%f`-splicing (3, with a precision-equivalence test); switch Sprintf-embedded coordinates to `gorm.Expr` (5, with a precision decision); switch `.First()`→`.Find()`+explicit `RowsAffected` check on the caller side (2).

**Tier 7 — per-call-site inventory work (~5 sites):** the shared `ExecInsertGetID` helper's 11 call sites need individual review (4 of them in-scope here); resolve the one unexamined `dynamic:true` flag in Spatial.

**Tier 8 — policy decision needed before authoring (2 sites):** `session.go`/`modconfig.go`'s 10–17-field dynamic SET — either extend `Shape` to be parametrized or accept non-exhaustive coverage; someone who owns the shapes.json gate needs to make that call first.

**Tier 9 — needs new harness capability (~13 sites):** unbounded per-group UNION ALL (5, needs a parametrized-shape assertion type); the CTE-heavy SELECT (1) and variable-arm derived-table UNION (1), both wanting Layer-2 result-parity rather than Layer-1 text match; the literal-int WHERE-helper sites (5, switch to binds or write bespoke shapes); the sync.Once-gated second UPDATE...JOIN shape (1).

**Tier 10 — highest care, do last, extra review (4 sites):** the GDPR export pipeline. Mechanically the easiest fix in the whole list (`db.Raw(...).Rows()` plus one `.Clauses(dbresolver.Write)` pin) but the *un-pinned* version reproduces the exact bug pattern behind a confirmed live production data-corruption incident. Do this with a dedicated routing-regression test, not just Layer-1 SQL-text parity, since a dropped pin renders identical SQL text and would sail through golden comparison undetected.

**Do not convert (24 sites, §3):** row-lock transactions, tryst.go's pre-existing quirk, DDL, generic retry wrappers, and the SQLite export driver mismatch.

---

## 5. How much of the keep-raw list was unjustified — bluntly

**84% of it.** Only 24 of 257 sites (9%) reflect an actual GORM capability gap. This is the fourth time the same directional bias — cautious, confident, and wrong about the *mechanism* rather than the risk — has shown up in this migration, and it recurred at scale even after three prior corrections were already documented.

Three things stand out as worth fixing in the *process*, not just the sites:

**Cross-category blind spots produced wrong "genuinely raw" verdicts that survived their own category's adversarial pass.** The Spatial reviewer declared bare-`SELECT EXISTS(...)` (3 sites) and top-level UNION (2 sites) GENUINELY-RAW using a real, correctly-cited fact — GORM's default pipeline always emits a FROM clause. But a *different* category reviewer, working the same structural shape, found and proved (with passing tests) that pre-setting `Statement.BuildClauses = []string{"SELECT"}` suppresses that FROM injection entirely. Neither the Spatial reviewer nor their in-category skeptic caught this, because each adversarial pass only cross-checked claims within its own category. Five sites were sitting in "impossible" for no reason other than a reviewer not trying a technique someone else in the same worktree had already shipped tests for. Similarly, the INSERT-id category declared modconfig.go's INSERT...SELECT sites (2) GENUINELY-RAW on the same reasoning that a sibling category's reviewer independently disproved for 5 near-identical sites via a `ClauseBuilders` override.

**Even the reviewers arguing "convertible" were often sloppy, and needed a second layer of adversarial checking to catch it.** A cited "already converted, identical pattern" precedent (story.go) turned out to be false — not converted, not even using the technique claimed. A proposed GORM chain for a spatial WHERE fragment silently dropped a JOIN clause and 2 binds relative to its own cited golden. Three separate test files (`insertselect_test.go`, `reachcap_test.go`, the MAX_EXECUTION_TIME preflight tests) were written and described as proof, but don't actually exercise the failure mode they claim to guard against — one hits GORM's empty-slice dry-run guard before the SQL comparison runs at all, one only does substring/count checks that a redundant-paren regression sails through, and one relies on `Canonical()`, which strips every `/* */` comment (including the optimizer hint the whole category exists to protect) before comparing, making it structurally blind to the one thing it needed to catch. None of these are mechanism failures — GORM really can do all three things — but "I wrote a test" and "the test proves what I claim" turned out to be different statements in about a fifth of the adversarially-reviewed claims.

**The keep-raw.json *reasons*, even where the raw verdict is correct, are frequently wrong about *why*.** Two directory-wide keep-raw rules with no function scoping swept unrelated sites into a category (`isochrone/message.go`'s 8 sites had nothing to do with the reach-cap fragment their reason cites; `ormshadow/`'s `CurrentBatchState` inherited a "not a migration target" reason that directly contradicts the same file's own doc comment). A blanket "Spatial: risk of getting ST_* subtly wrong" reason was attached to a site whose actual blocker is that a Go compile-time constant (`utils.SRID = 3857`) got spliced through `fmt.Sprintf("%d", ...)` before the extractor saw it — a tooling gap, not a spatial-semantics risk. And a boilerplate "connection-scoped, LAST_INSERT_ID is per-connection" reason — the specific claim already proven wrong twice in this project's history — was still attached to sites whose actual, different, and real blocker (a shared-transaction row lock, or a no-op ON DUPLICATE UPDATE) has nothing to do with connection scoping at all.

None of the adversarially-refuted "convertible" claims turned out to be truly impossible — every single refutation downgraded a site from "trivially convertible, zero work" to "convertible, but do the harness work first," never to GENUINELY-RAW. That's the strongest signal in this whole review: the reviewers' instinct that GORM could do almost all of this was right even in the cases where their specific evidence was weak. The caution reflex, not the risk assessment, is what's been consistently miscalibrated.