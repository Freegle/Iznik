---
last_reviewed: 2026-08-01
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
   Playwright) via a shared reusable orb. New raw SQL is kept out by
   `.claude/check-raw-sql.sh`, which runs in two places: as an authoring-time Claude Code
   hook, and as the `check-raw-sql` CI job (`--diff` mode) that scans the branch and fails
   the build. The CI job is the one that actually holds the line - the authoring hook only
   sees edits made through that one tool. The ORM migration inventory ratchet that used to
   run here was retired once the Go conversion work finished; note that this did *not* mean
   zero raw SQL, as about 44 deliberate sites remain (the manifest's "raw: 0" counted
   untriaged sites, not raw statements).

   **Coverage deliberately given up when the ratchet went.** Retiring the harness also
   removed seven "Tier 9" Go tests (`*_tier9_test.go` in `logs/`, `message/`, `session/`)
   that proved production query builders - `buildGetLogsQuery`, `buildInsertViewBatchQuery`
   and friends - correct across many field and parameter combinations, by independently
   reconstructing the expected SQL. Those builders still exist and are still exercised by
   the ordinary integration tests, but no longer combinatorially. Reviving them would mean
   keeping ~2,200 lines of `ormharness` (golden, fieldwise, wherefieldwise,
   parametrizedshape, canonical) alive purely for them, which is the machinery this change
   set out to remove. Recorded here so the trade is visible rather than silent.
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

CircleCI runs through a shared reusable **orb** (`freegle/tests`). On branches other than
master, path-based rules skip suites that a change cannot affect; master always runs the
full suite. An optional **self-hosted runner** speeds up builds, with automatic fallback
to cloud runners if it is unavailable.

Operational notes worth knowing:

- Docker build caching is controlled by a CI environment variable; bumping version
  suffixes in the orb invalidates the cache.
- When you change tests, **publish the orb** so CI picks the change up.
- SSH debugging of CI machines is available to the team, gated by their CircleCI
  credentials. The mechanics are internal and not reproduced here.

## Rollback

Because `production` is a branch that deploys on update, and Netlify keeps previous
deploys, a bad frontend deploy can be rolled back by reverting on `production` or
redeploying a previous build. Backend rollback follows the backend deploy path. Keep the
backend-first ordering in mind when rolling back a paired change (roll the frontend back
first).
