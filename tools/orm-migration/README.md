# ORM migration tooling

Tooling for the raw-SQL-to-ORM rewrite described in
[`plans/database-migration-evaluation-2026-07.md`](../../plans/database-migration-evaluation-2026-07.md),
section 7 ("The raw-SQL-to-ORM rewrite: a locked-in process"). Read that
section first — this file only covers how to run the tools; the *why* and the
full process (harness layers, conversion waves, definition of done) live
there.

## The manifest is the contract

`manifest.json` is a machine-generated inventory of every raw SQL call site in
`iznik-server-go` (`.Raw(`, `.Exec(`, and the `database/sql` equivalents).
It is committed to the repo and is the single source of truth the CI gate
enforces against — not anyone's memory or diligence (plan 7.1: "missing a
site or silently deferring one is structurally impossible").

Each site has exactly one **status**:

| Status | Meaning |
|---|---|
| `raw` | Not yet converted. The default for every newly-discovered site. |
| `in-progress` | A conversion is underway (e.g. mid-PR, or blocked on something). |
| `converted` | Rewritten to use the ORM. Requires a passing Layer-1 parity test bearing the site's ID (Gate 2, below) before this status is legitimate. |
| `keep-raw` | Deliberately staying raw (plan 7.5: the genuinely hard sites). **Must carry a non-empty `reason`** — the ratchet fails the build otherwise. |
| `test-fixture` | Go test file SQL (`_test.go`), out of scope for ORM-ification but in scope for the later engine migration (plan 7.6). |
| `retired` | The call site was deleted from the codebase; kept in the manifest as a record rather than silently disappearing. |

The programme is done (plan 7.4) when `raw` and `in-progress` are both zero.

## Running the extractor

```bash
cd tools/orm-migration
go run . -root ../../iznik-server-go -out manifest.json -repo ../..
```

(`-root`, `-out` and `-repo` all default to the right thing when run from this
directory, so `go run .` alone is normally enough.) It re-scans the source,
regenerates every derived field (file, line, SQL text, complexity, wave,
mysqlisms, etc.), and **merges forward** the reviewed fields (`status`,
`reason`, `approvedDiff`) from whatever is currently at `-out` by ID, so
re-running it never resets a reviewed decision. Run it, review the diff, and
commit `manifest.json` whenever you convert, retire or re-triage a site.

**Do not hand-edit a site's `status` to `converted`.** The extractor sets that
itself: a site whose raw SQL is gone from the code *and* which a parity test
names (see Gate 2 below) is promoted automatically on the next run. Writing it
by hand does nothing durable - the next regeneration derives it again - and it
is actively misleading, because the one case where the hand-edit changes the
output is the case where no parity test exists, which is exactly the case the
status is supposed to flag.

If a site you converted is still not showing as `converted` after a regenerate,
the missing thing is the parity test, or the extractor cannot see the assertion
you used - check `parityAssertions` in `extract.go`, and see gate (m). Reach for
that before reaching for the JSON. The one time this map fell behind the harness,
68 sites with real passing tests reported as unproven, and the natural reading -
"the manifest is stale, I should fix up the statuses" - was wrong in a way that
would have papered over the actual bug.

## Gate 1: the CI inventory ratchet (`ci-ratchet.sh`)

Implements plan 7.1 point 3. Runs the extractor against a **temp** copy of
the manifest (the committed `manifest.json` is never modified by this script)
and fails the build if:

1. **New raw SQL isn't in the manifest.** The code has a call site whose ID
   the committed manifest doesn't know about.
2. **The golden SQL has drifted.** A site's `goldenSql` in the committed
   manifest no longer matches what the extractor finds in the real source —
   either the manifest was hand-edited, or the source changed without
   re-running the extractor. This matters because Layer-1 parity tests
   compare against `goldenSql`; if it's wrong, those tests are proving
   nothing.
3. **The raw+in-progress count went up.** Compared against a baseline: an
   explicit top-level `"ratchet": {"baseline": N}` in the manifest if
   present, otherwise the manifest's own `counts.raw + counts["in-progress"]`
   (self-initialising — the very first run always passes). To bank progress
   and tighten the ratchet, lower `ratchet.baseline` in the same PR that
   reduces the count; the script never does this automatically (see the
   script header for why).
4. **A `keep-raw` site has no reason.** Deferral must always carry a written
   justification (plan 7.4).

Run it the same way locally and in CI:

```bash
tools/orm-migration/ci-ratchet.sh
```

It needs `go` and `jq` on `PATH` and works from any `cwd`. Output is prefixed
`CI-RATCHET:` so failures are easy to `grep` out of CI logs.

## Gate 2: no `converted` site without a passing parity test

Plan 7.2, Layer 1: a site cannot be marked `converted` unless a parity test
bearing its ID exists and passes ("the extractor checks test existence
mechanically"). **This is now wired up and enforced**, in three places:

- `extract.go`'s `parityTestedIDs` walks every `_test.go` file under the source
  root and records which site IDs are passed as string literals to a harness
  assertion. It parses rather than greps, deliberately: matching the ID anywhere
  in a file let a *comment* saying "site abc123 is deliberately not converted"
  satisfy the gate asserting that it was.
- A site whose raw SQL is gone and which such a test names is promoted to
  `converted` automatically. One that vanished with no test to vouch for it
  keeps its old status, so **ratchet gate (f)** can refuse it — "it disappeared"
  is never a route out of the inventory.
- **Ratchet gate (m)** checks `parityAssertions` against the harness's real
  surface: every exported `ormharness` assertion taking a `siteID` must be
  listed. Without that, adding an assertion silently un-proves every site using
  it (see the note under "Running the extractor").

A test may reach an assertion through a local helper of its own rather than
calling `ormharness` directly — `message_list_tier9_test.go` builds its
parametrized cases in `assertUnionAllSiteShape(t, siteID, ...)` and forwards.
`forwardingHelpers` resolves those to a fixed point, so such a test still counts.
It is deliberately narrow: the forwarded argument must be an identifier naming a
parameter of the enclosing function, because an ID the helper *computes* is not
the ID written at the call site, and crediting the literal would vouch for
something the test never asserted.

`AssertHintSurvivesInRawSQL` is intentionally *not* a parity assertion: it proves
an index hint survives in a statement that is **staying raw**, so counting it
would promote a keep-raw site on the strength of a test asserting the opposite.

## Burn-down reporting (`burndown.mjs`)

Plan 7.3: "the burn-down (manifest status counts over time) is a dashboard,
so progress and any stall are visible, not anecdotal." Prints counts by
status, wave, complexity and kind, a per-wave remaining/done breakdown, and
the modules with the most remaining raw+in-progress work (the natural unit
for planning the next PR, per the "one module or package per PR" batch rule).

```bash
node tools/orm-migration/burndown.mjs                # text report
node tools/orm-migration/burndown.mjs --json          # machine-readable, for a dashboard
node tools/orm-migration/burndown.mjs --top=25        # more modules in the "top remaining" table
node tools/orm-migration/burndown.mjs --manifest=path # point at a different manifest
```

No dependencies (Node ESM, stdlib only).

## Wave order

Fixed conversion order (plan 7.3), enforced by convention and the batch rule,
not mechanically by these tools yet:

0. **Pilot** — ten mixed-shape sites through all four harness layers, to
   calibrate cost. Nothing else converts until the pilot retrospective.
1. Single-table `SELECT` with simple predicates.
2. Single-table `INSERT`/`UPDATE`/`DELETE`.
3. Upserts, via `clause.OnConflict` / `Model::upsert()`.
4. Multi-table `SELECT`s with no MySQL-only functions.
5. Triage of everything left into `keep-raw` (with reason) or an individually
   planned conversion.

The extractor assigns each site a suggested `wave` using this order; see
`wave()` in `extract.go`.

## Testing the ratchet itself

`ci-ratchet.sh` reads the manifest path from `$RATCHET_MANIFEST` (default:
`manifest.json` in this directory) and the source root from `$RATCHET_ROOT`
(default: `iznik-server-go`), so you can exercise a failure mode without
touching the committed manifest:

```bash
jq '.ratchet = {"baseline": 0}' iznik-server-go/ormharness/manifest.json > /tmp/perturbed.json
RATCHET_MANIFEST=/tmp/perturbed.json tools/orm-migration/ci-ratchet.sh   # exits 1
```
