# WhatJobs Billable Clicks Growth Plan

## 1. The Opportunity and the One Metric

**Revenue-per-impression** (RPI) = SUM(cpc of clicked jobs) / COUNT(jobs shown and viewable) across all placements. This is the metric that connects ad-slot decisions to actual income.

Right now you cannot compute RPI because the denominator - how many times a job was actually visible to a real user - is not recorded anywhere. `logs_jobs` stores only clicks. Loki receives `job_ad_visible` events from the IntersectionObserver in `JobOne.vue`, but Loki is not queryable as a database, has no stable schema, and can drop events on tab close. You also cannot tell which placement slot (mobile sticky footer, desktop sticky footer, left sidebar) drove a given click, because the `context` prop on every `JobsDaSlot` instance is hardcoded to the string `'daslot'`.

Everything else in this plan depends on fixing those two gaps first. Without impressions and placement tags you cannot compute a valid CTR per slot, cannot measure whether the A/B test moved revenue rather than just clicks, and cannot tell whether the follow-up modal is additive or cannibalising the primary slot.

---

## 2. Build Order

**Phase 1 (foundation):** Impression logging + placement tagging. Ship this first.

**Phase 2 (experiment):** A/B test on job-card presentation style. Requires Phase 1 data to measure correctly.

**Phase 3 (reach):** JobsFollowUpModal - shows more jobs after the user clicks one. Requires Phase 1 for attribution, benefits from Phase 2 to know which card style to use inside the modal.

---

## 3. Step-by-Step

---

### Phase 1a - Placement tagging (prerequisite, effort S)

**What it does:** Every job click in `logs_jobs` records which slot it came from. Every Loki event carries the same placement string. Metric moved: makes existing click data segment-able by slot immediately; no impression table needed yet.

**The gap:** `ExternalDa` mounts two sticky-footer `JobsDaSlot` instances in `LayoutCommon.vue` and one each in `SidebarLeft.vue` and `SidebarRight.vue`. All four pass no placement identifier, so `JobsDaSlot` hardcodes `context='daslot'` on every `JobOne`. The click goes to `logs_jobs` with no placement column at all.

**DB migration (iznik-batch):**

New file `database/migrations/2026_XX_add_placement_source_to_logs_jobs.php`:
- `ALTER TABLE logs_jobs ADD COLUMN placement VARCHAR(32) NULL DEFAULT NULL`
- `ALTER TABLE logs_jobs ADD COLUMN source VARCHAR(32) NULL DEFAULT NULL` (values: `'website'`, `'email'`, NULL for legacy)
- Add index on `placement`

**Go changes (iznik-server-go/job/job.go):**

Extend the `RecordJobClick` JSON body struct with `Placement string` and `Source string`. Both already use a three-way parse (JSON body / query / form); add both fields to all three paths. Write them into the INSERT:

```go
// existing INSERT adds:
// placement = ?, source = ?
```

Both columns are nullable, so old callers (email redirect, pre-deploy web) produce NULL rows. No breaking change.

**Frontend changes:**

`ExternalDa.vue` - add `placement` prop (String, default `'unknown'`); pass `:placement="placement"` to `<JobsDaSlot>`.

`LayoutCommon.vue` - the two `ExternalDa` instances become:
```
:placement="'sticky_footer_mobile'"  (xs/sm instance)
:placement="'sticky_footer_desktop'" (md+ instance)
```

`SidebarLeft.vue` - add `:placement="'sidebar_left'"` to its `ExternalDa`.

`SidebarRight.vue` - add `:placement="'sidebar_right'"` to its `ExternalDa`.

`JobsDaSlot.vue` - add `placement` prop (String, default `'daslot'`); replace the hardcoded string `context='daslot'` passed to each `JobOne` with `:context="placement"`.

`JobOne.vue` - in `clicked()`, add `placement: props.context` and `source: 'website'` to the `jobStore.log()` call payload. In all `action()` calls (`job_ad_rendered`, `job_ad_visible`, `job_ad_hover`, `job_ad_click`), add `placement: props.context`.

`pages/job/[id].vue` (email redirect) - already reads `?source=` and `?campaign=` from URL; the URL already carries `source=email` from `trackedUrl()`. Add `?placement=email_redirect` to the tracked URL in `UnifiedDigest.php`, `ChatNotification.php`, and `VolunteeringDigestMail.php`. The redirect page's `jobStore.log()` call already reads these params; add `placement: route.query.placement ?? 'email_redirect'`.

`pages/jobs.vue` - already passes `context='jobspage'`; add `placement='jobs_page'` to `jobStore.log()` calls here.

**Tests:** Add a Vitest unit test for `JobsDaSlot` that asserts the `context` prop received by the first rendered `JobOne` equals the `placement` prop passed to `JobsDaSlot` (not the hardcoded string `'daslot'`). This prevents regression.

---

### Phase 1b - Impression logging (effort M)

**What it does:** Records one row per (session, job, placement) when a job is actually viewable. Together with Phase 1a click data this gives RPI per slot. Metric moved: unlocks the primary metric (revenue-per-impression).

**DB migration (iznik-batch):**

New file `database/migrations/2026_XX_create_logs_job_impressions.php`:

```sql
CREATE TABLE logs_job_impressions (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session    VARCHAR(64) NOT NULL COMMENT 'freegle_session_id from sessionStorage',
  jobid      BIGINT UNSIGNED NOT NULL,
  placement  VARCHAR(32) NOT NULL DEFAULT '',
  variant    VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'bandit variant or empty',
  position   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  userid     BIGINT UNSIGNED NULL,
  timestamp  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY session_job_placement (session, jobid, placement),
  INDEX idx_jobid (jobid),
  INDEX idx_timestamp (timestamp),
  INDEX idx_placement (placement)
);
```

The `UNIQUE KEY` means repeated scroll-backs in the same tab collapse to one row, keeping the table lean. This mirrors `browse_scroll_depth` exactly (`iznik-server-go/browse/scroll.go`).

**Go changes (iznik-server-go):**

New handler `RecordJobImpression` in `job/job.go`:

```go
type jobImpressionBody struct {
    JobID     int64  `json:"jobid"`
    Session   string `json:"session"`
    Placement string `json:"placement"`
    Variant   string `json:"variant"`
    Position  int    `json:"position"`
    ListLength int   `json:"list_length"`
}
```

- Validate: drop if `session` empty and `jobid` zero (bot filter, mirrors `RecordJobClick`)
- Normalise placement to allowlist: `sticky_footer_mobile`, `sticky_footer_desktop`, `sidebar_left`, `sidebar_right`, `jobs_page`, `email_redirect`, `modal_more_jobs`; default `'unknown'`
- Optional userid from JWT (`auth.WhoAmI(c)` - already used in `RecordJobClick`)
- `INSERT INTO logs_job_impressions ... ON DUPLICATE KEY UPDATE userid=COALESCE(VALUES(userid), userid), timestamp=VALUES(timestamp)`
- Return `{ret:0,status:"Success"}` immediately; fire-and-forget

Register in `router/routes.go`:
```go
router.POST("/job/impression", jobHandler.RecordJobImpression)
```
No auth required, same as `/scrolldepth`.

**Frontend changes (JobOne.vue):**

The existing `IntersectionObserver` fires `job_ad_visible` immediately on 50% intersection. Wrap the dwell-confirm in a 1000ms `setTimeout`:

```js
const intersectionObserver = new IntersectionObserver((entries) => {
  if (entries[0].isIntersecting && !hasBeenVisible.value) {
    dwellTimer = setTimeout(() => {
      hasBeenVisible.value = true
      action('job_ad_visible', { ...payload })
      // NEW: fire impression beacon
      $fetch('/apiv2/job/impression', {
        method: 'POST',
        body: {
          jobid: job.value.id,
          session: getSessionId(),
          placement: props.context,
          variant: props.abVariant ?? '',
          position: props.position,
          list_length: props.listLength,
        },
      }).catch(() => {}) // fire-and-forget
    }, 1000)
  } else if (!entries[0].isIntersecting) {
    clearTimeout(dwellTimer)
  }
}, { threshold: 0.5 })
```

The 1000ms filter drops scroll-throughs. The `hasBeenVisible` ref already prevents re-firing within a page load; the DB UNIQUE key prevents double-counting across refreshes in the same tab.

Add props to `JobOne.vue`: `abVariant` (String, default `''`) and keep existing `context` prop.

**Email impression denominator:**

In `UnifiedDigest.php` `build()`, after the `getJobAds()` call, append to the metadata array:
```php
$this->metadata['job_ids'] = $jobAds->pluck('id')->values()->toArray();
```
No schema change - `email_tracking.metadata` is already JSON. This gives an exact denominator: `SELECT COUNT(*) FROM email_tracking WHERE JSON_CONTAINS(metadata, CAST(? AS JSON), '$.job_ids')`.

Also update `ChatNotification.php` and `VolunteeringDigestMail.php` with the same `job_ids` metadata append.

**Email click attribution:**

New migration: `ALTER TABLE email_tracking_clicks ADD COLUMN jobid BIGINT UNSIGNED NULL`.

In the Go `/e/d/r/{tracking_id}` redirect handler: when `action='job_click'`, extract jobid from link_url using a regex on `/job/(\d+)` and update the `email_tracking_clicks` row. This makes per-job email CTR an indexed query: `SELECT COUNT(*) FROM email_tracking_clicks WHERE jobid=? AND created_at BETWEEN ? AND ?`.

---

### Phase 1c - Placement performance dashboard (effort S)

**What it does:** Makes the Phase 1a/1b data visible to the team without requiring raw SQL. Metric moved: enables data-driven slot decisions.

**Go changes:**

New endpoint `GET /apiv2/job/placement-stats` in `job/job.go`:

```sql
SELECT
  lj.placement,
  COUNT(*)                          AS clicks,
  SUM(j.cpc)                        AS revenue_clicks,
  AVG(j.cpc * j.clickability/100.0) AS avg_expected_cpc
FROM logs_jobs lj
JOIN jobs j ON j.id = lj.jobid
WHERE lj.timestamp BETWEEN ? AND ?
  AND lj.placement IS NOT NULL
GROUP BY lj.placement
ORDER BY revenue_clicks DESC
```

Support/Admin gated, same middleware as `/rippling/metrics`. Add to `router/routes.go`.

**Frontend (modtools):**

New file `modtools/components/ModSysAdminJobStats.vue`: date-range pickers (reuse the pattern from `ModSysAdminRippling.vue`), a table showing placement, clicks, revenue, and avg CPC per day, plus a note linking to Grafana for impression data from Loki. Also shows email stats from the email_tracking tables. Admin/Support gated.

---

### Phase 2 - A/B test: job card presentation style (effort M)

**Depends on:** Phase 1a (placement tags in Loki + logs_jobs) and Phase 1b (impressions table). Without these you cannot compute RPI per arm.

**The question:** Which card presentation style produces the highest revenue-per-impression in the sticky footer and left sidebar slots?

**Arms:**

- **Arm A (image tiles):** Render `JobMosaicTile` (120x120 image, title above). Show 6 jobs. Only activates for jobs where `job.image` is non-null (avoids briefcase-placeholder spam). Falls back to arm B if fewer than 4 jobs have images.
- **Arm B (text dense, control):** Current behaviour. `JobOne` with `summary=true`. 10 jobs in list mode or 20 in grid.
- **Arm C (text rich):** `JobOne` with a new prop `richSummary=true` that adds a category badge and distance line below the title when `summary=true`, making each row ~20px taller. Show 6 jobs.

**Bucketing:**

In a new composable `composables/useJobsABVariant.js`:
1. Read `sessionStorage.getItem('freegle_jobs_ab_variant')`.
2. If present, use it (sticky for the tab lifetime).
3. If absent, call `BanditAPI.choose({ uid: 'job_presentation' })`, write the result to sessionStorage, and call `BanditAPI.shown({ uid: 'job_presentation', variant })`.

`JobsDaSlot.vue` calls this composable on `mounted`. The returned variant drives a `v-if` on which component to render. Seed the `abtest` table with:
```sql
INSERT IGNORE INTO abtest (uid, variant, shown, action, rate, suggest)
VALUES
  ('job_presentation','image',0,0,0,1),
  ('job_presentation','text_dense',0,0,0,1),
  ('job_presentation','text_rich',0,0,0,1);
```

**Click signal:**

In `JobOne.clicked()` and `JobMosaicTile.clicked()`, call `BanditAPI.chosen({ uid: 'job_presentation', variant: props.abVariant })`. This increments `abtest.action` for that variant and recomputes `rate`. The epsilon-greedy bandit (10% explore / 90% exploit) will converge on the best arm automatically.

**Fix `JobMosaicTile` for production:**

`JobMosaicTile.vue` is currently orphaned and missing `action()` calls. Before using it in the A/B test:
- Import `useClientLog` and call `action('job_ad_rendered', {...})` on `onMounted`
- Add an `IntersectionObserver` identical to `JobOne` for `job_ad_visible`
- Call `action('job_ad_click', {...})` in `clicked()` with the same payload shape as `JobOne`
- Add props `abVariant` (String) and `placement` (String)

`JobsDaSlot.vue` rendering by variant:
```html
<template v-if="activeVariant === 'image'">
  <JobMosaicTile v-for="job in imageJobs" :ab-variant="activeVariant" ... />
</template>
<template v-else-if="activeVariant === 'text_rich'">
  <JobOne v-for="job in richJobs" :rich-summary="true" :ab-variant="activeVariant" ... />
</template>
<template v-else> <!-- text_dense control -->
  <JobOne v-for="job in displayedJobs" :ab-variant="activeVariant" ... />
</template>
```

**Sample and stop rules:**

- Minimum before any conclusion: 500 `logs_job_impressions` rows per arm per placement.
- Stop-early: if one arm's `rate` in the `abtest` table (= 100 * actions/shown) is more than 40% above the lowest arm after 2000 impressions per arm, set `suggest=0` for the losing rows. The bandit will then route 100% to the winner.
- Maximum duration: 28 days from first impression.
- No clear winner at 28 days: keep arm B (text dense, status quo), set all arms `suggest=0`, close the test.

**Reading the winner:**

Primary: `SELECT uid, variant, shown, action, rate FROM abtest WHERE uid='job_presentation' ORDER BY rate DESC` - this gives the bandit's real-time view.

Revenue analysis (do this weekly):
```sql
SELECT lj.variant, COUNT(*) AS clicks, SUM(j.cpc) AS revenue
FROM logs_jobs lj JOIN jobs j ON j.id = lj.jobid
WHERE lj.variant IS NOT NULL AND lj.timestamp > ?
GROUP BY lj.variant;
```
Impression denominator comes from `logs_job_impressions GROUP BY variant`.

RPI per arm = `revenue / impression_count * 1000`.

**UX risk:** Arm A (image tiles) reduces list length from 10-20 to 6. If users find fewer jobs less useful, bounce to `/jobs` will increase. Guard: check Loki for `page_view` events within 30s of `job_ad_click` where the next URL is `/jobs` - if arm A shows > 25% more same-session `/jobs` bounces than arm B, pause arm A and investigate. This is queryable from Loki using `session_id` correlation between `job_ad_click` and subsequent `page_view` events.

**Tests:**
- Vitest unit test: `JobsDaSlot` with `activeVariant='image'` renders `JobMosaicTile` not `JobOne`.
- Vitest unit test: `JobMosaicTile` calls `action('job_ad_click')` with `ab_variant` in payload on click.
- Vitest unit test: `JobOne` with `richSummary=true` renders the category/distance line.

---

### Phase 3 - JobsFollowUpModal (effort M)

**Depends on:** Phase 1 (attribution), Phase 2 (know which card style to use inside the modal).

**The opportunity:** A user who clicks a job ad has shown high intent. Today `JobOne.clicked()` calls `router.push('/jobs')` which navigates away from the page entirely. Replacing that navigation with a modal showing more jobs converts a single click into multiple impressions at zero API cost (`jobStore.list` is already in memory).

**Remove the navigation:** Delete the `router.push('/jobs')` line from `JobOne.clicked()`. The `ExternalLink` anchor already handles the outbound tab open via `target='_blank'`. The modal replaces the page-navigation as the "next step".

**Emit from JobOne:** Add `emit('clicked', job.value.id)` in `JobOne.clicked()`. `JobsDaSlot` catches this with `@clicked="onJobClicked"`.

**New component `components/JobsFollowUpModal.vue`:**

- `b-modal` with `size='lg'`, `hide-header`, `hide-footer`, `no-stacking`
- Props: `excludeIds` (Array, job IDs already visible in the triggering slot), `placement` (String, default `'modal_more_jobs'`)
- Reads `jobStore.list` directly - no new fetch
- Computes `modalJobs = jobStore.list.filter(j => !excludeIds.includes(j.id)).slice(0, 8)` in ranked API order (not reshuffled)
- Renders `JobOne :summary="true"` for each with `context="modal_more_jobs"`, `position=index`, `:list-length="modalJobs.length"`
- X close button first in tab order, then job list, then "See all jobs" nuxt-link to `/jobs`
- On "See all jobs": `modal.hide()` then `router.push('/jobs')`
- Fires `action('jobs_modal_open', { triggered_by_job_id, triggered_by_context, modal_job_count, session_id })` on `@show`

**Frequency cap (composable `composables/useJobsFollowUpModal.js`):**

- `shouldShowModal()`: returns false if `sessionStorage.getItem('jobs_modal_shown_this_session')` is set, OR if `miscStore.vals['last_jobs_modal_shown']` is within 30 minutes. Uses existing `miscStore.set/get` pattern - no new store field.
- `recordShown()`: sets both the sessionStorage key and the `miscStore` timestamp.

**JobsDaSlot changes:**
- Always mounts `<JobsFollowUpModal>` (not v-if-gated), controlled by `useOurModal({ autoShow: false })`
- `onJobClicked(clickedId)`: check frequency cap; if allowed, set `excludeIds` to the current `displayedJobs` id list, call `modal.show()`, call `recordShown()`
- Pass `:exclude-ids="displayedJobIds"` to the modal

**Mobile layout (SCSS):**
```scss
@include media-breakpoint-down(sm) {
  .jobs-followup-modal .modal-dialog {
    position: fixed;
    bottom: 0;
    margin: 0;
    width: 100%;
    max-width: 100%;
  }
  .jobs-followup-modal .modal-content {
    border-radius: 12px 12px 0 0;
  }
}
```

**Backend:** The `placement='modal_more_jobs'` field on `jobStore.log()` calls from inside the modal reaches `RecordJobClick` and is stored in `logs_jobs.placement` (already added in Phase 1a). No new endpoint.

**Tests:**
- Vitest: clicking a `JobOne` inside `JobsDaSlot` emits `'clicked'` event.
- Vitest: `JobsFollowUpModal` filters out `excludeIds` from `jobStore.list`.
- Vitest: modal does not show if `shouldShowModal()` returns false.

---

## 4. A/B Methodology Summary

**Arms:** image (mosaic tiles, n=6), text_dense (summary rows, n=10-20, control), text_rich (summary rows with category line, n=6).

**Bucketing unit:** Browser tab session (`freegle_session_id` from `sessionStorage`). One variant per tab lifetime. The epsilon-greedy bandit picks stochastically at session start; no per-user DB bucketing is needed for this scale.

**Primary metric:** Revenue per 1000 impressions = `SUM(logs_jobs.cpc of clicks with this variant) / COUNT(logs_job_impressions rows for this variant) * 1000`. This is the only metric that captures both click rate and the CPC value of what was clicked.

**Sample requirement:** 500 impressions per arm minimum; 2000 per arm before any stop-early decision. At typical Freegle page-view volume, this should accumulate within 1-2 weeks.

**Stop rules:**
- Early stop (winner found): one arm's RPI > 40% above lowest arm at >= 2000 impressions per arm.
- Time stop (no winner): 28 days; keep control.
- Safety stop: arm's `/jobs` bounce rate (session has `job_ad_click` then `page_view` on `/jobs` within 30s) is > 25% above control; pause and investigate.

**Reading:** `SELECT uid, variant, shown, action, rate FROM abtest WHERE uid='job_presentation'` gives real-time bandit convergence. Weekly SQL join with `logs_jobs` and `logs_job_impressions` gives RPI per arm. The bandit's `action/shown` rate is a proxy for CTR, not RPI; always cross-check against the revenue SQL.

**UX risk:** Arm C (text rich) shows only 6 jobs. If high-CPC jobs rank positions 7-10, arm C misses them. Check whether clicked positions in arm C cluster at positions 0-2 (suggesting the list is long enough) or spread evenly (suggesting you are cutting off high-value tail). Readable from `logs_job_impressions.position` joined with `logs_jobs.position` once that column is wired (Phase 1b already includes `position` in the impression table).

---

## 5. Guardrails

**Do not tank UX:**
- The 30-minute cross-session frequency cap on the follow-up modal prevents it becoming an annoyance.
- The 1000ms IntersectionObserver dwell filter prevents impression inflation from scroll-throughs (which would make CTR look worse than it is and mislead the A/B test).
- The borednow 31s timeout in `JobsDaSlot` is already in place; the modal is only triggered by an active click, not by passive boredom.
- The UX guardrail (> 25% `/jobs` bounce increase) is measurable from existing Loki session data.

**WhatJobs terms:**
- Do not auto-click or pre-fetch job URLs on behalf of users. Every entry in `logs_jobs` must correspond to a real user click. The `INSERT IGNORE` in `RecordJobClick` already prevents duplicates; the modal's `JobOne` calls `jobStore.log()` only on genuine user clicks.
- Do not manipulate the clickability scores by recording modal impressions as clicks. The `logs_job_impressions` table (impressions) is separate from `logs_jobs` (clicks). `WhatJobsService.analyseClickability()` reads only `logs_jobs`, so it is not contaminated.
- The existing area-ratio guard in `GetJobs` (drops nationwide jobs > 2x the search box) already limits off-target ad delivery; do not remove it.

**Privacy:**
- `logs_job_impressions.session` is a `sessionStorage` UUID - ephemeral per tab, not linked to a user without an authenticated JWT join, and `userid` is nullable. No PII.
- No IP stored in `logs_job_impressions` (consistent with existing `logs_jobs`).
- The email `job_ids` metadata field stores only integer job IDs, not user data.

**Rollback:**
- Phase 1a (placement tags): the new `logs_jobs` columns are nullable; removing the frontend prop-threading reverts the Loki payload to no placement field and the DB to NULL rows. No data loss.
- Phase 1b (impressions endpoint): the `POST /apiv2/job/impression` call in `JobOne.vue` is a fire-and-forget `$fetch` with a `.catch(() => {})`. Removing the frontend call leaves the endpoint idle; the table stays but accumulates no rows. The Go handler can be unregistered from `routes.go` without touching any other handler.
- Phase 2 (A/B): set `suggest=0` for all `abtest` rows where `uid='job_presentation'`. The bandit returns no suggestion; `JobsDaSlot` falls back to the `text_dense` default. The `variant` column on `logs_jobs` retains historical data.
- Phase 3 (modal): set the frequency cap to `0` minutes (miscStore) and the modal never re-shows. Or remove the `JobsFollowUpModal` mount from `JobsDaSlot` and redeploy. No DB changes to undo.

---

## File Change Summary

| Phase | Files changed | Effort |
|---|---|---|
| 1a - placement tags | `ExternalDa.vue`, `JobsDaSlot.vue`, `JobOne.vue`, `LayoutCommon.vue`, `SidebarLeft.vue`, `SidebarRight.vue`, `pages/jobs.vue`, `pages/job/[id].vue`, `job/job.go` (RecordJobClick), 1 migration | S |
| 1b - impression logging | `JobOne.vue` (IntersectionObserver dwell), new Go handler `RecordJobImpression`, `routes.go`, 1 migration, `UnifiedDigest.php`, `ChatNotification.php`, `VolunteeringDigestMail.php`, 1 migration (email_tracking_clicks.jobid), Go email redirect handler | M |
| 1c - dashboard | New `ModSysAdminJobStats.vue`, new Go endpoint `GET /apiv2/job/placement-stats`, `routes.go` | S |
| 2 - A/B test | `JobsDaSlot.vue` (variant rendering), `JobOne.vue` (abVariant prop + actions), `JobMosaicTile.vue` (fix orphaned component), new `composables/useJobsABVariant.js`, `LayoutCommon.vue`, `SidebarLeft.vue`, `ExternalDa.vue`, `job/job.go` (variant column in RecordJobClick), 1 migration (variant + position on logs_jobs), abtest seed SQL | M |
| 3 - modal | New `components/JobsFollowUpModal.vue`, new `composables/useJobsFollowUpModal.js`, `JobsDaSlot.vue` (emit handler, modal mount), `JobOne.vue` (emit clicked), remove `router.push('/jobs')`, SCSS additions | M |