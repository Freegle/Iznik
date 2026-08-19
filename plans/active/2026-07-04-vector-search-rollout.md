# Vector Search Full Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **All build agents must be Opus-class, not Fable** (orchestrator constraint from Edward).

**Goal:** Make vector (semantic) search THE search mechanism everywhere, use it for all "relevant post" recommendations (similar-posts widget, wanted→offer matching at post time, digest relevance ranking), instrument whether recommendations are worth showing, and retire the entire legacy keyword-index machinery (`words`, `messages_index`, `items_index`, `words_cache`, soundex/typo cascade, synonym crowd-sourcing).

**Architecture:** The prototype (PR #573) is already live in production: `nomic-embed-text-v1.5` @ 256 dims in `messages_embeddings`, an in-memory Go store (`embedding.Global`) refreshed every 2 min, a hybrid vector+keyword `/message/search/:term?searchmode=vector` path, an embedding sidecar container, and a Laravel `embeddings:generate` cron every 5 min. This plan (1) flips the server default so every caller gets hybrid vector search, and fixes the known 3.8s store-reload slow query first; (2) adds a "More like this nearby" strip on the message page powered by stored embeddings, with impression/click/reply funnel instrumentation and a 10% deterministic holdout; (3) shows matching OFFERs during and after WANTED composition (async, never blocking the posting flow); (4) retires the V1 PHP "Relevant" email and folds personal relevance into the existing what's-new digest as a sort-ranking signal; (5) removes the keyword leg entirely (pure vector + in-memory lexical guarantee) and drops the legacy tables.

**Cross-cutting requirements (Edward, 2026-07-04):**
- **Feature flags on every behaviour change** (env-var killswitches, `RIPPLE_ENABLED` precedent; default ON in code, `=off`/`=keyword` disables without a deploy): `VECTOR_SEARCH_DEFAULT` (Phase 1), `FEATURE_SIMILAR_POSTS` (Phase 2), `FEATURE_WANTED_MATCH` (Phase 3), `FEATURE_DIGEST_RELEVANCE` (Phase 4, Laravel — this one defaults OFF until enabled). Phase 5 (retirement) is the only unflaggable step and merges last, after the flags have proven the features in prod.
- **Recommendations must respect rippling REACH**: never recommend a post the viewer cannot actually reply to. A post's `rippling_reach` polygon may not cover the viewer's location, and the chat reply gate (`iznik-server-go/chat/chatmessage.go:430-445`) will block the reply. Candidate filtering in Phases 2 and 3 reuses that exact eligibility logic (extract a shared helper; do not duplicate the SQL). Fail-open semantics, matching the existing guards: no reach row → eligible; viewer location unknown → no filtering.

**Tech Stack:** Go (fiber, gorm) in `iznik-server-go`; Nuxt3/Vue3 + Pinia + bootstrap-vue-next in `iznik-nuxt3` (+ `iznik-nuxt3/modtools`); Laravel in `iznik-batch`; MySQL/Percona; existing embedding sidecar (Node/ONNX).

**Worktree:** `/home/edward/FreegleDocker-vector-search` (status API `http://localhost:12021`, site `http://freegle-dev-live.localhost:12024`). Each phase = its own feature branch + PR. NEVER commit to master. NEVER touch main-checkout containers.

---

## Research references (read these before starting a phase)

Scratchpad research digests (session-local copies; the facts are restated in-plan where needed):
- Prototype architecture: `iznik-server-go/embedding/store.go` (Entry holds Msgid, Groupid, Msgtype, Lat, Lng, Subject, Arrival, SubjectVec, BodyVec), `iznik-server-go/message/vectorsearch.go` (MinVectorScore=0.65 subject tier, MinBodyVectorScore=0.75 body tier, keyword boost ×0.3, VectorStats Loki telemetry), `iznik-server-go/message/search_hybrid.go`, `iznik-server-go/message/message.go:1401-1601` (Search handler), `docker/embedding-sidecar/`, `iznik-batch/app/Services/EmbeddingService.php`, `plans/active/vector-search-poc.md`.
- Legacy map: `iznik-batch/app/Services/MessageSearchService.php` (live index maintenance), `iznik-batch/routes/console.php:797-802` (messages:deindex daily 01:00) and `:1128-1136` (messages:update-index every 30 min — **the "NOT YET ENABLED" header comment is wrong; the schedule IS active**), `iznik-server-go/message/search.go` (GetWordsExact/Starts/Typo/Sounds), `iznik-server/include/misc/Search.php`, `iznik-server/include/mail/Relevant.php`, tables `words`/`words_cache`/`messages_index`/`items_index`.
- Keep list (do NOT retire): `search_history` (Stats.php:231-244, 672-684; User.php:5242 GDPR; VW_Essex_Searches), `users_searches` (saved-search UI), `damlevlim()` function (email-domain typo correction in domain.go/domains.php), `microactions` table itself, `items`/`messages_items`.
- Instrumentation patterns: `messages_likes` (pageview + source columns; Go `message.go:4530` handleView writes `source=COALESCE(?,source)`; `markseen.go:26` MarkSeen writes pageview=0, NO source today), `pages/message/[id].vue:105` already passes `$route.query.src` as view source, `OurMessage.vue:149-153` viewSource prop, digest click-by-position pattern (`emailtracking.go:1386` + `ModSysAdminDigestClicks.vue`), scroll-depth pattern (`browse/scroll.go` + `ModSysAdminBrowseScroll.vue`).

**Worktree test commands** (status API on :12021):
- Go: `curl -s -X POST http://localhost:12021/api/tests/go` then poll `GET /api/tests/go/status`. **CAVEAT: the worktree apiv2 container is NOT auto-synced. Before running, `docker cp` every changed Go file into `freegle-vector-search-apiv2:/app/<relpath>`, and `docker exec freegle-vector-search-apiv2 sh -c 'cd /app && go build ./...'` to confirm it compiles.**
- Laravel: `curl -s -X POST http://localhost:12021/api/tests/laravel -H 'Content-Type: application/json' -d '{"filter":"<MethodSubstring>"}'` (filter matches METHOD names). **Do NOT `docker cp` into `freegle-vector-search-batch` — the batch container BIND-MOUNTS `./iznik-batch` at `/var/www/html`, so host edits are already live, and docker cp onto a bind mount triggers a known file-sync race that can DELETE host files** (see the 2026-07-04 autoapprove session gotcha: file-sync.sh + inotify self-sync loop; fixed only on feature/autoapprove-delay, NOT in this worktree). If files under `iznik-batch/` ever vanish while you work, that race is why — re-create them and avoid any docker cp/exec-rm touching that path. Full suite before any push.
- Vitest: `curl -s -X POST http://localhost:12021/api/tests/vitest -d '{"filter":"<spec>"}'` . `docker cp` changed specs/components into `freegle-vector-search-modtools-dev-local:/app/...` first (the runner container). File deletes do NOT propagate — if you delete a spec, also `docker exec ... rm` it in the container.
- Lint PHP: `docker exec freegle-vector-search-batch php -l <container path>`. Lint JS: `docker exec freegle-vector-search-modtools-dev-local sh -c 'cd /app && npx eslint --fix <files>'`.

**Git discipline per phase:** `cd /home/edward/FreegleDocker-vector-search && git fetch origin && git checkout -B <branch> <base>` where `<base>` is `origin/master` (Phases 1, 2, 4) or the named dependency branch (Phase 3 bases on Phase 2's branch; Phase 5 bases on Phase 1's branch). Commit with `git -c user.name='edwh' -c user.email='edward@ehibbert.org.uk' commit`. `git add` by explicit pathspec. Push only after the full relevant local suites are green. One PR per phase, PR body: Summary / Code Quality Review / Future Improvements / Test Plan, no AI attribution. PRs that depend on another PR must say "Merge after #N" at the top of the body.

---

# PHASE 1 — Vector becomes THE search (branch `feature/vector-search-default`, base `origin/master`)

Outcome: every search (public site, ModTools, apps old and new) runs the hybrid vector path by default; the 3.8s store-reload query is fixed first so the higher call volume is safe; the ModTools toggle disappears; `?searchmode=keyword` remains as a temporary server-side escape hatch (removed in Phase 5).

### Task 1.1: Incremental store refresh (fix the 3.8s Load query)

The current `Store.Load()` (`iznik-server-go/embedding/store.go:55-103`) re-reads ~109k rows including two BLOB columns every 2 minutes — measured at 3811ms avg on prod db3 (see `plans/2026-06-23-prod-slow-query-improvements.md:322-326`). Replace the periodic full reload with a cheap diff: fetch the current open-message id set WITHOUT blobs, drop entries no longer present, fetch blobs only for new msgids.

**Files:**
- Modify: `iznik-server-go/embedding/store.go`
- Test: `iznik-server-go/test/embedding_test.go` (create if absent; check for an existing embedding test file first and extend it instead)

- [ ] **Step 1: Write failing tests** for a new `Store.Refresh()` method. Seed via the test DB (pattern: existing Go tests use `database.DBConn` against `iznik_go_test`; find an existing test that INSERTs into `messages`/`messages_spatial` and copy its fixture helpers). Test cases:

```go
// TestStoreRefreshAddsNewEmbedding: Load() with 1 open embedded message → Count()==1.
// INSERT a second open message + messages_spatial row + messages_embeddings row,
// call Refresh() → Count()==2 and the new msgid is findable via Search with its own vector.
// TestStoreRefreshRemovesClosedMessage: two loaded messages; UPDATE messages_spatial
// SET successful=1 WHERE msgid=?; Refresh() → Count()==1, removed msgid absent.
// TestStoreRefreshNoChange: Refresh() with no DB change keeps Count() identical and
// does not re-fetch blobs (assert via a package-level counter incremented in the
// blob-fetch query path, or simply assert correctness only — counter optional).
```

- [ ] **Step 2: Run tests, verify they fail** (`Refresh` undefined).

- [ ] **Step 3: Implement.** In `store.go`:

```go
// Refresh incrementally reconciles the store against the DB: removes entries
// whose messages are no longer open, adds entries for newly-embedded open
// messages. Falls back to a full Load() if the store is empty.
func (s *Store) Refresh() error {
    if s.Count() == 0 {
        return s.Load()
    }
    db := database.DBConn
    // Cheap: id set only, no blobs. Same predicate as Load().
    var openIds []uint64
    if err := db.Raw(`
        SELECT me.msgid FROM messages_embeddings me
        INNER JOIN messages_spatial ms ON ms.msgid = me.msgid
        WHERE ms.successful = 0 AND ms.promised = 0`).Scan(&openIds).Error; err != nil {
        return fmt.Errorf("refresh id query: %w", err)
    }
    open := make(map[uint64]bool, len(openIds))
    for _, id := range openIds { open[id] = true }

    s.mu.RLock()
    have := make(map[uint64]bool, len(s.entries))
    for i := range s.entries { have[s.entries[i].Msgid] = true }
    s.mu.RUnlock()

    var added []uint64
    for _, id := range openIds { if !have[id] { added = append(added, id) } }

    var newEntries []Entry
    if len(added) > 0 {
        // Same SELECT as Load() but WHERE me.msgid IN (?) — extract the shared
        // row-scan+decodeEntry logic into a helper loadRows(where string, args ...interface{})
        // used by both Load() and Refresh() rather than duplicating the SQL.
    }

    s.mu.Lock()
    kept := make([]Entry, 0, len(s.entries)+len(newEntries))
    for i := range s.entries {
        if open[s.entries[i].Msgid] { kept = append(kept, s.entries[i]) }
    }
    s.entries = append(kept, newEntries...)
    s.mu.Unlock()
    return nil
}
```

Change `StartRefresh` to call `Load()` once at startup and `Refresh()` on each tick.

- [ ] **Step 4: docker cp changed files into `freegle-vector-search-apiv2:/app/`, `go build ./...`, run the Go embedding tests via the status API, verify pass.**

- [ ] **Step 5: Commit** `perf(embedding): incremental store refresh instead of 2-minute full blob reload`.

### Task 1.2: Flip the server default to vector (env-flagged)

**Files:**
- Modify: `iznik-server-go/message/message.go:1481` — default comes from a flag helper: `searchmode := c.Query("searchmode", defaultSearchMode())`
- Create the helper in the `message` package (or wherever Go env flags conventionally live — grep for `os.Getenv` in iznik-server-go first and follow the pattern):

```go
// defaultSearchMode returns the searchmode used when the caller doesn't specify one.
// VECTOR_SEARCH_DEFAULT=keyword is the no-deploy rollback switch for the vector flip;
// both the env var and the ?searchmode param are removed in Phase 5.
func defaultSearchMode() string {
	if os.Getenv("VECTOR_SEARCH_DEFAULT") == "keyword" {
		return "keyword"
	}
	return "vector"
}
```
- Modify: root `docker-compose.yml` apiv2 service environment — document the variable (commented-out line is fine); it is the rollback lever named in the PR body.
- Test: extend the existing search tests in `iznik-server-go/test/` (find the file containing existing `/message/search` tests, likely `messages_test.go`); include a test that with `VECTOR_SEARCH_DEFAULT=keyword` (t.Setenv) the no-param path runs the keyword cascade.

- [ ] **Step 1: Write failing test**: seed the store (`embedding.Global.SetEntries(...)` — exported test helper already exists at `store.go:271`) with one entry whose subject is "Blue sofa" and a known vector; stub the query-embed path? NO — `EmbedQuery` calls the sidecar. Instead exercise the handler with `?searchmode=` ABSENT and assert the response includes the vector-store hit. In the dev/test environment the sidecar container runs, so a live embed works; if the existing test suite has no sidecar, instead assert on the OTHER observable: with store count 0 and no `searchmode`, results still come from the keyword cascade (fallback contract), plus a unit-level assertion that `c.Query("searchmode", ...)` default is "vector" via a direct handler test with a store entry seeded using a vector produced by `embedding.EmbedQuery` against the test sidecar. Check how existing vector tests in the repo seed vectors (grep `SetEntries` in `iznik-server-go/test/`) and follow that pattern exactly.
- [ ] **Step 2: Run, verify fails** (default is still keyword).
- [ ] **Step 3: Implement the helper and wire it in.** Update the handler comment: vector is the default; `?searchmode=keyword` is a per-request escape hatch and `VECTOR_SEARCH_DEFAULT=keyword` the global rollback, both scheduled for removal in Phase 5.
- [ ] **Step 4: Run Go search tests green via status API.**
- [ ] **Step 5: Commit** `feat(search): vector-hybrid search is now the default for all callers`.

### Task 1.3: Remove the ModTools toggle (always semantic now)

**Files:**
- Modify: `iznik-nuxt3/modtools/pages/messages/approved/[[id]]/[[term]].vue` (remove the "Semantic search" `b-form-checkbox` at lines 22-29, the `vectorSearchEnabled` state, the `modtoolsSemanticSearch` miscStore persistence at lines 71-73/104/241, and the `?searchmode=vector` URL override at 190-192; the vector branch of `loadMore()` at 284-302 becomes the only branch — but KEEP passing `searchmode: 'vector'` explicitly from `searchMT()` so MT behaviour doesn't depend on the server default)
- Modify: `iznik-nuxt3/stores/message.js:554-586` (`searchMT()` — drop the conditional, always vector path)
- Test: the existing spec for that page/store (grep `modtoolsSemanticSearch` and `searchMT` in `iznik-nuxt3/modtools/**/*.spec.js` and `iznik-nuxt3/**/*.spec.js`)

- [ ] **Step 1: Update/write vitest specs first**: `searchMT` always calls `/message/search` with `searchmode=vector`; the page renders no "Semantic search" checkbox.
- [ ] **Step 2: Run specs, verify the checkbox-absence spec fails** (checkbox still there).
- [ ] **Step 3: Implement removals.** Keep the `matchedon` type "Vector" attachment (`stores/message.js:582-585`) — it is harmless attribution.
- [ ] **Step 4: Vitest green via status API (docker cp specs+components first). ESLint the touched files.**
- [ ] **Step 5: Commit** `chore(modtools): remove semantic-search toggle — vector is always on`.

### Task 1.4: Response-shape parity + public-site regression specs

The public site (`stores/message.js:270-274` → `PostMap.vue`) sends no `searchmode`, so Task 1.2 flips it implicitly. Both handler paths return `[]SearchResult`, but verify and pin that with tests rather than assuming.

**Files:**
- Test (Go): extend search tests: one test that runs the handler default path (vector, store seeded) and asserts the JSON fields consumed by the frontend are present: `id`, `groupid`, `lat`, `lng`, `matchedon` (grep `SearchResult` struct in `iznik-server-go/message/search.go` for the exact field set; assert on those).
- Test (vitest): `stores/message.js` `search()` spec — unchanged call shape (no searchmode param), results stored as before.

- [ ] **Step 1: Write both tests; run; they should PASS immediately** (this is a pin, not a change). If the Go one fails, the vector path is leaking a different shape — fix `VectorSearch`'s SearchResult mapping (in `vectorsearch.go`) until parity holds, and document what differed in the commit message.
- [ ] **Step 2: Commit** `test(search): pin response-shape parity for vector-default search`.

### Task 1.5: Latency benchmark, documented in the PR

- [ ] **Step 1:** With the worktree stack up and embeddings generated for the dev dataset (run `docker exec freegle-vector-search-batch php artisan embeddings:generate --backfill` if `messages_embeddings` is empty), time 20 searches through Traefik: 10 distinct terms ("sofa", "table", "bike", "washing machine", "kids toys", "double bed", "fridge freezer", "laptop", "garden chairs", "pram"), 2 runs each (2nd run exercises the LRU query cache):

```bash
for t in sofa table bike "washing machine" "kids toys" "double bed" "fridge freezer" laptop "garden chairs" pram; do
  for i in 1 2; do
    curl -s -o /dev/null -w "%{time_total} $t\n" \
      "http://freegle-dev-live.localhost:12024/apiv2/message/search/$(python3 - <<<print 2>/dev/null || echo "$t" | sed 's/ /%20/g')?messagetype=Offer"
  done
done
```

(Use plain `sed` URL-encoding; no python dependency.) Also pull the `embed_ms`/`store_ms`/`total_ms` VectorStats lines from `docker logs freegle-vector-search-apiv2 | grep vector_search | tail -40`.

- [ ] **Step 2:** Record min/median/max total and embed_ms cold vs cached in a `## Latency` section of the PR body. **Decision gate for Phase 3:** if median end-to-end < 500ms locally, in-flow compose matching is viable (it is async regardless, so this gate only affects whether the panel is worth rendering mid-flow; expected result: comfortably under).

### Task 1.6: Phase 1 finish

- [ ] Full Go suite + full vitest suite via status API — green. Fix anything red; never dismiss failures.
- [ ] `superpowers:requesting-code-review` pass on the phase diff; fix findings.
- [ ] Push branch, open PR titled `feat(search): vector search is now the default everywhere`. PR body includes the latency table and a rollback note (`?searchmode=keyword` and reverting the one-line default).

---

# PHASE 2 — "More like this nearby" + measurement (branch `feature/similar-posts`, base `origin/master`)

Outcome: the message page shows a horizontal strip of genuinely similar open posts of the same type nearby, computed from stored embeddings (no query-embed call, so ~in-memory-cosine fast); impressions, clicks, and downstream replies are measurable; a 10% deterministic holdout gives a causal read on "was it worth it".

**UX spec (build exactly this):**
- Location: `pages/message/[id].vue`, directly below the message card in the centre column.
- Component: `SimilarPosts.vue` renders a heading "More like this nearby" and a horizontally scrollable row of compact cards (`MessageMatchCard.vue`): square thumbnail (first attachment or the standard placeholder used by `MessageAttachments`/`OurMessage` — reuse whatever placeholder pattern `MyMessage`/`OurMessage` use), item name (subject stripped of type/location prefix — the API returns full subject; display it truncated to 2 lines with CSS `-webkit-line-clamp`), and distance ("2.3 miles" — compute from message lat/lng vs the source message's lat/lng, using the existing distance helper if one exists in composables, else Haversine inline).
- Behaviour: lazy — the component fetches only when scrolled into view (use the same `v-observe-visibility` directive OurMessage uses); renders nothing at all (no heading, no reserved space) unless ≥ 3 results; each card links to `/message/<id>?src=similar_posts` (same tab); horizontal scroll with touch swipe on mobile and visible overflow affordance (partial card peeking) — use plain CSS `overflow-x: auto; scroll-snap-type: x mandatory` rather than a carousel library.
- Holdout: logged-in users with `myid % 10 === 0` never see the strip (and it never fetches). Anonymous users always see it (excluded from cohort analysis).

### Task 2.1: Go endpoint GET `/message/:id/similar`

**Files:**
- Create: `iznik-server-go/message/similar.go`
- Modify: `iznik-server-go/router/routes.go` (add route next to the search route at :857-866)
- Modify: `iznik-server-go/embedding/store.go` (add `Fromuser uint64` to `Entry`, to the Load/Refresh SELECT — `m.fromuser` — and to `VectorSearchResult`; needed to exclude the same poster)
- Test: `iznik-server-go/test/similar_test.go`

- [ ] **Step 1: Failing tests** (seed `embedding.Global.SetEntries` with hand-built vectors — unit vectors make cosine trivially controllable: e.g. source subject vec `[1,0,0,...]`, a near match `[0.9,0.435,0,...]` normalized, an unrelated `[0,1,0,...]`):

```go
// TestSimilarReturnsNearMatchesSameType: source=Offer sofa; store holds a 0.9-cos Offer
// (returned), a 0.9-cos Wanted (excluded: type mismatch), a 0.3-cos Offer (excluded:
// below MinSimilarScore), an identical-vector Offer from the SAME fromuser (excluded),
// and the source msgid itself (excluded). Assert exactly one result with the right id,
// and that the response items carry {id, score, lat, lng}.
// TestSimilarMessageNotInStore: :id not in store but present in messages_embeddings →
// endpoint falls back to a DB read of subject_embedding and still returns matches.
// TestSimilarNoEmbedding: :id has no embedding row → 200 with empty list (never 500).
// TestSimilarFlagOff: FEATURE_SIMILAR_POSTS=off (t.Setenv) → 200 with empty list.
// TestSimilarReachFiltered: logged-in viewer with a known location; candidate whose
// rippling_reach polygon does NOT contain that point is excluded; candidate with NO
// reach row is kept (fail-open); anonymous request → no reach filtering at all.
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** `similar.go`:

```go
const MinSimilarScore = 0.60 // exploratory surface: lower than search's 0.65,
                             // high enough to avoid junk. Tune from telemetry.

// GET /message/:id/similar?limit=8
// Returns open posts of the same msgtype, excluding the source message and its
// poster, ranked by subject-embedding cosine. Uses the STORED embedding of the
// source message (no sidecar call): from the in-memory store if the message is
// open, else one indexed-PK read of messages_embeddings.
func Similar(c *fiber.Ctx) error { ... }
```

Flow: flag first — if `FEATURE_SIMILAR_POSTS=off` (env), return an empty 200 immediately (the FE strip then renders nothing; killswitch needs no FE deploy). Parse id + limit (default 8, cap 20); find source entry in `embedding.Global` (add a small exported `FindByMsgid(id) *Entry` accessor with RLock); if absent, `SELECT subject_embedding FROM messages_embeddings WHERE msgid = ?` + decode (reuse `decodeFloats`; export a tiny `DecodeVector([]byte) ([]float32, error)` from the embedding package rather than duplicating); also read the source msgtype+fromuser+lat/lng from `messages` /`messages_spatial` in that fallback. Call `embedding.Global.Search(vec, limit*3, srcType, nil, 0,0,0,0)`, then filter out `Msgid==src`, `Fromuser==srcFromuser`, `SubjectCos < MinSimilarScore`. **Reach filter**: if the caller is logged in and their location resolves (same resolution the chat reply gate uses), drop candidates whose `rippling_reach` polygon exists and does not contain the viewer's point — extract the reply gate's resolution+containment logic (`chat/chatmessage.go:430-445`) into a shared exported helper (e.g. `chat.ReachEligible(msgid, lat, lng)` or a new small package if import cycles bite) and call it here; fail-open when no reach row / unknown location. Truncate to limit. Return `[]{id, score, lat, lng, groupid}`. Register route: `message.Group.Get("/message/:id/similar", message.Similar)` — mirror however the search route is registered in routes.go:857-866, public (no auth), ratelimited the same way.

- [ ] **Step 4: docker cp, go build, Go tests green.**
- [ ] **Step 5: Commit** `feat(api): /message/:id/similar — stored-embedding similarity endpoint`.

### Task 2.2: Impression tagging — `source` on MarkSeen

**Files:**
- Modify: `iznik-server-go/message/markseen.go` (request struct gains `Source *string`; the INSERT writes it on fresh rows only, exactly like pageview=0 semantics at lines 44-52 — never overwrite an existing row's source)
- Modify: `iznik-nuxt3/api/MessageAPI.js` (markSeen gains optional source), `iznik-nuxt3/stores/message.js` (markSeen passthrough)
- Test: Go markseen test (extend existing `TestMarkSeen*` — grep test/ for MarkSeen), vitest MessageAPI spec.

- [ ] **Step 1: Failing Go test**: markSeen with source `similar_posts` on a fresh (msgid,user) → row has pageview=0 AND source='similar_posts'; then a real View (handleView, no source) → pageview upgraded to 1, source PRESERVED (COALESCE already does this — pin it); markSeen again with a different source → source unchanged (first-touch wins).
- [ ] **Step 2: Run, fail.**
- [ ] **Step 3: Implement Go + FE param.**
- [ ] **Step 4: Go + vitest green.**
- [ ] **Step 5: Commit** `feat(tracking): source-tagged impressions via markseen`.

### Task 2.3: `MessageMatchCard.vue` + `SimilarPosts.vue`

**Files:**
- Create: `iznik-nuxt3/components/MessageMatchCard.vue` (pure presentational: props `id`, `subject`, `attachment` (thumb url or null), `distanceKm` (number or null), `srcTag` (string) → renders `<nuxt-link :to="'/message/' + id + '?src=' + srcTag">`; NO store access — props only, per project convention of passing ids/simple props)
- Create: `iznik-nuxt3/components/SimilarPosts.vue` (props: `msgid`, `lat`, `lng`; owns fetch + holdout + impression logic)
- Create: `iznik-nuxt3/api/MessageAPI.js` addition: `similar(id, limit)` → GET `/message/${id}/similar`
- Modify: `iznik-nuxt3/pages/message/[id].vue` (insert `<SimilarPosts>` below the message component, only when the message is loaded and open)
- Test: `iznik-nuxt3/components/__tests__/SimilarPosts.spec.js` (or wherever component specs live — match the existing spec directory convention, grep for `OurMessage.spec`), plus a MessageMatchCard spec.

- [ ] **Step 1: Failing specs**:

```js
// SimilarPosts.spec.js
// - renders nothing when fewer than 3 results returned
// - renders a card per result (>=3), each linking to /message/<id>?src=similar_posts
// - holdout: with authStore myid = 20 (20 % 10 === 0) it renders nothing AND does not
//   call the similar API
// - on becoming visible, calls messageStore.markSeen(ids, 'similar_posts') exactly once
// - anonymous user (myid null) fetches and renders
```

- [ ] **Step 2: Run, fail.**
- [ ] **Step 3: Implement.** SimilarPosts flow: on visibility (v-observe-visibility, once), if holdout → return; call `api.message.similar(msgid, 8)`; if `< 3` results → render nothing; else fetch the message summaries for thumbnails/subjects via the existing message store fetch-by-ids path (grep how `MessageList`/search results hydrate messages — reuse exactly that; do NOT invent a new fetch), compute distance from props lat/lng, render cards, call `markSeen(ids, 'similar_posts')` once. Heading: "More like this nearby". Strip: `overflow-x:auto` flex row, scroll-snap, cards ~9rem wide, partial next-card peek via container padding.
- [ ] **Step 4: Vitest + eslint green.**
- [ ] **Step 5: Manual validation** (Chrome MCP against `http://freegle-dev-live.localhost:12024`, isolatedContext "vector-search"): open a message page with dev data (ensure ≥3 similar posts exist in dev DB — seed several sofa/table OFFERs via the compose flow or SQL if needed), screenshot the strip, verify mobile viewport (375px) swipes horizontally with no page-level horizontal scroll and no layout shift when the strip loads (it appears below existing content — acceptable; nothing above it moves).
- [ ] **Step 6: Commit** `feat(similar-posts): "More like this nearby" strip on message page`.

### Task 2.4: Sysadmin measurement panel

**Files:**
- Create: `iznik-server-go/recommendations/stats.go` — GET `/modtools/recommendations/stats?days=30` (Support/Admin only — copy the auth guard from the rippling metrics endpoint, grep `rippling/metrics` in routes.go). Returns per-day, per-source (`similar_posts` now; `wanted_match` joins in Phase 3):

```
impressions:  SELECT DATE(timestamp) d, source, COUNT(*) FROM messages_likes
              WHERE type='View' AND pageview=0 AND source IN (...) GROUP BY d, source
clicks:       same WHERE pageview=1
attributed replies: views (pageview=1, source tagged) joined to chat_messages
              (type='Interested', refmsgid=msgid, same userid, cm.date BETWEEN view ts
              AND view ts + INTERVAL 7 DAY) — COUNT(DISTINCT cm.id)
holdout cohort: over the window, for users active on message pages
              (any messages_likes View row), replies-per-user for userid%10=0 vs rest.
```

- Create: `iznik-nuxt3/modtools/components/ModSysAdminRecommendations.vue` + API wrapper + store slice, patterned exactly on `ModSysAdminBrowseScroll.vue`/its API+store (impressions/clicks/CTR per day chart + totals + holdout comparison table).
- Modify: `iznik-nuxt3/modtools/pages/sysadmin/index.vue` — new tab "Recommendations" (append after Rippling; update topTabMap).
- Test: Go stats test (seed likes+chat rows, assert counts incl. the 7-day window boundary and the userid%10 cohort split), vitest for the component (renders rows from mocked store).

- [ ] **Step 1: Failing Go test → Step 2 fail → Step 3 implement → Step 4 green → Step 5 vitest component (fail→implement→green) → Step 6 eslint.**
- [ ] **Step 7: Commit** `feat(modtools): Recommendations sysadmin panel — similar-posts funnel + holdout`.

### Task 2.5: Phase 2 finish

- [ ] Full Go + vitest suites green via status API. `superpowers:requesting-code-review`; fix findings.
- [ ] Push, open PR `feat: similar-posts recommendations with funnel + holdout measurement`. PR body documents: the funnel definitions (impression=strip seen, click=pageview with src, reply=Interested within 7d of tagged view), the holdout rule (`userid % 10 == 0`, anon excluded), and the pre-registered decision criterion: **after 4 weeks live, keep the widget iff CTR ≥ 1% AND holdout comparison shows no reply-rate decrease; expand to browse-expanded-view iff reply-rate lift ≥ 2% relative.** Future improvements: per-position CTR, source→reply propagation instead of window join, browse-feed placement.

---

# PHASE 3 — WANTED → existing OFFERs at post time (branch `feature/wanted-offer-match`, base = Phase 2 branch; PR notes "Merge after Phase 2 PR")

Outcome: someone posting a WANTED sees existing matching OFFERs near them — during compose (async side panel, never blocking) and again on the My Posts landing after submit. Backed by a small dedicated endpoint `/message/matches` that wraps the vector store search with `messagetype=Offer` + bbox, applies **reach eligibility for the poster's chosen location** (a matched OFFER whose rippling reach doesn't cover the wanted-poster's location can't be replied to and must never be shown), and carries its own killswitch (`FEATURE_WANTED_MATCH=off`).

### Task 3.0: Go endpoint GET `/message/matches`

**Files:**
- Create: `iznik-server-go/message/matches.go`
- Modify: `iznik-server-go/router/routes.go` (public route next to the similar route; compose can be pre-login, so no auth requirement)
- Test: `iznik-server-go/test/matches_test.go`

- [ ] **Step 1: Failing tests** (seeded store vectors, same technique as similar_test.go):

```go
// TestMatchesReturnsNearbyOffers: query "sofa" (stub/seed a vector for the query via
//   the test sidecar or by seeding the LRU cache if the tests can't reach a sidecar —
//   follow whatever existing vector search tests do for query embedding), Offer within
//   bbox and cos>=0.65 → returned with {id, score, lat, lng, groupid}.
// TestMatchesExcludesWanteds: same-vector Wanted → excluded.
// TestMatchesExcludesOutOfBox: strong-cos Offer outside ±0.15° bbox → excluded.
// TestMatchesReachFilter: Offer whose rippling_reach polygon does NOT contain the
//   given lat/lng → excluded; Offer with NO reach row → kept (fail-open).
// TestMatchesFlagOff: FEATURE_WANTED_MATCH=off → 200 empty list.
// TestMatchesExcludesOwnPosts: logged-in caller's own Offer → excluded.
```

- [ ] **Step 2: fail → Step 3: implement.** Flow: flag check (`FEATURE_WANTED_MATCH=off` → empty 200); parse `query` (required, trimmed), `lat`/`lng` (required floats), `limit` (default 6, cap 12); `embedding.EmbedQuery(query)` (sidecar + LRU cache); `embedding.Global.Search(vec, limit*3, "Offer", nil, lat-0.15, lng-0.15, lat+0.15, lng+0.15)` (note arg order swlat,swlng,nelat,nelng — check the signature); filter `SubjectCos >= MinVectorScore` (0.65 — search-grade, this is a match claim, not exploration); reach-filter by the GIVEN point using the shared helper extracted in Task 2.1; if `myid > 0` exclude `Fromuser == myid`; truncate; return list.
- [ ] **Step 4: docker cp + go build + Go tests green → Step 5: commit** `feat(api): /message/matches — reach-aware offer matching for wanted composers`.

**UX spec:**
- In-flow: on the location step (`pages/find/whereami.vue`, and mobile `pages/find/mobile/whereami.vue`), once a postcode is chosen (`postcodeSelect` in `useCompose.js:265-307` already yields lat/lng + groups), fetch matches for the draft item title(s); if ≥1 match, render a `WantedMatches.vue` panel under the postcode chooser: heading "Good news — people are offering these near you right now", up to 6 `MessageMatchCard`s (srcTag `wanted_match`), each opening in a **new tab** (`target="_blank"`) so the draft survives. A dismiss ("Not what I'm looking for — keep posting") collapses it. It must never disable or delay the Next button.
- Post-submit: on `pages/myposts.vue`, when arriving with freshly-submitted WANTED ids (router state `ids` + the messages are type Wanted — see `useCompose.js:309-425` freegleIt), show the same panel per submitted wanted (max 1 panel, first wanted) using the item text, heading "While you wait — these offers might match".
- Query: the draft item name (compose store message item text). bbox: ±0.15° around the chosen location (~15km) via the search endpoint's `swlat/swlng/nelat/nelng` params.

### Task 3.1: `WantedMatches.vue`

**Files:**
- Create: `iznik-nuxt3/components/WantedMatches.vue` (props: `query` string, `lat`, `lng`; internal: calls a new `api.message.matches(query, lat, lng, 6)` wrapper in `api/MessageAPI.js` → GET `/message/matches`; hydrates message summaries the same way SimilarPosts does; markSeen(ids, 'wanted_match') on visibility; dismiss state local; cards target=_blank with ?src=wanted_match)
- Test: `WantedMatches.spec.js`: no render when 0 results; renders ≤6 cards with target=_blank and src=wanted_match; dismiss hides; impression call once; never renders a disabled state for parent flow (assert it emits nothing that gates navigation).

- [ ] **Steps: failing specs → implement → vitest+eslint green → commit** `feat(compose): WantedMatches panel component`.

### Task 3.2: Wire into the find flow (desktop + mobile) + My Posts

**Files:**
- Modify: `iznik-nuxt3/pages/find/whereami.vue`, `iznik-nuxt3/pages/find/mobile/whereami.vue` (render `<WantedMatches v-if="postcodeChosen" :query="itemTitle" ...>`; item title from compose store draft messages of type Wanted — grep how the page accesses composeStore for existing draft fields)
- Modify: `iznik-nuxt3/pages/myposts.vue` (if router-state ids resolve to a Wanted just submitted, render the panel once with that item text + the user's location)
- Test: extend the pages' existing specs (grep `whereami.spec` / `myposts.spec`); assert panel presence given a chosen postcode + draft item, and absence in the give (Offer) flow.

- [ ] **Steps: failing specs → implement → vitest+eslint green.**
- [ ] **Manual validation** (Chrome MCP, worktree URL): walk the /find flow with dev data (post a WANTED for "sofa" with several sofa OFFERs seeded): panel appears on whereami within ~1s, Next never blocked, links open new tab, dismiss works, mobile variant at 375px, myposts shows the post-submit panel. Screenshot both.
- [ ] **Commit** `feat(compose): show matching offers during and after wanted posting`.

### Task 3.3: Measurement joins in the panel

- [ ] Extend `recommendations/stats.go` + `ModSysAdminRecommendations.vue` to include source `wanted_match` (the SQL already groups by source — just add it to the IN list and the FE legend). Extra Go test row. Commit `feat(modtools): wanted_match in recommendations stats`.

### Task 3.4: Phase 3 finish

- [ ] Full vitest + Go suites green; code review; push; PR `feat: wanted→offer matching at post time` (body: UX rationale — async panel so posting flow is never gated on search latency; deflection is a success case; decision criterion: after 4 weeks, keep iff ≥0.5% of wanted-composers click a match AND attributed replies > 0; future: track draft-abandon-after-click as explicit deflection metric, offer→wanted direction).

---

# PHASE 4 — Digest relevance ranking + retire the Relevant email (branch `feature/digest-relevance`, base `origin/master`)

> ## ABANDONED 2026-08-19. PR #956 CLOSED, not merged.
>
> The signal does not work. Rather than run the four-week live A/B this phase specifies, the
> branch's own `interests()` + `maxCosine()` were replayed offline against 21 days of real digest
> clicks (2,126 digests, 2,884 click events, 1,291 members, 1,823 usable).
>
> - Clicked post median cosine to its interest set **0.6757**; not-clicked posts in the **same**
>   digest **0.6759**. Within-digest AUC **0.5268** against a shuffle floor of **0.5129**.
> - The **views** arm scored AUC **0.5228**, on the shuffle floor, independently reproducing on
>   clicks the null already recorded on replies in `docs/developers/reference/first-reply.md`
>   ("Post views are NOT a signal", commit `e0160a37c`).
> - The **own-posts** arm, untested until now, scored AUC **0.5377** (n=799): the best number
>   found, still near a coin flip, and in the 0.60-0.75 cosine band where top-1 precision is 11%.
> - 35% of click events had no qualifying interest set at all, so `rank()` would have left those
>   digests unchanged regardless.
>
> The experiment as designed would also not have settled it: the `ranked` arm was conditioned on an
> interest signal firing while the holdout was not, and on **unranked** live digests those two
> populations already click at 4.06% vs 0.74%. "Ranked CTR >= holdout CTR" passes on composition
> alone. See `finding_digest_relevance_signal_measured_null`.
>
> If revived: own open **Wanted** matched cross-type against **Offers**, floored at 0.85, routed
> through the existing matched-posts/scout path, not as a digest-wide reranker. Validate with the
> same offline replay before any live cohort split.
>
> **Effect on Phase 5: none.** Phase 4's only Phase-5 dependency was retiring the V1 Relevant mail,
> and the whole of V1 was removed on 2026-07-09, so that consumer is already gone.

Outcome (per Edward 2026-07-04): similarity-to-previous-items becomes part of the existing **what's-new Unified Digest via sort ranking — NOT a separate mail**. The standalone V1 "Any of these take your fancy?" mail (`iznik-server/include/mail/Relevant.php` + `scripts/cron/relevant.php` — besides search itself, the last hard consumer of `messages_index`) is retired outright. Ranking ships behind `FEATURE_DIGEST_RELEVANCE` (default OFF) with a 10% holdout, and is measured by the EXISTING digest click-by-position dashboard — the digest already records `metadata.post_msgids` order and per-position clicks, so a ranking change is directly observable with zero new tracking.

### Task 4.1: Investigate current digest assembly (no code)

- [ ] Read `iznik-batch/app/Services/UnifiedDigestService.php` DAILY path end-to-end. Document as notes in the service (comment block) before coding: where the per-recipient candidate post list is assembled, what order it currently uses (arrival?), where `metadata.post_msgids` is written (that order IS the measured position), and where a per-recipient ranking hook can go. Ranking applies to the DAILY digest only — immediate digests send posts as they arrive; there is nothing to reorder.

### Task 4.2: `DigestRelevanceService` (TDD)

**Files:**
- Create: `iznik-batch/app/Services/DigestRelevanceService.php`
- Modify: `iznik-batch/config/freegle.php` (add `digest_relevance` flag reading env `FEATURE_DIGEST_RELEVANCE`, default false)
- Test: `iznik-batch/tests/Unit/Services/DigestRelevanceServiceTest.php`

- [ ] **Step 1: Failing tests**:

```php
// test_interest_vectors_from_own_posts_and_views: user has a post (last 60d) and a
//   viewed message (messages_likes type=View, last 30d) — both with rows in
//   messages_embeddings → interests() returns 2 vectors (cap 40, newest first).
// test_rank_orders_by_max_cosine: three candidates with hand-built subject_embedding
//   blobs (256 little-endian float32; helper packs from a PHP float array — MUST
//   round-trip against the Go encoding: little-endian, 1024 bytes) — candidate most
//   similar to any interest vector ranks first; ties broken by arrival desc.
// test_candidates_without_embeddings_rank_last_in_arrival_order.
// test_flag_off_returns_input_order_unchanged.
// test_holdout_user_returns_input_order_unchanged (userid % 10 === 0, flag ON).
// test_user_with_no_interests_returns_input_order_unchanged.
```

- [ ] **Step 2: fail → Step 3: implement.** `interests(int $userid): array` — subject embeddings of (a) the user's own posts from the last 60 days, (b) messages they Viewed (messages_likes type=View) in the last 30 days; single query each joining `messages_embeddings`; unpack blobs with PHP (`unpack('g256', $blob)` — 'g' is little-endian float; VERIFY with a round-trip test against bytes produced the Go way); cap 40 vectors. `rank(int $userid, array $candidateMsgids): array` — flag off OR `$userid % 10 === 0` (holdout, same rule as the widgets) OR no interests → return input unchanged; else fetch candidate embeddings in one query, score = max cosine over interest vectors (plain PHP loop — digest candidate sets are tens of posts, interests ≤ 40: ≤ a few thousand 256-dim dot products, well under 50ms), sort score desc, tiebreak arrival desc, unembedded candidates after embedded ones in arrival order.
- [ ] **Step 4: Laravel tests green via status API → Step 5: commit** `feat(digest): relevance ranking service (vector similarity to user's posts+views)`.

### Task 4.3: Wire into the daily digest

**Files:**
- Modify: `iznik-batch/app/Services/UnifiedDigestService.php` — apply `DigestRelevanceService::rank()` to the per-recipient daily candidate list BEFORE the posts are rendered and BEFORE `metadata.post_msgids` is written (so the click-by-position dashboard measures the ranked order directly).
- Test: extend the UnifiedDigest daily tests — flag ON: recipient with interests gets ranked order in both rendered output and metadata.post_msgids; holdout recipient keeps arrival order; flag OFF (default): byte-identical behaviour to today.

- [ ] **Steps: failing tests → implement → full UnifiedDigest test classes green → render a daily digest to Mailpit (check `./freegle status` for the worktree Mailpit port) and eyeball → commit** `feat(digest): rank daily digest posts by personal relevance (flagged, 10% holdout)`.

### Task 4.4: Cohort split on the digest-positions dashboard

**Files:**
- Modify: `iznik-server-go/emailtracking/emailtracking.go` `DigestClickPositions` (:1386) — add optional `cohort=holdout|ranked` query param: joins the recipient user id already on `email_tracking` (verify the column name by reading the struct/table) and filters `userid % 10 = 0` vs `<> 0`.
- Modify: `iznik-nuxt3/modtools/components/ModSysAdminDigestClicks.vue` + its store/API — cohort selector (All / Ranked / Holdout).
- Test: Go — seeded rows split by cohort produce different curves; vitest — selector wires the param.

- [ ] **Steps: failing tests → implement → suites green → commit** `feat(modtools): cohort split on digest click positions (relevance-ranking experiment)`.

### Task 4.5: Retire the V1 Relevant email

- [ ] Delete `iznik-server/scripts/cron/relevant.php`; add a header comment to `iznik-server/include/mail/Relevant.php`: "RETIRED 2026-07 — replaced by digest relevance ranking (iznik-batch DigestRelevanceService); do not resurrect" (class deletion happens with the rest of V1; the cron file is what runs). Update `iznik-batch/MIGRATION-STATUS.md` (~line 318): "Relevant message matching" → Retired, folded into digest relevance ranking, with date. **PR body must flag: the LIVE crontab entry for relevant.php (not tracked in-repo) needs removing at deploy time — action for Edward.**
- [ ] Full Laravel + Go + vitest suites green. Code review. Push. PR `feat: digest relevance ranking (flagged) + retire V1 relevant email`.

---

# PHASE 5 — Retire the keyword machine (branch `feature/retire-keyword-search`, base = Phase 1 branch; PR notes "Merge after Phases 1 & 4 PRs")

Outcome: pure-vector search with an in-memory lexical guarantee; all keyword-index code and tables gone; microvolunteering SearchTerm challenge retired; nothing left reads `words`/`messages_index`/`items_index`/`words_cache`/`search_terms`.

> ## FEASIBILITY RE-CHECKED 2026-08-19: unblocked, and smaller than written below.
>
> **Unblocked.** Phase 1 is live: `defaultSearchMode()` (`iznik-server-go/message/message.go:459-467`)
> returns `vector` unless `VECTOR_SEARCH_DEFAULT=keyword`. Phase 4 is abandoned but was never a real
> dependency: its only Phase-5 role was retiring the V1 Relevant mail, and **all of V1 was removed
> on 2026-07-09**, so `iznik-server/` no longer exists.
>
> **Stale below.** Every `iznik-server/...` path in Tasks 5.2 and 5.3 is gone. Drop them:
> no `Message.php`/`Item.php` no-oping, no `typeahead()`, no v1 script sweep, no
> `MicroVolunteering.php`/`microvolunteering.php` edits, no `php -l` via the apiv1 container.
> The SearchTerm challenge now lives in `iznik-server-go/microvolunteering/microvolunteering.go`
> plus `iznik-nuxt3/components/MicroVolunteering.vue`, and `search_terms`' v1 populator script is
> already gone with V1.
>
> **What actually remains:**
> 1. **The only real work, Task 5.1.** The keyword index is still read on EVERY default search: the
>    vector path calls `GetWordsExact`/`GetWordsStarts` at `message/message.go:1899-1901`, not just
>    the fallback path at `:1935-1953`. That hybrid leg is what currently guarantees literal
>    matches, so the in-memory lexical tier has to be built and proven BEFORE anything is dropped.
>    This is the whole behavioural risk of the phase; treat it as its own PR.
> 2. Laravel maintenance removal: `MessageSearchService.php`, `DeindexCommand` (`messages:deindex`),
>    `IndexUnindexedCommand` (`messages:update-index`), and both schedule blocks. All still live.
> 3. Go/Vue SearchTerm challenge retirement.
> 4. The drop-tables migration, last, once nothing reads them.
>
> Recommend splitting: PR A = lexical tier + keyword cascade removal (behavioural, needs care);
> PR B = index maintenance + SearchTerm retirement; PR C = drop tables. Do not fold A into C.

### Task 5.1: Pure vector + in-memory exact-match tier (Go)

The hybrid keyword leg (GetWordsExact/Starts) currently guarantees literal matches. Replace that guarantee in-memory: the store already holds every open message's `Subject` (store.go:23).

**Files:**
- Modify: `iznik-server-go/message/vectorsearch.go` — add a lexical tier inside `VectorSearch`: after scoring, any store entry whose subject contains ALL query words (case-insensitive, on the type/group/bbox-filtered set) is guaranteed inclusion, ranked above pure-semantic body-tier hits and interleaved with subject-tier hits by combined score (keep the existing keywordScore ×0.3 boost — it already implements most of this; the change is: an all-words subject match is included even when its cosine < MinVectorScore).
- Modify: `iznik-server-go/message/message.go` Search handler — remove the hybrid goroutine pair, the typo/soundex fallback cascade call sites, and the `searchmode` param entirely (vector path unconditional; keep writing search_history/users_searches; keep VectorStats logging).
- Delete: `iznik-server-go/message/search_hybrid.go`, `iznik-server-go/message/synonyms.go`, and the keyword functions in `iznik-server-go/message/search.go` (GetWordsExact/GetWordsStarts/GetWordsTypo/GetWordsSounds + their SQL; keep whatever else lives in that file — read it first; if the file becomes empty, delete it and fix imports).
- Test: rewrite affected search tests; new tests: `TestSearchLexicalGuarantee` (entry subject "White goods bundle", query "white goods", cosine deliberately low via orthogonal seeded vector → still returned); `TestSearchNoSearchmodeParam` (param ignored/absent → vector path; `?searchmode=keyword` now behaves identically — parameter dropped, not erroring, so old clients keep working); delete typo/soundex tests.

- [ ] **Steps: failing tests → implement → docker cp + go build + full Go suite green → commit** `feat(search)!: pure vector search; keyword cascade removed`.

### Task 5.2: Stop maintaining the index (Laravel + PHP v1)

**Files:**
- Delete: `iznik-batch/app/Services/MessageSearchService.php`, `app/Console/Commands/Message/DeindexCommand.php`, `app/Console/Commands/Message/IndexUnindexedCommand.php`, their tests; remove both schedule blocks (`routes/console.php:797-802` and `:1128-1136` — note the second block's misleading "NOT YET ENABLED" comment; it IS active today).
- Modify (PHP v1, surgical — v1 is near-retired but still deployed; nothing may write dropped tables): `iznik-server/include/message/Message.php` — make `index()` and `deindex()` no-ops with a comment (bodies removed, signature kept: other v1 call sites at :303, :3295, :3302-3321 keep working); remove the two `Search` constructions at :630-631; `iznik-server/include/message/Item.php` — same for its index paths (:18, :57, :69-74, :130-140) and make `typeahead()` return an empty set (endpoint is orphaned — zero frontend callers). Grep `iznik-server/scripts/` for `message_deindex.php` / `message_unindexed.php` / `fixmessageindex` / `fix_deindex` / `fix_rejindex` and delete those scripts.
- Test: Laravel full suite (deleted tests gone, nothing else references the service — grep `MessageSearchService` across iznik-batch); v1 has no CI test suite gating this repo — `php -l` every touched v1 file via the apiv1 container.

- [ ] **Steps: grep-sweep first (`grep -rn "messages_index\|items_index\|words_cache\|\bwords\b" iznik-batch/app iznik-server-go iznik-server/include iznik-server/http` — resolve EVERY hit: delete, no-op, or justify-keep in the commit message) → implement → suites green → commit** `chore(search): remove all keyword-index maintenance (Laravel + v1)`.

### Task 5.3: Retire microvolunteering SearchTerm challenge

**Files:**
- Modify: `iznik-server/include/user/MicroVolunteering.php` — remove `CHALLENGE_SEARCH_TERM` from the challenge-rotation logic (constant can stay for old-data readability; `responseSearchTerm()` at :251 kept for historical API tolerance but never offered); `iznik-server/http/api/microvolunteering.php:63-80` — stop serving that challenge type.
- Modify: `iznik-nuxt3/components/MicroVolunteeringSimilarTerms.vue` — delete component; `iznik-nuxt3/pages/microvolunteering/index.client.vue` + `stores/microvolunteering.js` — remove the SearchTerm branch (grep `SimilarTerms` and `searchterm` case-insensitively across iznik-nuxt3).
- Delete the nightly export: `iznik-server/scripts/cli/search_terms.php` (populates `search_terms` from search_history) and `scripts/cli/search_pairing.php` (offline research export), `scripts/cli/elastic_search.php` (defunct ES experiment, dead already).
- Test: vitest — microvolunteering page spec updated (no SimilarTerms card); existing microvol Go/PHP behaviour untouched otherwise.

- [ ] **Steps: specs → implement → vitest+eslint green → commit** `chore(microvolunteering): retire SearchTerm similarity challenge (fed keyword synonyms; vector makes it moot)`.

### Task 5.4: Drop the tables

**Files:**
- Create: `iznik-batch/database/migrations/2026_07_XX_000001_drop_keyword_search_tables.php` + matching idempotent `*_migration.sql` for prod. Order matters (FKs):

```php
// 1. microactions: drop FKs + columns searchterm1, searchterm2 (schema refs 2633-2634,
//    2656-2657). Guard with Schema::hasColumn.
// 2. DROP VIEW IF EXISTS VW_search_term_similarities;
// 3. Schema::dropIfExists('search_terms');
// 4. Schema::dropIfExists('messages_index');   // FK to words
// 5. Schema::dropIfExists('items_index');      // FK to words
// 6. Schema::dropIfExists('words_cache');
// 7. Schema::dropIfExists('words');
// KEEP: search_history, users_searches, damlevlim() function (email-domain typo
// correction: domain.go / domains.php), microactions table, items, messages_items,
// VW_item_similarities only if it references dropped tables — CHECK schema.sql:5301;
// if it reads microactions.item1/item2 (kept columns) it stays.
```

- Modify: `iznik-server/install/schema.sql` is retired/historical — do NOT edit it; migrations are the source of truth (CLAUDE.md).
- Test: Laravel migration test — `migrate:fresh` green on the worktree test DB (proves no remaining FK/view references); full Laravel + Go + vitest suites green ON THIS BRANCH WITH PHASE 1 MERGED IN (base branch already includes it).

- [ ] **Steps: write migration → migrate:fresh on worktree test DB → full three suites via status API → commit** `feat(db)!: drop keyword search tables (words, messages_index, items_index, words_cache, search_terms)`.

### Task 5.5: Final sweep + phase finish

- [ ] Repo-wide sweep: `grep -rn --include='*.php' --include='*.go' --include='*.js' --include='*.vue' -i "messages_index\|items_index\|words_cache\|search_terms\|GetWordsTypo\|GetWordsSounds\|ExpandQuery\|SOUNDEX" iznik-server iznik-server-go iznik-batch iznik-nuxt3` — every remaining hit must be: kept-by-design (damlevlim email domains; search_history stats) or removed. Document the survivors list in the PR body.
- [ ] `migration-parity-audit` skill mindset check (manual): for each deleted execution path, name its replacement (cascade→vector+lexical tier; index maintenance→embeddings:generate; Relevant→mail:relevant; typeahead→none, orphaned; SearchTerm challenge→none, purpose obsolete).
- [ ] Full suites green; code review; push; PR `feat!: retire legacy keyword search` with body: merge-order requirement (after Phase 1 + Phase 4), the dropped-tables list, the keep-list with reasons, prod deploy notes (run the `*_migration.sql`; remove relevant.php crontab line if still present; `embedding-sidecar` is already a standing prod service).

---

## Sequencing & merge order (humans merge)

```
Phase 1 (default flip + store fix)  ──┐
Phase 2 (similar posts + measurement) ├─ independent, any order
Phase 4 (digest relevance)          ──┘
Phase 3 (wanted→offer)   — after Phase 2 (shares MessageMatchCard + markseen source)
Phase 5 (retirement)     — LAST, after Phases 1 & 4 are merged; recommend letting
                           Phase 1 soak in prod ~1-2 weeks first (rollback for the
                           default flip is ?searchmode=keyword / one-line revert,
                           which dies with Phase 5).
```

## Pre-registered "was it worth it" criteria (Phase 2/3 PR bodies repeat these)

- Similar-posts strip: after 4 weeks live — keep iff CTR (clicks/impressions) ≥ 1% AND the 10% holdout shows no reply-rate decrease among non-holdout users; expand to more surfaces iff relative reply-rate lift ≥ 2%.
- Wanted-match panel: after 4 weeks — keep iff ≥ 0.5% of wanted-composers click a match AND attributed replies > 0. A wanted draft abandoned after a match-click that leads to a reply is a SUCCESS (deflection), not a failure.
- Digest relevance ranking: measured on the existing digest click-by-position dashboard with the new cohort split (ranked vs `userid % 10 == 0` holdout). After 4 weeks with `FEATURE_DIGEST_RELEVANCE` on: keep iff overall digest CTR in the ranked cohort ≥ holdout CTR; expected signature is a steeper position curve (more clicks concentrated at top positions).
- All widget metrics visible on the ModTools sysadmin "Recommendations" tab; sources: `similar_posts`, `wanted_match`. Digest clicks stay in the existing email-tracking pipeline — no new tracking needed there.

## Execution notes for the orchestrator

- Build agents: **Opus only** (Agent tool `model: "opus"`), one per task or task-group, working in `/home/edward/FreegleDocker-vector-search`. Fresh agent per task; orchestrator reviews diffs between tasks (subagent-driven-development).
- Worktree isolation is absolute: never touch `freegle-*` (main) containers, only `freegle-vector-search-*`.
- Update `.claude-session.md` (main checkout) status table as phases complete.
- If the sidecar in the worktree isn't running or `messages_embeddings` is empty in dev, run `docker compose up -d embedding-sidecar` in the worktree and `php artisan embeddings:generate --backfill` in `freegle-vector-search-batch` before Phase 1 Task 1.5 and any manual validation.
