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

**When anyone reports "it says it saved but nothing changed" - on a group setting, a member
setting, anything - check the request struct has a field for it before anything else.** This has
bitten at least four times: group profile picture, microvolunteering options, post visibility, and
`users.chatmodstatus`, the "Fully moderated" control that holds a member's chat for review. None
of them had a field at all. The UI often hides it by recomputing its display from local state
after the save, or by refetching a value the save never changed.

Two things make this worse than a dropped write. A field the struct lacks cannot be granted by
adding a permission check later - it is invisible to the handler - so the gap survives security
review. And where the same column is written elsewhere in one direction only (chatmodstatus was
set to `Unmoderated` by two other code paths and to nothing by any), the feature degrades one
way and looks merely unused rather than broken.

Worth grepping periodically: pull the keys every caller passes to the store's `edit()` and
compare them against the `*Request` struct's json tags. Keys nested under `settings` are fine -
that field is accepted and merged.

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

## Reads and writes go to different servers

Reads are split away from the primary, so a write followed by a read-back can land on a replica
that has not caught up:

- A newly created post read its own id back with a **select for the maximum id**, which on a
  replica returned somebody else's post. Members got each other's offers. Use the id the insert
  returns.
- Moderator setting toggles "don't stick" for the same reason: the save succeeds and the
  immediate read-back shows the old value.

Never confirm a write by reading it again unless you have pinned the read to the primary.

## A 5xx on a slow query is ten slow queries

The web and ModTools clients retry every 5xx up to ten times (`useFetchRetry`, with a
one-second-per-attempt delay), and a query that hit `MAX_EXECUTION_TIME` will hit it again
on the retry. So a handler that turns a 20-second abort into a 500 has multiplied it by ten
on the read node, one copy after another, for a request that was never going to succeed.
Found on the ModTools member search: one moderator's single term appears 17 times in a
week's 500s.

Where the cause is the request itself - a search too broad for its scope - answer a 4xx
with a message that says what to change; the client parses the body of a 4xx and does not
retry it. Keep the 5xx where a retry can genuinely succeed, such as a queue listing
aborted by a slow replica (Discourse 10037). The gateway's own 504 is the one 5xx the
client retries once only, for the same reason.

## A request context that only cancels at shutdown

The framework request context is not cancelled when the client or the gateway goes away; it ends
only when the process shuts down. Work handed that context therefore keeps running after nobody
is waiting for it, so a timed-out request still costs the database everything it was going to.

## Enum migrations follow production's physical order

Enum values have a physical order, and the development order had already diverged from
production. Author any enum migration from production's order, or values shift underneath stored
rows.

## A swallowed `Scan` error becomes a confident wrong answer

The login paths run a raw query, `Scan` into a struct, and then branch on the id being zero
without ever checking the error. When the database is unreachable the scan fails, the id stays
zero, and a lookup failure is reported to the member as **"We don't know that email address."**
They try their correct password during an outage and are told their account does not exist.

Capture the result and return a retryable error when it failed. This is a shape, not one site:
grep for `db.Raw(...).Scan(&x)` followed by a test on a zero value.

## Any join to `messages_groups` fans out, and DISTINCT does not fix it

A rippled post has one row per receiving group, so a join returns one row per group. `SELECT
DISTINCT` only drops rows identical in every selected column, so a per-group column in the
select list defeats it entirely. Use `GROUP BY m.id`, or `AND mg.rippled_in = 0` for origin rows
only, and `COUNT(DISTINCT messages.id)` rather than `COUNT(*)`. See
`.claude/rules/rippling.md`.

## The chat list merges its rows by id, so a duplicated row is invisible

`ListChatRooms` runs a second query and folds its rows into the outer list with
`if chat1.ID == chat.ID`. A row returned twice is applied to the same entry twice, with the same
values, so nothing about the API response changes. That has two consequences.

The harmless one: dropping `SELECT DISTINCT` from that query cannot alter what any endpoint
returns. The costly one: **no endpoint test can detect a join in it that starts fanning out.** A
test that lists chats and asserts no duplicate ids passes whatever the SQL does - it is testing
the merge loop, not the query. One was written here, passed against a deliberately broken join,
and had to be thrown away.

Assert on the query's own row count instead. `chat.ChatRoomListFrom(idlist)` exists so a test can
run `SELECT COUNT(*)` over the real join list with every fan-out-capable table stacked; see
`test/chatroom_joins_test.go`.

## A DISTINCT over 1:1 joins removes nothing and is not free

The mirror of the `messages_groups` note above. Where that one is a DISTINCT that cannot do the
job asked of it, this is a DISTINCT with no job at all: if every join is on a primary key, on
`id = (SELECT ... LIMIT 1)`, or on a derived table cut to `rn = 1`, no row can be duplicated and
the keyword only buys a temporary table and a sort. On the chat room list - ~20 wide columns
including two `JSON_EXTRACT`s and several correlated `COUNT(*)`s - that was 0.39 cores of db3
continuously, 926,265 calls a day, and 621ms against 86ms over 15 members.

Check each `SELECT DISTINCT` against its own join list rather than assuming either way. An equal
row count proves a DISTINCT removed nothing, because DISTINCT can only ever remove.

## A cache key must carry every filter the request can send

`serve()` in `iznik-server-go/ux/ux.go` caches each answer for ten minutes under
`name|start|end|flow|device|signed_in`. A filter missing from the key serves the first caller's
answer to everyone who asks the same dates for ten minutes, whatever they asked for, with a 200 and
the right shape. The key is built from the values the request **sent**, including a filter that
read ignores, or one caller's list of ignored filters reaches the next. Add a part to the key in the
same commit as the parameter. `TestUxFilterIsPartOfTheCacheKey` fails when one is missing.
## `c.IP()` is whatever the client wrote in `X-Forwarded-For`

`main.go` sets `ProxyHeader: "X-Forwarded-For"` and does not validate it, so Fiber v2.52's
`c.IP()` returns the header **as the client sent it**: any leading entries, in one line or several.
haproxy's `option forwardfor` only adds its own line after them. A client can choose `c.IP()` with
one header, so anything that decides from it can be dodged by every client that sends its own, and
a gate that falls open on an unknown address most of all: it opens, with no error and no log.

Use `misc.ClientAddress(c)`. It is `X-Real-IP`, which haproxy sets from the peer address with
`set-header`, replacing whatever the client sent, or the socket peer when there is none (direct
access, tests). The `clientlog` rate limit counts on it and the email job page gate decides on it.
`misc.getClientIP` takes the leading `X-Forwarded-For` entry on purpose, for log lines only.
`TestJobGate_AForgedForwardedForIsIgnored` and `TestClientAddress_NeverTakesTheLeadingForwardedFor`
pin it. Production sends `X-Real-IP` on every request: all 1,089 `GET /apiv2/job/<id>` header lines
in Loki for 9 to 16 October 2026 had it, equal to their single `X-Forwarded-For` entry.

## Asking `WhoAmI` turns a stale login into a 401

`auth.WhoAmI` marks the request with `c.Locals("authUsed")`, and when the token's session has gone
the auth middleware then answers **401 "JWT for invalid user or session"** over whatever the
handler returned. On a public endpoint that only wants to know whether someone is signed in, that
gives a member with an old login an error for a page that never needed them. `BaseAPI` throws on
the 401 and does not retry without the token, so the email job page would have told them the job
was no longer available.

Set `c.Locals("skipPostAuthCheck", true)` before asking, as `job.gate` does for `GetJob`, and a
stale JWT reads as signed in, because it still names its member. A persistent token
(`Authorization2`) is checked against the sessions table, so one whose session has gone reads as
not signed in. `TestJobGate_AStaleSessionStillGetsTheJob` and
`TestJobGate_APersistentTokenCountsAsSignedIn` check both.

## See also

- `.claude/rules/laravel-batch-traps.md` - JSON null casting, in-place foreign keys.
- `.claude/rules/rippling.md` - one post, many group rows.
- `docs/developers/reference/rippling-algorithm.md` - what the reach geometry means.
