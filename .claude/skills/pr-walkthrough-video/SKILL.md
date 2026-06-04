---
name: pr-walkthrough-video
description: Use when asked to make an annotated walkthrough / preview VIDEO of a pull request — a calm, captioned, human-paced video that points at the changed UI (NOT a screen recording that whizzes through). Triggers - "walkthrough video", "preview video", "video of PR/the change", "annotate the PR", "show me what changed in a video".
---

# PR Walkthrough Video

Turn a PR into a calm, captioned walkthrough at human viewing speed, with callouts on the
changed UI. The tool lives at `pr-walkthrough/` (Remotion + Playwright). **You author the
script; the tooling does the mechanical parts and measures the coordinates.** Full design:
`plans/active/pr-walkthrough-capture-framework.md` and `pr-walkthrough/README.md`.

## Three rules that shape everything

1. **Externally-visible function only.** Show what a person using the product sees and does.
   No code, data models, APIs, migrations, diff stats. (A `code` scene type exists but is off.)
2. **Capture LIVE code, never the PR's embedded screenshots** — they go stale (fields get
   added/moved). The tool drives a *preexisting running worktree* of the PR, read-only.
3. **Show EVERY affected party.** Many features touch more than one role — two Freeglers (a
   giver *and* a recipient), or a Freegler *and* a moderator. Before filming, list the parties a
   change affects and show **each side**. A clearance isn't just the giver posting and the
   recipient ticking items — it's also the **giver receiving** the one consolidated message, and
   the **mod** previewing the bulk post. Don't film only one side of a two-sided interaction.

## What you decide vs what the tool does

- **You (judgement):** which screens/states to film, the selectors to drive + annotate, the
  captions, the timing, and which callouts to show. Use the PR's **tests** (esp. Playwright) to
  pick the flows that matter — don't miss an important surface that has a test.
- **The tool (mechanical):** fetch, login, drive the UI, **measure every callout box from the
  live DOM**, **compute each scene's zoom**, mask PII, render. You never eyeball coordinates.

## The tools (in `pr-walkthrough/`)

| step | command |
|---|---|
| fetch | `node src/fetch.mjs <pr> --repo <owner/repo>` → metadata, diff, test files, body images |
| function signal | `node src/analyze.mjs --pr-dir prs/pr-<pr>` → lists the PR's test titles (★=E2E) |
| find the app | `node src/discover.mjs --worktree <name>` → APP_URL / MODTOOLS_URL + readiness |
| log in | `node src/auth.mjs --base-url <url> --email <e> [--password freegle] --out prs/pr-<pr>/.auth-<role>.json` |
| capture | `node src/capture.mjs --pr-dir prs/pr-<pr> --base-url <url> [--storage-state <auth>]` → `assets/*.png` + `*.boxes.json` |
| render | `node src/render.mjs --pr-dir prs/pr-<pr>` → masks + resolves refs + auto-focus + MP4 |
| **golden tests** | `node src/plan-to-playwright.mjs --pr-dir prs/pr-<pr>` → a Playwright spec per shot (the same flows, as regression tests) |
| seeded env | `eval "$(node src/env-from-testenvs.mjs --env <key> --testenvs <path>)"` → seeded ids/users as `${ENV}` vars |
| embed | `node src/publish.mjs --pr-dir prs/pr-<pr>` (drag-drop into the PR for an inline player) |

## Runbook (any PR)

1. **Fetch** (mechanical): `node src/fetch.mjs <pr> --repo <owner/repo>`.
2. **Pick the shots** (judgement): read the diff; run `analyze` to see the test-covered flows.
   **First list the parties the change affects** (giver, recipient, mod, …) and ensure a shot
   for **each side** (rule 3) — e.g. giver-posts, recipient-expresses-interest, *giver-receives
   the message*, mod-previews. Then list the screens/states worth filming. Grep the diff for
   `data-testid=` — those are your selectors. (Different parties usually need different auth.)
3. **Find the running app** (mechanical): `node src/discover.mjs --worktree <name>`. If the
   frontend is down, `docker start <project>-dev-local` and wait for it to serve 200. Use
   **dev-local** (local API, seeded worktree DB) — never dev-live (live data).
4. **Auth + seed** (judgement, app-specific): most screens need a login + seeded data.
   - Credentials/ids: read the PR's `iznik-nuxt3/tests/e2e/test-envs.json` + `config.js`
     (seed password is usually `freegle`), or query the worktree DB:
     `docker exec <project>-percona mysql -uroot -piznik iznik -N -e "<query>"`.
   - `node src/auth.mjs ...` once per role (e.g. a non-owner user, a mod). Auth states are
     git-ignored (they hold tokens). For the *recipient* view, log in as someone who is NOT the
     poster, and use a seeded message id (pass via `--base-url` route `${ENV}` substitution).
5. **Write the capture-plan** (judgement): `prs/pr-<pr>/capture-plan.json`. Per shot:
   `{ name, route (with ${ENV} for ids), steps: [...], annotate: [{selector,label,arrow}] }`.
   - Steps drive by `testid=...` / `text=...` / CSS. They may fill + toggle to reach a state but
     **must not submit/save** (capture refuses submit clicks, so capturing can't write to the DB).
   - `annotate` lists the controls to highlight — capture measures their boxes for you.
6. **Capture** (mechanical): `BULK_MSG_ID=.. node src/capture.mjs --pr-dir prs/pr-<pr>
   --base-url $APP_URL --storage-state prs/pr-<pr>/.auth-user.json`. Check the produced
   screenshots + `assets/*.boxes.json`. Overlay the boxes to sanity-check placement (see Tips).
7. **Write the storyboard** (judgement): `prs/pr-<pr>/storyboard.json`. Title → a "why"
   narration → screenshot scenes → an "everywhere else" beat → outro. Each screenshot scene:
   `{ src, focusAuto: true, caption: "<one plain sentence>", callouts: [{at,until,ref:"<label>"}] }`.
   The renderer resolves each `ref` to the measured box and derives the zoom — you only write the
   caption, the timing, and which refs. Keep captions plain ("tick the items you want").
8. **Render** (mechanical): `node src/render.mjs --pr-dir prs/pr-<pr>`. It prints
   "resolved N callouts from tool-measured boxes" + "auto-computed focus for M scenes".
9. **Review** (judgement): extract frames and check each callout + caption against the real PR:
   `ffmpeg -ss <t> -i prs/pr-<pr>/out/*.mp4 -frames:v 1 /tmp/f.png` then Read it. Adjust the
   storyboard (timing/captions/refs) — re-render. Aim ~85–120s, scenes 8–13s, captions dwell ≥3s.
10. **Lock the golden flows as regression tests** (mechanical): a flow worth a walkthrough is
    worth a test. `node src/plan-to-playwright.mjs --pr-dir prs/pr-<pr>` turns each capture-plan
    shot into one Playwright `test()` (steps → actions; every `waitFor`/annotated selector →
    a `toBeVisible` assertion on the golden state). Review it and **propose it to the PR**
    (`iznik-nuxt3/tests/e2e/`) — don't edit the target worktree yourself.
11. **Deliver**: `publish.mjs` prints the embed markdown; copy to Downloads if asked
    (`cp prs/pr-<pr>/out/*.mp4 /mnt/c/Users/<you>/Downloads/`).

## Tips & gotchas (the tool handles these — know them)

- **Sanity-check measured boxes**: overlay them on the screenshot before rendering —
  `python3 -c "from PIL import Image,ImageDraw; import json; im=Image.open('a.png').convert('RGB'); d=json.load(open('a.boxes.json')); w,h=im.size; dr=ImageDraw.Draw(im); [dr.rectangle((b['box']['x']*w,b['box']['y']*h,(b['box']['x']+b['box']['w'])*w,(b['box']['y']+b['box']['h'])*h),outline=(255,90,0),width=4) for b in d.values()]; im.save('/tmp/overlay.png')"`.
- **Coordinates are document-relative + scroll-safe** (the tool fixed the viewport-relative
  `boundingBox` trap) and it scrolls to top before the shot so a sticky nav doesn't overlap.
- **Repeated-row editors** sometimes *prepend* new rows (`unshift`) — fill index 0 in reverse
  display order. Check the component if rows don't populate.
- **PII**: `masks.json` pixelates regions (fractions). Logged-in views often show no poster, so
  no mask is needed — but always check the captured image for names/avatars/emails.
- **`--analyzer claude`** can draft the storyboard from the diff+tests (spends tokens; opt-in).
- The worked reference is `prs/pr-618/` (bulk-offer clearance) — copy its shape for a new PR.
