---
paths:
  - "iznik-server-go/**/*.go"
---

# Traps in the Go API

Each of these has cost real debugging time more than once. They share a shape: **no error, no
warning, a plausible wrong answer**. A passing test does not clear any of them.

## A helper that writes a refusal is not returning an error

```go
// WRONG - refuses nothing
return 0, c.Status(fiber.StatusUnauthorized).JSON(...)
```

`c.Status(...).JSON(...)` writes the response and returns `nil`, so the caller's
`if err != nil` never fires. The handler carries on, writes its own body over the refusal, and
you get HTTP 401 **with a complete success payload** - on a write endpoint, the write happens
too. Found on the Partnerships endpoints: an unauthenticated `GET /api/partnership` returned
every deal.

Use `fiber.NewError(fiber.StatusUnauthorized, "Not logged in")`; `main.go`'s ErrorHandler turns
it into the standard body. Returning `c.Status(...).JSON(...)` **directly from a handler** is
fine and widespread - the bug is only when the value is passed back as an error through a
helper's tuple return. Find them with `grep -rn 'return [^,]*, c\.Status('`.

A permission test asserting only `resp.StatusCode == 401` passes while the body leaks
everything. Assert on the body too.

## `Order(clause.Expr{...})` is silently dropped

GORM's `Order` switch handles `clause.OrderBy`, `clause.OrderByColumn` and `string`, with **no
default branch**, so a bare `clause.Expr` adds no ORDER BY at all. With `LIMIT 1` the query
degrades into the arbitrary index-order pick it was written to replace.

```go
Order(clause.OrderBy{Expression: gorm.Expr("ST_Distance(...) ...", args...)})  // right
```

`gorm.Expr` returns a `clause.Expr`, so it hits the same hole. Sweep with
`grep -rn "Order(clause\.Expr\|Order(gorm\.Expr" --include=*.go`. Confirm with a DryRun session
rather than by reading the code.

## Scanning several aggregates into a struct yields zeros

```go
db.Table("x").Select("COUNT(*) AS total, SUM(...) AS additional").Scan(&out) // both 0
```

No error, no log. `Scan(&someInt)` for a **single** aggregate works, which is what makes the
pattern look safe. Use a positional `Row().Scan(&a, &b)` instead. Symptom: SQL that is provably
right against the test database returns zeros through GORM.

## Chain `Scan` mis-reads a single BLOB column

Chain `Scan(&dest)` is built for "result set into a struct or slice of structs", not "one row's
one BLOB into a `[]byte`". A populated column gives a scan error; a NULL column gives
`converting NULL to uint8 is unsupported`, which would otherwise have been silent. Use
`Row().Scan(&cells)`.

## PATCH handlers silently drop unknown fields

`c.BodyParser` uses `encoding/json` without `DisallowUnknownFields`, so a field the client sends
that the `*Request` struct lacks is dropped. The handler returns `{"ret":0,"status":"Success"}`
and the database is untouched.

**When a moderator reports "it says it saved but nothing changed" on a group setting, check the
request struct has a field for it before anything else.** This has bitten at least three times
(group profile picture, microvolunteering options, and post visibility, which never had a field
at all). The UI often hides it by recomputing its display from local state after the save.

## Spatial geometries are lng/lat degrees mislabelled as SRID 3857

`rippling_reach.polygon`, `outer_bound`, `messages_spatial.point` and friends are declared
SRID 3857 but store raw lng/lat degrees. `ST_Distance` on them as stored returns coordinate
degrees, which are anisotropic and cannot be converted to miles. Re-tag to a geographic SRS:

```sql
ST_Distance(ST_SRID(polygon, 4326), ST_SRID(POINT(lng, lat), 4326))  -- metres
```

**Do not add `ST_SwapXY`.** MySQL documents 4326 as latitude-first, so wrapping both sides looks
obviously correct, but measured on our server `ST_Distance` under 4326 reads X as longitude,
matching the stored order. Swapping reinterprets UK coordinates as sitting near the equator: a
~35% error, big enough to matter and small enough to look plausible in aggregate.

Run this control before trusting any 4326 distance. London (-0.1278, 51.5074) to Birmingham
(-1.8904, 52.4862) is 101.6 miles: correct form gives 101.18, with `ST_SwapXY` gives 138.74.

## The spatial mock answers every path, including ones it does not know

`test/main_test.go`'s `ensureSpatialMock()` sets `SPATIAL_KNN_URL` process-wide (not
`t.Setenv`) and its handler ends in a catch-all returning `{"results":[],"ids":[]}` with
HTTP 200. Every later test in the package talks to the mock, and an unrecognised path gets a
plausible body rather than an error. A test that appears to exercise a real spatial call may be
reading 23 bytes of empty JSON. If a spatial result looks empty-but-valid, check the mock knows
the path.

## Any join to `messages_groups` fans out, and DISTINCT does not fix it

A rippled post has one row per receiving group, so a join returns one row per group. `SELECT
DISTINCT` only drops rows identical in every selected column, so a per-group column in the
select list defeats it entirely. Use `GROUP BY m.id`, or `AND mg.rippled_in = 0` for origin rows
only, and `COUNT(DISTINCT messages.id)` rather than `COUNT(*)`. See
`.claude/rules/rippling.md`.

## See also

- `.claude/rules/laravel-batch-traps.md` - JSON null casting, in-place foreign keys.
- `.claude/rules/rippling.md` - one post, many group rows.
- `docs/developers/reference/rippling-algorithm.md` - what the reach geometry means.
