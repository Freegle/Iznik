# Rippling score-ordering in the unified digest

Date: 2026-06-22

## Problem

The `/rippling` page's "Digest preview" (inbound) mode ranks posts a member would
see by a **rippling score**, but the actual unified digest email does not — it
emits posts strictly chronologically (`ORDER BY messages_groups.arrival ASC`).
The scoring/ordering algorithm exists only on the page, never in the digest the
member receives. This spec brings the same score-ordering into the unified digest.

## Reference algorithm (source of truth)

`iznik-routing-go/digest_simulator.go::scoreDigestPost` (lines ~407-436). Each
post gets four component scores in `[0,1]` combined as a weighted sum:

```
close  = clamp(1 - driveMin / maxMinutes, 0, 1)          # nearer = higher
fresh  = clamp(1 - ageH    / windowH,    0, 1)            # newer  = higher
budget = exp( -(views + 3*replies) / max(ageH, 1) / (budgetDecay/12) )   # fewer eyeballs/hr = higher
anchor = homeGroup ? 1 : 0                                # post from member's home group
total  = wClose*close + wFresh*fresh + wBudget*budget + wAnchor*anchor
```

Posts are sorted by `total` descending. The `/rippling` Explorer sends
`wClose=1, wFresh=0, wBudget=1, wAnchor=0`, `cap=1000`, `budgetDecay=25`,
`windowH=24`. Engagement inputs:

- `views`   = `SELECT COALESCE(SUM(ml.count),0) FROM messages_likes ml WHERE ml.msgid=? AND ml.type='View'`
- `replies` = `SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid=? AND cm.type='Interested' AND cm.reviewrejected=0 AND cm.reviewrequired=0`

## Key constraint

The unified digest is mass mail (potentially millions of recipient-sends per
run). The reference `close` term needs drive-minutes from a full Dijkstra
isochrone computed per recipient in the routing server — far too expensive to run
inline at digest scale. So the digest **approximates** the closeness term with a
straight-line (haversine) distance, normalised by the post's reach radius. This
is a deliberate performance approximation and MUST be commented as such in the
code.

## Design

### New unit: `DigestPostScorer`

`iznik-batch/app/Services/Ripple/DigestPostScorer.php` — a small, single-purpose,
**pure** class (no DB, no I/O) mirroring `scoreDigestPost`. Pure so it is
unit-testable in isolation against the Go reference values.

```php
score(
    float $distanceMetres,   // haversine(recipient -> post origin)
    float $reachRadius,      // post reach extent in metres (denominator for closeness)
    float $ageH,
    int $views,
    int $replies,
    bool $homeGroup,
    array $weights,          // ['close'=>, 'fresh'=>, 'budget'=>, 'anchor'=>]
    array $env               // ['window_hours'=>, 'budget_decay'=>]
): array                     // ['close'=>, 'fresh'=>, 'budget'=>, 'anchor'=>, 'total'=>]
```

- `close = clamp(1 - distanceMetres/reachRadius, 0, 1)`. **Approximation:**
  haversine distance stands in for drive-minutes; reachRadius stands in for the
  drive-isochrone edge. Commented in-code with a pointer to
  `iznik-routing-go/digest_simulator.go`.
- `fresh`, `budget`, `anchor`, `total` exactly as the Go reference, including the
  `max(ageH, 1)` age clamp and the `budgetDecay/12` rate-scale conversion.

### Config

A `config/digest.php` block (created/extended), all overridable via env so
weights can be retuned without a deploy:

```php
'score' => [
    'weights'             => ['close' => 1.0, 'fresh' => 0.0, 'budget' => 1.0, 'anchor' => 0.0],
    'window_hours'        => 24,
    'budget_decay'        => 25,
    'default_reach_metres'=> 30000, // ~30km, the 30-min drive-isochrone analogue; used for posts with no rippling_reach row
],
```

### `UnifiedDigestService` changes (daily mode, `getPostsForUser`)

1. **Engagement columns.** Add `views` and `replies` to the post fetch via the two
   correlated subqueries above (matching the Go query verbatim).
2. **Reach radius per post.** Once per digest run, per post, compute
   `reachRadius` = the maximum haversine distance from the post origin to its
   `rippling_reach` polygon boundary vertices. Cached in a `msgid -> radius` map
   so it is computed once, not per recipient. Posts with **no** `rippling_reach`
   row fall back to `score.default_reach_metres`.
3. **Score + sort.** For each recipient, score every **available** post
   (`!has_outcome`) with `DigestPostScorer` using the recipient's lat/lng, then
   sort available posts by `total` DESC — replacing `ORDER BY arrival ASC` for
   that section. `deduplicatePosts()` runs after the sort, so the first-seen/kept
   representative of a cross-post is now the highest-scoring one.
4. **Completed section unchanged.** The "came and went" / completed posts stay
   chronological and remain at the end. Final display order is therefore
   `available (by score) -> completed`, matching `/rippling`'s
   `active (by score) -> completed`.

### `anchor` term — not yet implemented

Default weight is `0.0`, so anchor does not affect default ordering. It ships as
a documented stub: `$homeGroup` is passed as `false` with an in-code comment
stating the home-group (location-enclosing group) determination is not yet done
and pointing to `/rippling` (`digest_simulator.go` `homeGroups` logic) for the
intended logic. No dead/wrong logic shipped.

### Immediate mode — unchanged

`getGroupMessagesSinceCursor()` stays chronological (`arrival ASC, msgid ASC`).
Scoring is a daily-roll-up concern; immediate mode is single-group, near-real-time
delivery where chronological order is correct.

## Testing (TDD)

- `tests/Unit/Services/Ripple/DigestPostScorerTest.php` — component and total
  scores match the Go `scoreDigestPost` for shared fixtures: a near/new/unseen
  post scoring high; a far/old/heavily-viewed post scoring low; the `ageH < 1`
  clamp; closeness clamped to 0 beyond reach radius; default-weights total. Pure,
  no DB.
- `tests/Unit/Services/UnifiedDigestServiceTest.php` — given a fixed pool, prove
  available posts emerge in score order (not arrival order), completed posts stay
  last, and a post outside its reach radius still scores (closeness 0) rather than
  erroring.

## Out of scope

- Per-recipient Dijkstra/drive-time fidelity (the whole reason for the haversine
  approximation).
- Home-group anchor resolution (stubbed, see above).
- Immediate-mode ordering changes.
- Any change to the `/rippling` page itself.
