---
last_reviewed: 2026-08-19
owner: Freegle dev team
covers:
  - docs/ops/reference/circleci.md
  - .circleci/**
  - docs/developers/reference/mobile-app.md
---

# Deployment and CI

This describes how code reaches production. The CI reference is
[./reference/circleci.md](./reference/circleci.md).

## The web pipeline

1. **Push to `master`.** CircleCI runs the full test suite (Go, PHPUnit, Laravel, Vitest,
   Playwright) via a shared reusable orb. New raw SQL is kept out at authoring time by the
   `.claude/check-raw-sql.sh` hook rather than by a CI gate - the ORM migration inventory
   ratchet that used to run here was retired once the Go migration reached zero raw sites.
2. **On green, `master` auto-merges to `production`.** This is automatic only when all
   tests pass.
3. **`production` deploys the frontends.** Two Netlify sites build from the same
   `production` branch with different build commands:
   - the **member site** (ilovefreegle.org) with `npm run build`, and
   - **ModTools** (modtools.org) with `cd modtools && npm run build`.
4. **Backend services** (the Go and PHP APIs and the Laravel batch app) deploy through
   their own path, separate from the Netlify frontend flow.

### Backend first

When a change spans both, deploy the **backend before** the frontend that depends on it,
so the frontend never calls an endpoint that is not there yet. This ordering rule is in
[../developers/reference/coding-standards.md](../developers/reference/coding-standards.md).

## The mobile app pipeline

Android (and iOS) builds run from the `production` branch. The Android flow builds the app
and uploads it to Google Play's internal track via Fastlane, with a weekly manual
promotion from internal to beta to production. Detail is in
[./reference/circleci.md](./reference/circleci.md) and [../developers/reference/mobile-app.md](../developers/reference/mobile-app.md).

## CI runner

CircleCI runs through a shared reusable **orb** (`freegle/tests`). Every branch runs the
full suite: the orb used to skip suites a change could not affect, but path-based skipping
was removed because a partial upload made Coveralls report a false large decrease against
master. An optional **self-hosted runner** speeds up builds, with automatic fallback to
cloud runners if it is unavailable.

Operational notes worth knowing:

- Docker build caching is controlled by a CI environment variable; bumping version
  suffixes in the orb invalidates the cache.
- When you change tests, **publish the orb** so CI picks the change up.
- SSH debugging of CI machines is available to the team, gated by their CircleCI
  credentials. The mechanics are internal and not reproduced here.

### When Coveralls goes red

Coveralls reports a percentage delta, never which statements moved, so a small decrease on
a commit that changes none of that language is not self-explaining. The Go suite in
particular is not reproducible run to run: it shares `iznik_go_test` with the Laravel suite
running at the same time, so load-dependent error and timeout branches are covered on one
run and not the next. One statement is roughly 0.004% of the Go total, which is enough to
turn the delta gate red on its own.

The build therefore keeps the Go profile as an artefact (`coverage-artifacts/`):
`go-coverage.out` carries per-block statement counts and is the one to diff between two
builds to find the moved line; `go-coverage.lcov` is the uploaded form. Diff the two builds
before treating a small delta as a regression - and equally, before dismissing it as noise.

## Rollback

Because `production` is a branch that deploys on update, and Netlify keeps previous
deploys, a bad frontend deploy can be rolled back by reverting on `production` or
redeploying a previous build. Backend rollback follows the backend deploy path. Keep the
backend-first ordering in mind when rolling back a paired change (roll the frontend back
first).
