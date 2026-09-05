---
last_reviewed: 2026-09-02
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

```mermaid
flowchart LR
    PR["Pull request"] --> M["push to master"]
    M --> CI["CircleCI<br/>Go, Laravel, Vitest, Playwright"]
    CI -->|all green| PROD["auto-merge to<br/>production branch"]
    CI -->|red| STOP["stops here"]
    PROD --> N1["Netlify:<br/>member site"]
    PROD --> N2["Netlify:<br/>ModTools"]
    PROD --> APP["Fastlane:<br/>Android + iOS builds"]
    BE["Backend services<br/>Go API, batch"] -.->|separate path,<br/>deploy first| N1
```


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
- When you change tests, **publish the orb** so CI picks the change up. Editing the
  orb source in this repo changes nothing on its own: CircleCI resolves the orb from
  the registry, and the pin in `continue-config.yml` is what decides which published
  version runs. An unpublished orb change is inert, not broken.
- **Do not write the next version number into a commit message before publishing it.**
  Versions are immutable and first-come, so a branch can take the number another
  branch was about to use, and the second change then goes out under no version at
  all. Publish, confirm with `circleci orb info freegle/tests`, then bump the pin.
- **The runner keeps its working copy between builds, so a green build is only worth
  as much as its checkout.** The checkout step fetches, checks the branch out, and
  then compares HEAD with `CIRCLE_SHA1`. That last comparison is not belt and braces:
  a fetch that failed silently used to leave the previous build's refs in place, and
  the branch checked out against them, so master reported success having tested its
  own parent commit. If a build fails saying the checked-out commit is not the one it
  was triggered for, the fetch is the thing to look at, not the workspace.
- **The fetch authenticates with `GITHUB_TOKEN` when it is set.** The repository is
  public, so this is about rate limiting rather than access: an anonymous fetch that
  github.com throttles reaches git as a credentials prompt and a protocol error, not
  as a clear refusal.
- A pin published from a branch carries that branch's orb changes. Master must not
  adopt it until the branch merges, or master's jobs run steps for code it does not
  have.
- SSH debugging of CI machines is available to the team, gated by their CircleCI
  credentials. The mechanics are internal and not reproduced here.

### When Coveralls goes red

Coveralls reports a percentage delta, never which statements moved, so a small decrease on
a commit that changes none of that language is not self-explaining. The Go suite in
particular is not reproducible run to run: it shares `iznik_go_test` with the Laravel suite
running at the same time, so load-dependent error and timeout branches are covered on one
run and not the next. One statement is roughly 0.004% of the Go total, which is enough to
turn the delta gate red on its own.

**The profile is already kept, and diffing it is the whole answer.** Every
`build-and-test` stores `~/artifacts/go/coverage.out` - per-block statement counts, which
is exactly what a build-to-build diff needs. Fetch it from two builds and compare, rather
than reasoning about what a percentage might mean:

```
curl -sS "https://circleci.com/api/v2/project/gh/Freegle/Iznik/<job>/artifacts"   # find the url
```

Both the job API and the artifact download work without a CircleCI token.

The Go coverage run is serialised (`-p 1`, in
`status-nuxt/server/api/tests/go.post.ts`) because the suite's packages otherwise hit the
single `iznik_go_test` database concurrently, on a runner already running iznik-batch,
Playwright and Vitest alongside them. It costs no wall clock, since Playwright is the
critical path, but it does not make the coverage number reproducible on its own.

The same delta gate can go red on the other suites for the same reason. The cheapest check
is whether the failing suite's language appears in the diff at all: a Go-only commit that
turns `Coveralls - vitest` red has not changed any JavaScript, so the number moved without
the code moving.

## Rollback

Because `production` is a branch that deploys on update, and Netlify keeps previous
deploys, a bad frontend deploy can be rolled back by reverting on `production` or
redeploying a previous build. Backend rollback follows the backend deploy path. Keep the
backend-first ordering in mind when rolling back a paired change (roll the frontend back
first).
