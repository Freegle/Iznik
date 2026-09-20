---
last_reviewed: 2026-08-19
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

To regenerate one image rather than all of them, name it (comma-separate for several):

```bash
DOCS_SHOT=ask-start node tests/e2e/docs-screenshots.mjs
```

Worth doing when a change only touches one page: a full run rewrites every image, which
buries the one that actually changed in a diff of twenty binaries, and it needs the whole
stack up rather than the one page's.

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

## Keeping images fresh in CI

The runnable mechanism is built; wiring it into CircleCI is the remaining step.

**Built and ready:**

- `iznik-nuxt3/tests/e2e/docs-screenshots.mjs` - the generator (its `SHOTS` manifest).
- `regenerate-and-commit.sh` - regenerates, keeps only **meaningful** pixel changes
  (ImageMagick `compare`, tuned by `DOCS_PIXEL_THRESHOLD` / `DOCS_PIXEL_FUZZ`), and commits
  them back with `[skip ci]` so the push never starts a new pipeline. New shots are always
  kept; non-deterministic noise is reverted.

**The CI job to add:**

The orb's `run-playwright-tests` job already brings up the full seeded stack (`prod-local`,
`modtools-prod-local`, `apiv2`, `delivery`, `playwright`, ...) - the same environment where
login and the image proxy work, which they do not in an ad-hoc local stack. So a
screenshots job, on feature branches only (never `master`/`production`), should:

1. Reuse that stack bring-up.
2. Run the generator inside the `${COMPOSE_PROJECT_NAME}-playwright` container (it has the
   browsers and can reach the other containers on the internal network), pointing
   `TEST_BASE_URL` / `TEST_MODTOOLS_BASE_URL` at the internal `prod-local` /
   `modtools-prod-local` hostnames with the seeded `DOCS_*` credentials.
3. Copy the generated PNGs out of the container (`docker cp`) into the working tree.
4. Run `docs/screenshots/regenerate-and-commit.sh` (needs `imagemagick` installed and a
   `GITHUB_TOKEN` with push scope).

Because the commit carries `[skip ci]`, it does not re-run CI. Keep the job **off** the
required-checks list (a `[skip ci]` commit carries no CircleCI status) and **off** master
(so it never interferes with the auto-merge-to-production flow). This job changes the shared
test pipeline, so build and validate it on its own branch and PR before it lands - a CI
change can only be tested by running CI.
