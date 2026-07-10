---
last_reviewed: 2026-07-09
owner: Freegle dev team
---

# Screenshot automation

The images in the member and moderator guides are **generated from the running app by
Playwright**, not captured by hand. That is what keeps them current: when the UI changes,
you rerun the generator and the images change with it, in the same pull request.

The generator reuses the existing Playwright end to end setup (`iznik-nuxt3/tests/e2e`),
so there is no new tooling to install.

## Where things live

| Thing | Path |
|-------|------|
| The generator | [`iznik-nuxt3/tests/e2e/docs-screenshots.mjs`](../../iznik-nuxt3/tests/e2e/docs-screenshots.mjs) |
| The manifest (what to shoot) | the `SHOTS` array inside that file |
| Generated images | `docs/members/assets/` and `docs/moderators/assets/` |

Each entry in `SHOTS` maps one screenshot to one place in the docs: an audience, a name,
a URL path, and whether it needs a logged-out, member, or moderator session. To add or
change a screenshot, edit the manifest - do not paste an image in by hand.

## Running it

The script expects a running Freegle stack. It reads base URLs and test credentials from
environment variables, exactly like the end to end suite.

Against a local full stack (the default seeded test data, FreeglePlayground / Edinburgh):

```bash
cd iznik-nuxt3
TEST_BASE_URL=http://freegle-prod-local.localhost \
TEST_MODTOOLS_BASE_URL=http://modtools-prod-local.localhost \
DOCS_MEMBER_EMAIL=test@test.com     DOCS_MEMBER_PASSWORD=freegle \
DOCS_MOD_EMAIL=testmod@test.com     DOCS_MOD_PASSWORD=freegle \
node tests/e2e/docs-screenshots.mjs
```

Against a **worktree** (ports are offset by slot; get the real URL from `./freegle
status`):

```bash
cd iznik-nuxt3
TEST_BASE_URL=http://freegle-prod-local.localhost:PORT \
TEST_MODTOOLS_BASE_URL=http://modtools-prod-local.localhost:PORT \
node tests/e2e/docs-screenshots.mjs
```

Images are written to `docs/<audience>/assets/<name>.png`. Review the diff and commit the
images alongside whatever UI change prompted them.

## How the images stay deterministic

Screenshots are only useful in version control if they do not churn on every run. The
generator pins the things that would otherwise vary:

- a **fixed phone viewport** (390x844, since Freegle is mobile-first) and
  `reducedMotion: 'reduce'`,
- `animations: 'disabled'` and `caret: 'hide'` at capture time,
- a **fixed locale and timezone** (`en-GB`, `Europe/London`),
- the **seeded test account and data**, so the same posts appear each time,
- an optional `MASK` list for any element that is still variable (dates, avatars,
  live counts). It is empty by default; add selectors there if a shot proves noisy.

Run it against the **prod-local** container (the production build with the seeded test
database), not a dev container, to avoid hot-reload noise.

## Keeping images fresh in CI (recommended)

The lowest-friction way to stop screenshots going stale is a **drift gate**:

1. On a pull request touching `iznik-nuxt3/pages/`, `iznik-nuxt3/components/` or
   `iznik-nuxt3/modtools/`, bring up the prod container and run the generator into a
   temporary directory.
2. Pixel-diff the result against the committed images.
3. If anything moved beyond a small threshold, **fail the check** so a human regenerates
   and commits the images in that same pull request. Do not auto-commit silently.

This mirrors how the CircleCI orb version bumps already work: the change and its
consequence are reviewed together. A weekly cron run catches drift from data or
dependency changes that no single pull request touched.
