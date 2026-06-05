# PR Walkthrough Capture Framework

## Mechanism

The primary capture path is **live-drive with auto-measured coordinates** (Mechanism A). A committed tool, `capture.mjs`, drives a running worktree to each screen via Playwright, takes full-page screenshots, and calls `element.boundingBox()` on every annotated selector at the moment the screenshot is taken — producing fractional `{x,y,w,h}` coordinates in a sidecar `.boxes.json` file. `render.mjs` resolves those boxes at render time via `{ "ref": "<label>" }` callouts, so no coordinates are ever eyeballed off a grid.

Mechanism B (recordVideo + frame extraction) is **not used** as a primary path. Playwright video runs at test speed with no semantic pauses; extracted frames are at arbitrary clock positions and are likely mid-animation or mid-scroll. Crucially, trace snapshots do not contain bounding boxes for arbitrary HTML elements — `kBoundingRectAttribute` is only captured for `CANVAS`, `IFRAME`, and `FRAME` elements. The whole annotation-precision claim for Mechanism B is false.

Mechanism B survives in one place: **as a diagnostic fallback** for understanding what sequence of states a test traverses, when no matching spec exists and the agent needs to understand the UI before writing a capture plan. It is never the source of still frames or callout coordinates.

The pipeline is:

```
fetch → auth (per role) → env-export → capture → verify PNGs → author storyboard (ref callouts) → render
```

All tools except `env-from-testenvs.mjs` already exist and are committed to the `pr-walkthrough` repo. A new small utility, `env-from-testenvs.mjs`, eliminates the manual env-var lookup step.

---

## Committed Tools

| Tool | Status | CLI signature | Input → Output |
|---|---|---|---|
| `src/fetch.mjs` | **exists** | `node src/fetch.mjs <pr> [--repo owner/repo] [--pr-dir dir]` | GitHub via `gh` CLI → `prs/pr-N/pr-N.json`, `pr-N.diff`, `assets/` (PR body images) |
| `src/auth.mjs` | **exists** | `node src/auth.mjs --base-url <url> --email <email> [--password freegle] --out <file>` | Live worktree login modal → `.auth-<role>.json` storageState (cookies + localStorage) |
| `src/env-from-testenvs.mjs` | **new** | `node src/env-from-testenvs.mjs --env <key> [--testenvs <path>]` | `test-envs.json` entry → `export VAR=value` lines for `eval $()` |
| `src/capture.mjs` | **exists** | `node src/capture.mjs --pr-dir prs/pr-N --base-url <url> [--headful]` | `capture-plan.json` + live worktree → `assets/*.png` + `assets/*.boxes.json` |
| `src/analyze.mjs` | **exists** | `node src/analyze.mjs --pr-dir prs/pr-N [--analyzer manual\|claude]` | Existing `storyboard.json` → schema validation + E2E test-title coverage report. With `--analyzer claude`: calls `claude` CLI to draft `storyboard.json` from diff + assets |
| `src/render.mjs` | **exists** | `node src/render.mjs --pr-dir prs/pr-N [--out path.mp4]` | `storyboard.json` + `assets/*.masked.png` + `assets/*.boxes.json` → `prs/pr-N/out/pr-N-walkthrough.mp4` |
| `pr-walkthrough.mjs` | **extend** | `node pr-walkthrough.mjs <pr> [--repo owner/repo] [--base-url <url>] [--analyzer manual\|claude] [--skip-fetch]` | One-command wrapper: fetch → capture → analyze → render. Add `env-from-testenvs` between fetch and capture |

### `src/env-from-testenvs.mjs` — new, ~20 lines

Reads `iznik-nuxt3/tests/e2e/test-envs.json` (or a path passed as `--testenvs`), looks up the entry for `--env <key>` (e.g. `browse`), and prints shell `export` lines that `capture.mjs`'s `subst()` function can consume. Example output:

```sh
export BULK_MSG_ID=1244
export WANTED_MSG_ID=1245
export MOD_EMAIL=pw_browse_mod@test.com
export USER_EMAIL=pw_browse_user@test.com
export USER2_EMAIL=pw_browse_user2@test.com
export GROUP_ID=12
export POSTCODE=LS1 4AP
```

The variable names are the union of common slots (`messages.offer` → `BULK_MSG_ID`, `messages.wanted` → `WANTED_MSG_ID`, `mod.email` → `MOD_EMAIL`, etc.). If a PR uses non-standard testEnv fields, the agent can add individual `export` lines before running capture; the tool handles the common 90%.

### `capture.mjs` — extend with `snap` step type

Add `snap` to `STEP_KEYS` and the step dispatcher. A `snap` step takes a numbered screenshot mid-sequence and measures annotations at that moment, writing `<shot-basename>-N.png` and `<shot-basename>-N.boxes.json`. This allows a single shot to capture both a before-state and an after-state (e.g. before and after a toggle click) without requiring two separate shots with duplicate navigation steps. The existing end-of-shot screenshot behaviour is unchanged when no `snap` steps are present.

---

## Declarative Per-PR Config: `capture-plan.json`

The foreground agent authors one file per PR: `prs/pr-N/capture-plan.json`. Everything else is derived mechanically from committed tools and existing source files.

### Schema (existing, with `snap` extension)

```jsonc
{
  "_about": "Human-readable note about what this plan captures and how to run it.",
  "baseUrl": "",                     // empty — supplied via --base-url at runtime
  "defaultViewport": { "width": 1385, "height": 1400 },
  "shots": [
    {
      "name": "my-shot.png",         // output filename in assets/
      "route": "/path/${ENV_VAR}",   // ${VAR} substituted from environment
      "viewport": { "width": 1385, "height": 1400 },  // optional override
      "fullPage": true,              // default true; false for above-fold only
      "clip": "testid=some-modal",   // optional: clip to one element instead of full page
      "auth": ".auth-user.json",     // optional: per-shot storageState file (relative to pr-dir)
      "steps": [
        { "fill": "testid=foo", "value": "Hello" },
        { "click": "testid=bar" },
        { "waitFor": "testid=result" },
        { "waitMs": 300 },
        { "snap": "mid-state.png", "annotate": [ ... ] }, // optional mid-sequence screenshot
        { "scrollTo": "testid=bottom-section" }
      ],
      "annotate": [
        { "selector": "testid=foo", "label": "My label", "arrow": "down" }
      ]
    }
  ]
}
```

Step vocabulary (full set in `capture-plan-schema.mjs`): `goto`, `fill`, `type`, `select`, `click`, `clickText`, `press`, `waitFor`, `waitForText`, `waitMs`, `scrollTo`, `setViewport`, `snap`.

Selector syntax: `testid=<value>` → `[data-testid="<value>"]`; `text=<value>` → Playwright `getByText`; anything else → raw CSS.

Mutating clicks are refused: `capture.mjs` throws if a click target matches `/(submit|register-interest|post these items|send|save|confirm|delete)/i`.

### Concrete example: PR 618 (bulk-offer clearance)

```json
{
  "_about": "Bulk-offer clearance composer + recipient-interest view + mod preview. Run against the bulk-offer branch worktree. BULK_MSG_ID and MOD_MSG_ROUTE from eval $(node src/env-from-testenvs.mjs --env browse). Auth: user2 for recipient-interest (needs a non-owner), mod for mod-bulk-preview.",
  "baseUrl": "",
  "defaultViewport": { "width": 1385, "height": 1400 },
  "shots": [
    {
      "name": "clearance-composer.png",
      "route": "/give/clearance",
      "fullPage": true,
      "_note": "addItem() unshifts (prepends) rows — fill the TOP row (index 0) after each add-item click, in REVERSE display order.",
      "steps": [
        { "fill": "testid=clearance-title", "value": "Office Clearance" },
        { "fill": "textarea[placeholder^='e.g. Charity office clearance']", "value": "Charity office clearance — everything must go by Friday." },
        { "click": "testid=mode-manual" },
        { "waitFor": "testid=item-name-0" },
        { "fill": "testid=item-name-0", "value": "Filing cabinet" },
        { "fill": "testid=item-qty-0", "value": "3" },
        { "select": "testid=item-condition-0", "value": "LikeNew" },
        { "click": "testid=add-item" },
        { "waitMs": 250 },
        { "fill": "testid=item-name-0", "value": "Swivel chair" },
        { "fill": "testid=item-qty-0", "value": "14" },
        { "select": "testid=item-condition-0", "value": "Used" },
        { "click": "testid=add-item" },
        { "waitMs": 250 },
        { "fill": "testid=item-name-0", "value": "Office desk" },
        { "fill": "testid=item-qty-0", "value": "4" },
        { "select": "testid=item-condition-0", "value": "Good" },
        { "fill": "testid=slot-0", "value": "Tue 7 Apr, 10am–4pm" },
        { "click": "testid=add-slot" },
        { "waitMs": 200 },
        { "fill": "testid=slot-1", "value": "Wed 8 Apr, 10am–2pm" },
        { "fill": "testid=clearance-access", "value": "Side gate by the loading bay." },
        { "waitMs": 400 }
      ],
      "annotate": [
        { "selector": "input[placeholder*='postcode' i]", "label": "Pick your area first", "arrow": "down" },
        { "selector": "testid=clearance-title", "label": "Name the whole clearance", "arrow": "down" },
        { "selector": "testid=mode-manual", "label": "Type them in — or paste a spreadsheet", "arrow": "up" },
        { "selector": "testid=item-qty-0", "label": "How many", "arrow": "up" },
        { "selector": "testid=item-condition-0", "label": "Condition", "arrow": "up" },
        { "selector": "text=things in total", "label": "3 items, 21 things", "arrow": "right" },
        { "selector": "testid=slot-0", "label": "Offer set collection times", "arrow": "right" }
      ]
    },
    {
      "name": "recipient-interest.png",
      "route": "/message/${BULK_MSG_ID}",
      "auth": ".auth-user2.json",
      "fullPage": true,
      "_note": "user2 is a non-owner — sees the Want toggle and slot picker. BULK_MSG_ID from env-from-testenvs (browse.messages.offer = 1244).",
      "steps": [
        { "click": "[data-testid^='pick-']" },
        { "waitFor": "text=How many?" },
        { "waitMs": 300 }
      ],
      "annotate": [
        { "selector": "text=items in this offer", "label": "A browsable catalogue in one offer", "arrow": "up" },
        { "selector": "[data-testid^='pick-']", "label": "Turn on what you want", "arrow": "left" },
        { "selector": "testid=slot-picker", "label": "Pick a collection time", "arrow": "up" },
        { "selector": "testid=register-interest", "label": "One message to the giver", "arrow": "right" }
      ]
    },
    {
      "name": "mod-bulk-preview.png",
      "route": "${MOD_MSG_ROUTE}",
      "auth": ".auth-mod.json",
      "clip": ".modal-content",
      "_note": "MOD_MSG_ROUTE is the pending-queue URL for the seeded bulk message. Clip to modal so only the preview panel is in frame.",
      "steps": [
        { "click": "testid=bulk-preview-btn" },
        { "waitFor": ".modal-content" },
        { "waitMs": 300 }
      ]
    }
  ]
}
```

### Auth roles and seeded IDs for PR 618

From `iznik-nuxt3/tests/e2e/test-envs.json`, `browse` entry:

| Variable | Value | Source |
|---|---|---|
| `BULK_MSG_ID` | `1244` | `browse.messages.offer` |
| `WANTED_MSG_ID` | `1245` | `browse.messages.wanted` |
| `MOD_EMAIL` | `pw_browse_mod@test.com` | `browse.mod.email` |
| `USER_EMAIL` | `pw_browse_user@test.com` | `browse.user.email` |
| `USER2_EMAIL` | `pw_browse_user2@test.com` | `browse.user2.email` |
| `MOD_MSG_ROUTE` | (not in testenvs — set manually from the mod queue URL) | n/a |

Auth files:
- `.auth-user2.json` — logged in as `pw_browse_user2@test.com` (non-owner, sees Want toggle)
- `.auth-mod.json` — logged in as `pw_browse_mod@test.com` (moderator, sees pending queue)

---

## How Callout Coordinates Are Auto-Derived

`capture.mjs` implements `measureAnnotations()`. After taking the full-page screenshot it calls `page.locator(<selector>).first().boundingBox()` for every entry in `shot.annotate`. The returned pixel rect is divided by the PNG's natural pixel dimensions (read from the PNG IHDR header via `pngSize()`) to produce fractional `{x, y, w, h}` coordinates in `[0, 1]`. These are written to `assets/<shot-basename>.boxes.json`.

`render.mjs` resolves `{ "ref": "<label>" }` callouts in `storyboard.json` by looking up the matching label in the `.boxes.json` sidecar at render time. No coordinates are ever typed by hand. The `focusAuto: true` scene flag derives the zoom rectangle from the union of the resolved callout boxes, so the zoom is also auto-computed.

The agent's only decision is which selectors to include in the `annotate` list (declared in the capture plan) and the arrow direction (`up`/`down`/`left`/`right`) for each callout.

If an element is not in the DOM at screenshot time (e.g. it is only visible after a click), its entry is absent from `.boxes.json` and `render.mjs` throws at render time with a clear error. The fix is to add a step before the screenshot that reveals the element.

---

## How It Reuses the E2E Tests

The E2E suite is consulted at three points, all read-only.

**1. Coverage signal — `analyze.mjs --pr-dir prs/pr-N`**

`mineTests()` in `analyze.mjs` scans the PR diff for added or changed `describe`/`test`/`it` blocks. It prints a coverage report listing test titles from Playwright spec files (marked `★`) and other test files. The agent reads this list to confirm which user flows the storyboard must cover. This does not run any tests; it only reads the diff.

**2. Seeded credentials and IDs — `test-envs.json`**

`iznik-nuxt3/tests/e2e/test-envs.json` is the single source of truth for seeded group IDs, user emails, message IDs, and postcodes, keyed by spec prefix (e.g. `browse`, `explore`, `postflow`). The agent reads this file to find the correct `testEnvKey` for the PR's flows and exports those values before running capture:

```sh
eval $(node src/env-from-testenvs.mjs --env browse \
  --testenvs ../iznik-nuxt3/tests/e2e/test-envs.json)
```

`capture.mjs` substitutes `${BULK_MSG_ID}` etc. from the environment via its existing `subst()` function.

**3. Flow understanding — spec files (read, not run)**

The agent reads the named spec file(s) to understand the navigation route, which auth persona is needed for each shot (user vs user2 vs mod), and the sequence of interactions that reaches the interesting UI state. This reading informs what steps to put in `capture-plan.json`. The spec files are not imported, modified, or executed.

No target worktree files are modified. No target databases are written (the mutating-click guard in `capture.mjs` refuses any submit/save/confirm). Auth login writes only the `.auth-<role>.json` sidecar in the `pr-walkthrough` repo, not in the worktree.

---

## The Runbook

Each numbered step is either **mechanical** (a fixed command the agent runs without judgment) or **judgment** (the agent must read and decide). Mechanical steps are marked `[M]`; judgment steps are marked `[J]`.

---

**Step 1 — Confirm the worktree is running** `[M]`

```sh
./freegle status
```

Note the base URL (e.g. `http://freegle-dev-local.localhost:9080`) and confirm it resolves to the PR's containers, not the main checkout. The URL is used in every subsequent step.

Confirm the worktree's test database is seeded:

```sh
curl -s http://localhost:<PORT_STATUS>/api/tests/env/browse | jq .ok
```

If `false`, run `scripts/setup-test-database.sh` against the worktree's DB first. See `BROWSER-TESTING.md`.

---

**Step 2 — Fetch PR material** `[M]`

```sh
node src/fetch.mjs <pr> --repo Freegle/Iznik
```

Creates `prs/pr-<N>/pr-<N>.json` (metadata), `pr-<N>.diff` (full diff), and downloads any images from the PR body into `assets/`.

---

**Step 3 — Read the diff and spec files; run the coverage report** `[J]`

```sh
node src/analyze.mjs --pr-dir prs/pr-<N>
```

This prints the E2E test titles mined from the diff. Read them. Open the matching spec file(s) in `iznik-nuxt3/tests/e2e/` to understand:
- Which routes the tests navigate to
- Which auth persona is used for each flow (user, user2, mod)
- Which `testEnv` key the spec references (e.g. `testEnvs.browse`, `testEnvs.postflow`)
- The interaction sequence that reaches the UI state worth filming

This step requires reading code. It does not run any tests.

---

**Step 4 — Export seeded IDs from test-envs.json** `[M]`

```sh
eval $(node src/env-from-testenvs.mjs --env <testEnvKey> \
  --testenvs ../iznik-nuxt3/tests/e2e/test-envs.json)
```

This sets `BULK_MSG_ID`, `MOD_EMAIL`, `USER_EMAIL`, `USER2_EMAIL`, etc. in the current shell. For any IDs not in `test-envs.json` (e.g. `MOD_MSG_ROUTE` for a specific pending-queue URL), set them manually:

```sh
export MOD_MSG_ROUTE=/modtools/messages/approved/123456
```

---

**Step 5 — Log in each required auth persona** `[M]`

For each persona needed for the PR's shots:

```sh
node src/auth.mjs \
  --base-url http://freegle-dev-local.localhost:<PORT> \
  --email pw_<env>_user@test.com \
  --password freegle \
  --out prs/pr-<N>/.auth-user.json

node src/auth.mjs \
  --base-url http://freegle-dev-local.localhost:<PORT> \
  --email pw_<env>_user2@test.com \
  --password freegle \
  --out prs/pr-<N>/.auth-user2.json

node src/auth.mjs \
  --base-url http://modtools-dev-local.localhost:<PORT> \
  --email pw_<env>_mod@test.com \
  --password freegle \
  --out prs/pr-<N>/.auth-mod.json
```

Run auth immediately before capture — storageState expires if the containers restart between auth and capture.

---

**Step 6 — Write `capture-plan.json`** `[J]`

Create `prs/pr-<N>/capture-plan.json` following the schema above. Use `${VAR}` placeholders for any values exported in Step 4.

Judgment decisions required:
- Which shots to include (one shot per distinct UI state worth narrating)
- Which selector to annotate for each callout
- The arrow direction for each callout (`up`/`down`/`left`/`right`)
- The correct step ordering for any form where `addItem()` prepends rows

**Prepend-row ordering rule (BulkItemEditor and similar):** When `addItem()` prepends (unshifts) a row, the topmost visible row is always index 0. Fill the row you want at the BOTTOM of the final display first, then add a new row, fill it, and so on. The last `fill` sequence targets index 0 (the intended top row). See the PR 618 `clearance-composer` shot in the example above. This is the only ordering nuance that cannot be inferred from the diff — the agent must read the component source.

---

**Step 7 — Validate the capture plan** `[M]`

```sh
node -e "
  import('./src/capture-plan-schema.mjs').then(({validateCapturePlan}) => {
    const fs = await import('fs');
    const plan = JSON.parse(fs.readFileSync('prs/pr-<N>/capture-plan.json','utf8'));
    const {ok,errors} = validateCapturePlan(plan);
    if (!ok) { console.error(errors.join('\n')); process.exit(1); }
    console.log('valid');
  });
"
```

Fix any schema errors before proceeding.

---

**Step 8 — Run capture** `[M]`

```sh
node src/capture.mjs \
  --pr-dir prs/pr-<N> \
  --base-url http://freegle-dev-local.localhost:<PORT>
```

This drives the live worktree, executes all shots in sequence, takes full-page screenshots, and writes `assets/<shot>.png` + `assets/<shot>.boxes.json` for each shot that has an `annotate` list. The console reports which callouts were measured.

Add `--headful` if a shot fails and visual debugging is needed.

---

**Step 9 — Verify PNGs and boxes** `[J]`

Open each `prs/pr-<N>/assets/*.png` in an image viewer. Confirm:
- The UI is in the intended state (not a spinner, not an error page, not a login modal)
- The visible content matches what you described in the capture plan

Open each `prs/pr-<N>/assets/*.boxes.json` and confirm:
- Every label you intended to annotate has an entry
- The `x`, `y`, `w`, `h` values are plausible (not `0,0,0,0`)
- No important selector was missed

If any shot is wrong: edit the relevant steps in `capture-plan.json` and re-run capture for that shot only (capture runs all shots in the plan; if only one needs re-shooting, comment out the others temporarily or use `--headful` to observe what's happening).

---

**Step 10 — Author storyboard.json** `[J]`

Either draft manually or use the Claude analyzer:

```sh
# Optional: let Claude draft a first pass
node src/analyze.mjs --pr-dir prs/pr-<N> --analyzer claude
```

The draft uses the PR diff and the assets list. Review and edit it.

**Use `ref` callouts, not explicit `box` coordinates.** For every callout that was measured by capture, write:

```json
{ "at": 1.5, "until": 6.0, "ref": "Name the whole clearance" }
```

The label must match the `label` in the `annotate` entry of the capture plan exactly. `render.mjs` looks up the measured box from `<shot>.boxes.json` at render time. Never write explicit `box` coordinates for a measured element — that would be hand-copying numbers that the tool already has.

**Use `focusAuto: true`** on any screenshot scene where you want the renderer to auto-zoom to the callout region:

```json
{
  "type": "screenshot",
  "focusAuto": true,
  "src": "pr-<N>/clearance-composer.masked.png",
  ...
}
```

`render.mjs` computes the `focus` box from the union of the resolved callout boxes plus padding. Explicit `focus` overrides this when needed.

---

**Step 11 — Mark PII regions for masking (if needed)** `[J]`

If any screenshot contains real personal data (names, email addresses, avatars of real users), create `prs/pr-<N>/masks.json`:

```json
[
  { "src": "clearance-composer.png", "rects": [
    { "x": 0.02, "y": 0.0, "w": 0.18, "h": 0.045 }
  ]}
]
```

Rects are fractions of the image. `render.mjs` calls `imageutil.py mask` before staging assets.

For screenshots that use seeded test users (which is the normal case with the worktree's test DB), masking is typically not needed.

---

**Step 12 — Validate storyboard** `[M]`

```sh
node src/analyze.mjs --pr-dir prs/pr-<N>
```

This validates `storyboard.json` against `storyboard-schema.mjs` (including checking that all referenced assets exist in `assets/`) and prints the test-coverage report. Fix any validation errors.

---

**Step 13 — Render** `[M]`

```sh
node src/render.mjs --pr-dir prs/pr-<N>
```

Steps: mask PII → stage masked assets into `public/` → resolve `ref` callouts from `.boxes.json` → auto-compute `focus` for `focusAuto` scenes → validate → Remotion render.

Output: `prs/pr-<N>/out/pr-<N>-walkthrough.mp4`.

---

## Failure Modes and Fallbacks

### Screenshot shows a spinner or loading state

The worktree's API or frontend is still initialising when capture navigates. Add a `waitFor` step before the final screenshot (or before a `snap` step):

```json
{ "waitFor": "testid=clearance-title" }
```

This waits for a visible element rather than relying on `networkidle` alone. If the API is especially slow (Go rebuild in progress), add `{ "waitMs": 2000 }` after navigation.

### Screenshot shows the login modal

The storageState in `.auth-<role>.json` has expired (the worktree containers restarted since auth was run). Re-run Step 5 immediately before Step 8. Auth and capture should be run in the same shell session without an intervening container restart.

### A `boxes.json` entry is missing

The annotated selector was not visible in the DOM at screenshot time. Either:
- The element is behind a click that wasn't in the steps — add `{ "click": "testid=toggle" }` and `{ "waitFor": "testid=revealed-element" }` before the screenshot.
- The element is scrolled off screen — add `{ "scrollTo": "testid=the-element" }` before the screenshot.
- The selector is wrong — check the actual `data-testid` in the running app via `--headful`.

### `render.mjs` throws `callout ref "X" not found in clearance-composer.boxes.json`

A `{ "ref": "X" }` in `storyboard.json` has no matching key in the `.boxes.json` sidecar. Either the label in the storyboard doesn't match the label in the capture plan's `annotate` entry exactly (case-sensitive), or capture didn't measure that element. Fix the label or re-run capture.

### The `addItem()` fill order produces wrong visible content

The BulkItemEditor (and any similar prepend-mutating form) adds rows at the top (index 0). If items appear in the wrong order in the screenshot, the steps were authored in the wrong sequence. Re-read the component's mutation model and reverse the fill order — fill the intended bottom-most item first, then `add-item`, then fill the next-from-bottom, and so on.

### A shot requires a seeded message ID not in `test-envs.json`

This happens when the PR introduces a new message type with no existing testEnv entry. Options:
1. Use a message ID from an adjacent env that has the right type (check `test-envs.json` for other envs that have the needed field).
2. Look up a real message ID directly from the worktree's DB: `docker exec freegle-<worktree>-mariadblive mysql -u root -pfreegle iznik -e "SELECT id FROM messages WHERE type='Offer' LIMIT 1;"` — this is read-only.
3. As a last resort, use the `supplement` pattern: provide a static PNG from a CI screenshot artifact or a manual screen capture. Reference it directly in `storyboard.json` with a `src` pointing at a pre-existing image file. No capture steps needed.

### No matching E2E spec exists for the PR's flows

When `analyze.mjs` prints no `★` E2E titles, there are no spec files to read for flow guidance. The agent must author `capture-plan.json` steps by reading the Vue components and the PR diff directly. This is more effort but still declarative — the step vocabulary is fixed. Consider whether this PR's UI change is significant enough to warrant a walkthrough video at all; backend-only or trivial UI changes may not be worth filming.

### PR touches a flow that requires DB writes to reach the interesting state

Example: a confirmation screen that only appears after a successful form submission. The `capture.mjs` mutating-click guard correctly bars this path. Options:
- If the confirmation page has a stable route (e.g. `/message/1244/confirmed`), navigate there directly and capture it — no form submission needed.
- If there is no stable route, use a static PNG from a CI test screenshot artifact (Playwright attaches screenshots on failure; a successful run can produce them with `screenshot: on`). Reference it as a supplement in `storyboard.json`.
- Accept that some post-submit states cannot be filmed read-only, and narrate them in a narration scene instead.

### `networkidle` timeout (30 s) on a slow worktree

Reduce the wait condition for navigation. Add this to the shot's steps:

```json
{ "waitFor": "testid=some-stable-element-on-the-page" }
```

Then set the shot's route to include `?_noidlewait=1` if the app supports it, or rely on the `waitFor` alone (the `networkidle` on `goto` will still time out, but the `waitFor` ensures the screenshot happens after the element is visible).

Alternatively: restart the worktree's API container to clear any Go rebuild that is blocking responses, then re-run capture.