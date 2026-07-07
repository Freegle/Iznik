# Monorepo Migration Plan

## Motivation

Submodule pointers are commit hashes with no branch awareness. On 2026-03-20, FreegleDocker's iznik-nuxt3 pointer was accidentally on a feature branch commit (11 workers, 29 test files) instead of master (1 worker, 21 test files). CI passed for days with the wrong code. PR merges corrected the pointer and "broke" CI — but it was actually a correction. This class of bug is inherent to submodules.

## Current Structure

```
FreegleDocker/               (parent repo)
├── .circleci/
├── iznik-server/            (submodule → Freegle/iznik-server, frozen/legacy)
├── iznik-server-go/         (submodule → Freegle/iznik-server-go)
├── iznik-nuxt3/             (submodule → Freegle/iznik-nuxt3)
├── iznik-batch/             (already a regular directory, not a submodule)
├── status-nuxt/
├── docker-compose.yml
└── ...
```

Three submodules: `iznik-server`, `iznik-server-go`, `iznik-nuxt3`. `iznik-batch` is already inline.

## Approach: Single Cutover

No shadow period. Build the monorepo on a branch, verify everything works, merge to master, archive the old repos. Directory names stay the same (`iznik-nuxt3/`, `iznik-server-go/`, `iznik-server/`). Full git history from each sub-repo is preserved and visible via `git log -- <path>`.

At cutover, rename `Freegle/FreegleDocker` → `Freegle/iznik`. GitHub maintains a redirect from the old URL, so existing clones, bookmarks, and CI configs continue to work until updated.

## Assumptions

- **iznik-server is frozen.** No CI pipeline needed for PHP V1 changes. It's pulled in for completeness and will be removed when the V2 migration finishes.
- **iznik-batch is already inline.** No migration work needed.
- **Claude does the CI rewrite.** Time estimates reflect AI execution, not human effort.

---

## Phase 1: Build the Monorepo Branch

**Estimated time: 1-2 hours**

### 1.1 Create migration branch

```bash
git checkout -b monorepo-migration
```

### 1.2 Import each sub-repo's history via `git subtree add`

For each submodule, we remove the submodule registration, then re-add the content as a subtree with full history:

```bash
# For each of: iznik-server, iznik-server-go, iznik-nuxt3

# 1. Remove submodule registration (keeps the directory)
git submodule deinit -f <name>
git rm -f <name>
rm -rf .git/modules/<name>

# 2. Add back as subtree with full history
git subtree add --prefix=<name> https://github.com/Freegle/<name>.git master
```

`git subtree add` creates a merge commit that grafts the sub-repo's entire commit history into the monorepo. After this, `git log -- iznik-server-go/microvolunteering/microvolunteering.go` shows every commit that touched that file in the original repo.

### 1.3 Remove submodule infrastructure

```bash
# Submodule config
git rm .gitmodules

# Submodule webhook workflows (now inside the monorepo after subtree import)
git rm iznik-nuxt3/.github/workflows/trigger-parent-ci.yml
git rm iznik-server-go/.github/workflows/trigger-parent-ci.yml
git rm iznik-server/.github/workflows/trigger-parent-ci.yml

# Push-ordering scripts and hooks (no longer needed — single repo)
git rm push-with-submodules.sh
git rm setup-hooks.sh
git rm setup-hooks.cmd

# Git hooks (not tracked by git, delete manually)
rm -f .git/hooks/pre-push .git/hooks/post-checkout

git commit -m "chore: remove submodule infrastructure"
```

### 1.4 Update the freegle CLI

The `freegle worktree create` command (line ~138) runs `git submodule foreach` to fix CRLF in submodule containers. Replace with a plain directory iteration:

```bash
# Before:
git -C "$worktree_dir" submodule foreach --quiet \
    'git config core.autocrlf false && git checkout -- .' 2>/dev/null || true

# After:
for dir in iznik-nuxt3 iznik-server-go iznik-server; do
    git -C "$worktree_dir/$dir" config core.autocrlf false 2>/dev/null || true
done
```

### 1.5 Update documentation

These files reference submodules and need updating:

| File | Change |
|------|--------|
| `README.md` | Replace `git clone --recurse-submodules` with plain `git clone`. Delete "Git Hooks Setup" section. |
| `CLAUDE.md` | Remove "Submodule webhooks" line, `FREEGLE_DOCKER_TOKEN` reference, "never in submodules" line. Add monorepo note. |
| `.circleci/README.md` | Rewrite: remove submodule PR testing section, webhook integration, GitHub token docs. |
| `.circleci/orb/README.md` | Remove `clone-freegle-docker`, `replace-submodule`, `align-submodule-branches` command docs. |
| `PHPSTORM-GIT-SETUP.md` | Delete or simplify — the entire doc is about submodule push ordering. |
| `WORKTREE-GUIDE.md` | Remove submodule-specific instructions. Worktrees still work, just no submodule init step. |
| `codingstandards.md` | Scan for any submodule references, update if found. |

### 1.6 Retire FREEGLE_DOCKER_TOKEN secret

After cutover, remove the `FREEGLE_DOCKER_TOKEN` secret from GitHub settings on:
- `Freegle/iznik-nuxt3`
- `Freegle/iznik-server-go`
- `Freegle/iznik-server`

(Not needed during branch testing — the workflows are already deleted from the monorepo.)

### 1.7 Verify history

```bash
# Each should show the full sub-repo history
git log --oneline -- iznik-nuxt3/ | head -20
git log --oneline -- iznik-server-go/ | head -20
git log --oneline -- iznik-server/ | head -20

# Blame should work
git blame iznik-server-go/microvolunteering/microvolunteering.go
```

---

## Phase 2: Rewrite CircleCI Configuration

**Estimated time: 2-3 hours**

### What changes

The current orb (`freegle-tests.yml`, 3500+ lines) has extensive submodule machinery that becomes dead code in a monorepo. Here's the complete inventory of what to remove, modify, and keep.

### 2.1 Commands to DELETE (submodule machinery)

| Command | Lines | Purpose | Why it's gone |
|---------|-------|---------|---------------|
| `clone-freegle-docker` | 300-338 | Clone parent repo for sub-repo PR testing | PRs are now in the same repo |
| `replace-submodule` | 340-356 | Swap submodule dir with PR code | No submodules to swap |
| `align-submodule-branches` | 358-408 | Check if matching branch exists in other submodules | All code in one repo |
| `rebuild-aligned-containers` | 410-440 | Rebuild containers after branch alignment | No alignment step |
| `build-submodule-container` | 442-455 | Build a single container after submodule swap | Replaced by normal docker-compose build |

### 2.2 Jobs to DELETE (sub-repo PR testing)

| Job | Lines | Purpose | Replacement |
|-----|-------|---------|-------------|
| `php-tests` | 1825-1841 | Test iznik-server PRs | Dead — iznik-server is frozen |
| `go-tests` | 1843-1860 | Test iznik-server-go PRs | Merged into `build-and-test` with path filter |
| `playwright-tests` | 1862-1895 | Test iznik-nuxt3 PRs | Merged into `build-and-test` with path filter |

### 2.3 Modify `build-and-test` job

**Remove** (lines 1946-1950):
```bash
git submodule init
git submodule update --recursive --jobs 4
git submodule status
```

The code is already in the repo after subtree import — no submodule init needed.

**Add: path-based test skipping.** Instead of CircleCI's `path-filtering` orb (which requires dynamic config — a full pipeline restructure), use simple shell logic within the existing job:

```bash
# Determine what changed
CHANGED=$(git diff --name-only HEAD~1 HEAD 2>/dev/null || echo "ALL")

SKIP_GO=true
SKIP_VITEST=true
SKIP_PLAYWRIGHT=true
SKIP_LARAVEL=true

if echo "$CHANGED" | grep -q '^iznik-server-go/'; then SKIP_GO=false; fi
if echo "$CHANGED" | grep -q '^iznik-nuxt3/'; then SKIP_VITEST=false; SKIP_PLAYWRIGHT=false; fi
if echo "$CHANGED" | grep -q '^iznik-batch/'; then SKIP_LARAVEL=false; fi

# Shared files (docker-compose, CI config, scripts) → run everything
if echo "$CHANGED" | grep -qE '^(docker-compose|\.circleci|scripts/|testenv)'; then
  SKIP_GO=false; SKIP_VITEST=false; SKIP_PLAYWRIGHT=false; SKIP_LARAVEL=false
fi

# Master branch always runs everything
if [ "$CIRCLE_BRANCH" = "master" ]; then
  SKIP_GO=false; SKIP_VITEST=false; SKIP_PLAYWRIGHT=false; SKIP_LARAVEL=false
fi

# Export for use in later steps
echo "export SKIP_GO=$SKIP_GO" >> "$BASH_ENV"
echo "export SKIP_VITEST=$SKIP_VITEST" >> "$BASH_ENV"
echo "export SKIP_PLAYWRIGHT=$SKIP_PLAYWRIGHT" >> "$BASH_ENV"
echo "export SKIP_LARAVEL=$SKIP_LARAVEL" >> "$BASH_ENV"
```

Each test step then wraps its execution:

```bash
if [ "$SKIP_GO" = "true" ]; then
  echo "⏭️ Skipping Go tests — no changes in iznik-server-go/"
  exit 0
fi
# ... existing test commands ...
```

This avoids the dynamic config rewrite entirely. The job still runs on every push, but skips irrelevant test suites quickly.

### 2.4 Modify auto-merge step

**Current** (line 2654): `cd iznik-nuxt3` then merge master→production *within the iznik-nuxt3 submodule*.

**New**: The production branch is on the monorepo itself. Netlify deploys from the monorepo's production branch with base directory `iznik-nuxt3/`.

```bash
# Replace the cd iznik-nuxt3 block with:
echo "🔀 Auto-merging master to production..."
git config --global user.email "circleci@freegle.org"
git config --global user.name "CircleCI Auto-merge"
git fetch origin production:production
git checkout production
MASTER_COMMIT_MSG=$(git log master -1 --pretty=%B | head -n 1)
if git merge master -m "Auto-merge master to production - $MASTER_COMMIT_MSG"; then
  git push origin production || { echo "❌ Push failed"; exit 1; }
else
  echo "❌ Merge conflicts"; git merge --abort; exit 1
fi
```

### 2.5 Modify `.circleci/config.yml`

Currently delegates entirely to the orb's `build-and-test` job. No structural change needed — the orb itself is what gets rewritten.

### 2.6 Merge iznik-nuxt3's `.circleci/config.yml` into the monorepo

iznik-nuxt3 has its own `.circleci/config.yml` (~1672 lines) with mobile app build workflows that are **completely separate** from FreegleDocker's test workflows. In a monorepo there's only one `.circleci/config.yml` per repo. These must be merged.

**Workflows to merge from iznik-nuxt3:**

| Workflow | Trigger | Purpose |
|----------|---------|---------|
| `build-android-apps` | Push to any branch | Debug APK build for testing |
| `pr-tests` | PR events | Playwright tests via `freegle/playwright-tests` orb |
| `deploy-apps` | Push to `production` branch | `increment-version` → `build-android` + `build-ios` → `check-hotfix-promote` |
| `weekly-promote-schedule` | Weekly cron | Auto-promote beta to production |
| `manual-promote-submit` | Pipeline param `run_manual_promote` | Manual promote trigger |
| `testflight-branch` | Pipeline param `build_testflight` | Build iOS from any branch |

**Jobs to merge:** `build-android-debug`, `build-android`, `build-ios`, `increment-version`, `auto-promote-to-production`, `check-hotfix-promote`.

**Commands to merge:** `setup-android-fastlane`, `setup-node-capacitor`, `setup-ios`, `build-android-apk`, `build-android-aab`, `prepare-capacitor-project`.

**Pipeline parameters to add:** `run_manual_promote` (boolean), `build_testflight` (boolean).

All mobile jobs need `working_directory: ~/project/iznik-nuxt3` or `cd iznik-nuxt3 &&` prefixes. Fastlane configs (`Fastfile`, `Appfile`) are relative to the app directory and don't change.

**`app-ci-fd` branch concept:** The `auto-merge-master.yml` GitHub Action merges `production` → `app-ci-fd` daily and triggers CircleCI on that branch. After monorepo migration, the CircleCI API URL changes from `Freegle/iznik-nuxt3` to `Freegle/iznik`. The `app-ci-fd` branch continues to exist on the monorepo.

After subtree import, delete `iznik-nuxt3/.circleci/config.yml` (now inert — CircleCI only reads from repo root).

### 2.7 Consolidate CircleCI secrets

In the monorepo there's ONE CircleCI project. All env vars from both the FreegleDocker and iznik-nuxt3 CircleCI projects must be set on the single project.

**From FreegleDocker CircleCI project:**

| Secret | Purpose |
|--------|---------|
| `COVERALLS_REPO_TOKEN` | Coverage upload for Go, Vitest, Playwright, Laravel |
| `COVERALLS_REPO_TOKEN_IZNIK_SERVER` | Coverage upload for iznik-server PHP tests |
| `SENTRY_DSN_APP_FD` | Sentry DSN for mobile app builds |
| `ENABLE_DOCKER_CACHE` | Controls Docker layer caching |

**From iznik-nuxt3 CircleCI project (19 secrets, all copied 2026-04-13):**

| Secret | Purpose |
|--------|---------|
| `ANDROID_KEYSTORE_BASE64` | Android signing keystore (base64) |
| `ANDROID_KEYSTORE_PASSWORD` | Android keystore password |
| `ANDROID_KEY_ALIAS` | Android signing key alias |
| `ANDROID_KEY_PASSWORD` | Android signing key password |
| `APP_STORE_CONNECT_API_KEY_ISSUER_ID` | Apple App Store Connect API issuer |
| `APP_STORE_CONNECT_API_KEY_KEY` | Apple App Store Connect API key (base64) |
| `APP_STORE_CONNECT_API_KEY_KEY_ID` | Apple App Store Connect key ID |
| `CIRCLECI_API_TOKEN` | CircleCI PAT — used to update CURRENT_VERSION via API |
| `CURRENT_VERSION` | Auto-updated after each Fastlane release (e.g. 100.0.287) |
| `FASTLANE_APPLE_ID` | Apple ID for Fastlane |
| `FASTLANE_TEAM_ID` | Apple Developer Team ID |
| `GOOGLE_PLAY_JSON_KEY` | Google Play service account key (base64) |
| `GOOGLE_SERVICES_JSON_BASE64` | Firebase google-services.json (base64) |
| `GOOGLE_SERVICE_INFO_PLIST_BASE64` | iOS GoogleService-Info.plist (base64) |
| `IOS_CERTIFICATE_PASSWORD` | iOS distribution certificate password |
| `IOS_DISTRIBUTION_CERT` | iOS distribution certificate (base64) |
| `IOS_PROVISIONING_PROFILE` | iOS provisioning profile (base64) |
| `SENTRY_DSN_APP_FD` | Sentry DSN for mobile app |
| `STRIPE_PUBLISHABLE_KEY` | Stripe live publishable key |

**Retired:**

| Secret | Why |
|--------|-----|
| `FREEGLE_DOCKER_TOKEN` | Was used by sub-repos to trigger parent CI; no sub-repos in monorepo |

**URL update required:** `increment-version` job calls `https://circleci.com/api/v2/project/gh/Freegle/iznik-nuxt3/envvar/CURRENT_VERSION` — change project slug to `gh/Freegle/FreegleDocker` (then `gh/Freegle/iznik` after rename).

### 2.8 ~~Extract secrets from iznik-nuxt3 via SSH~~ DONE

All 19 secrets extracted via SSH into the `build-android` CircleCI job and set on the FreegleDocker CircleCI project via API. Completed 2026-04-13.

### 2.9 Human-only steps

After all CI work is done, only two tasks require GitHub admin access:
1. **Rename repo**: `Freegle/FreegleDocker` → `Freegle/iznik` (GitHub Settings → General)
2. **Archive old repos**: `Freegle/iznik-nuxt3`, `Freegle/iznik-server-go`, `Freegle/iznik-server` (GitHub Settings → Danger Zone)

---

## Phase 3: Update Coveralls

**Estimated time: 15 minutes**

Coverage uploads already use path-prefixed lcov files (fixed in the April 11 coverage work). The paths (`iznik-server-go/`, `iznik-nuxt3/`, `iznik-batch/`) stay the same in the monorepo, so coverage uploads should work unchanged. Verify after first CI run.

---

## Phase 4: Update Sentry

**Estimated time: 15 minutes**

Sentry source map uploads reference paths relative to the build output. Since the build runs from `iznik-nuxt3/` with the same commands, no changes needed. Verify release tagging uses the correct commit SHA (monorepo's, not sub-repo's).

---

## Phase 5: Update GitHub Actions Workflows

**Estimated time: 30 minutes**

The trigger-parent-ci.yml deletions are in Phase 1.3. The remaining workflows need updating:

### 6.1 `.github/workflows/build-base-image.yml`

Remove `submodules: true` from the checkout step (line 26) — no submodules to init.

### 6.2 `iznik-nuxt3/.github/workflows/auto-merge-master.yml`

This merges production→app-ci-fd daily and triggers a CircleCI build. Two changes:
- Line 61: Change CircleCI API URL from `Freegle/iznik-nuxt3` to `Freegle/iznik`
- The workflow currently lives in iznik-nuxt3's `.github/workflows/`. After subtree import it's at `iznik-nuxt3/.github/workflows/auto-merge-master.yml` — GitHub only runs workflows from the repo root's `.github/workflows/`. **Move it to `.github/workflows/auto-merge-app-ci-fd.yml`**.

### 6.3 `iznik-batch/.github/workflows/update-version.yml`

Increments `version.txt` on every push to master. After monorepo migration, this triggers on *any* master push, not just iznik-batch changes. Add a path filter:

```yaml
on:
  push:
    branches:
      - master
    paths:
      - 'iznik-batch/**'
```

Also: this workflow is at `iznik-batch/.github/workflows/` — same issue as 6.2, **move to `.github/workflows/iznik-batch-version.yml`** and update paths in the script.

### 6.4 General: nested `.github/workflows/` directories

GitHub only processes workflows in the repo root's `.github/workflows/`. After subtree import, workflows inside `iznik-nuxt3/.github/`, `iznik-server-go/.github/`, etc. are inert — they won't run. This is safe (they're deleted or moved), but verify no wanted workflow is accidentally left in a subdirectory.

---

## Phase 6: Local Dev Verification

**Estimated time: 2 hours**

Before merging, verify the monorepo works end-to-end.

### 6.1 Worktree test

Create a worktree from the `monorepo-migration` branch to verify the full system comes up in an isolated environment:

```bash
./freegle worktree create mono-test
```

In the worktree, verify:
- [ ] `docker-compose up -d` — all containers build and start
- [ ] Go tests pass (via status API)
- [ ] Vitest tests pass
- [ ] Laravel tests pass
- [ ] Playwright tests pass
- [ ] HMR works (edit a Vue component, see change in browser)
- [ ] Go API rebuild works (edit a Go file, rebuild apiv2 container, verify change)

Then clean up: `./freegle worktree remove mono-test`

### 6.2 History verification

- [ ] `git log -m --follow -- iznik-nuxt3/components/` shows original sub-repo commit history
- [ ] `git log -m --follow -- iznik-server-go/microvolunteering/` shows original commit history
- [ ] `git blame` works on files from each sub-repo (shows original authors/dates)

### 6.3 CI dry run

Push the `monorepo-migration` branch to origin and verify CI runs successfully:
- [ ] Push branch (does NOT affect master or production)
- [ ] CircleCI `build-test` workflow triggers and passes
- [ ] Path-skip logic works (only relevant test suites run for the changed paths)
- [ ] Coverage uploads succeed

### 6.4 Netlify build test

- [ ] Create a temporary Netlify site pointed at `Freegle/FreegleDocker` branch `monorepo-migration`, base dir `iznik-nuxt3/`
- [ ] Verify the build completes successfully
- [ ] Delete the temporary site

### 6.5 Yesterday system

- [ ] Yesterday system works (`docker-compose.override.yesterday.yml`)

---

## Phase 7: Migrate Open PRs and Issues

**Estimated time: 1 hour**

Before archiving the old repos, migrate all open PRs and issues to the monorepo.

### 8.1 Open PRs (4)

Each open PR's branch already exists in the sub-repo. After subtree import, the branch history is in the monorepo but the *branch itself* isn't. For each PR:

1. Fetch the branch from the sub-repo remote
2. Create a monorepo branch with the same changes (rebased onto monorepo master, scoped to the correct subdirectory)
3. Open a new PR on the monorepo referencing the original
4. Close the original PR with a comment linking to the new one

| Repo | PR | Branch | Title |
|------|----|--------|-------|
| iznik-nuxt3 | #230 | `feature/partnerships-page` | Add /partnerships page |
| iznik-nuxt3 | #203 | `feature/cls-improvements` | perf(cls): fix layout shifts |
| iznik-nuxt3 | #200 | `feature/charity-badge` | feat: Charity Partner landing page |
| iznik-server-go | #44 | `feature/migrate-http-endpoints` | feat: Migrate V1 HTTP endpoints |

### 8.2 Open Issues (18)

Use `gh issue transfer` where possible (same org). If transfer isn't available across repos, create new issues on the monorepo with the original content and a backlink, then close originals.

| Repo | Count | Issues |
|------|-------|--------|
| iznik-nuxt3 | 10 | #198, #177, #147, #142, #141, #137, #126, #93, #81, #51 |
| iznik-server-go | 4 | #43, #42, #39, #5 |
| iznik-server | 4 | #55, #53, #52, #51 |

Each transferred/created issue should note its origin (e.g. "Migrated from Freegle/iznik-nuxt3#147").

---

## Phase 8: Cutover

**Estimated time: 30 minutes**

1. Merge `monorepo-migration` branch to master
2. Push to origin — triggers CI
3. Verify CI passes (all test suites)
4. Verify auto-merge to production works
5. Rename repo: `Freegle/FreegleDocker` → `Freegle/iznik` (GitHub Settings → General → Repository name). GitHub creates an automatic redirect from the old URL.
6. Repoint Netlify sites (two sites, same change):
   ```bash
   # Freegle site (golden-caramel-d2c3a7)
   curl -X PATCH "https://api.netlify.com/api/v1/sites/75fa22f1-3d32-4474-a3fc-65afbd7f4f43" \
     -H "Authorization: Bearer $NETLIFY_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"repo":{"repo_url":"https://github.com/Freegle/iznik","repo_branch":"production","base_rel_dir":"iznik-nuxt3/"}}'

   # ModTools site (modtools-org)
   curl -X PATCH "https://api.netlify.com/api/v1/sites/516c2438-4185-40e5-b3eb-c5728368ed53" \
     -H "Authorization: Bearer $NETLIFY_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"repo":{"repo_url":"https://github.com/Freegle/iznik","repo_branch":"production","base_rel_dir":"iznik-nuxt3/"}}'
   ```
7. Verify Netlify deploys successfully for both Freegle and ModTools
8. Archive old repos on GitHub (Settings → Archive):
   - `Freegle/iznik-nuxt3` → read-only
   - `Freegle/iznik-server-go` → read-only
   - `Freegle/iznik-server` → read-only
9. Update README in each archived repo: "This repo has been merged into Freegle/iznik"
10. Update local clone remotes: `git remote set-url origin https://github.com/Freegle/iznik.git`

---

## What Stays the Same

- Directory names: `iznik-nuxt3/`, `iznik-server-go/`, `iznik-server/`, `iznik-batch/`
- Docker Compose: all paths, volume mounts, build contexts unchanged
- Dockerfiles: unchanged
- `.env` and port configuration: unchanged
- Worktree system: unchanged
- Yesterday system: unchanged
- Dev workflow: `git pull` instead of `git pull && git submodule update`

## What's Simpler After Migration

- No submodule pointer drift bugs
- No `trigger-parent-ci.yml` webhook choreography
- No `clone-freegle-docker` + `replace-submodule` pattern for PR testing
- PRs that span Go API + frontend are a single PR
- `git diff` shows all changes, not just pointer updates
- New contributors: `git clone` just works, no `--recurse-submodules`

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| `git subtree add` merge commits clutter root log | Low | Use `git log -- <path>` for per-directory history |
| CI path-skip logic has edge cases | Medium | Master always runs all tests; path skip is PR optimization only |
| Netlify doesn't detect base directory changes | Medium | Test with a preview deploy before cutover |
| Open PRs on sub-repos become orphaned | Medium | Phase 8 migrates all 4 PRs and 18 issues before archiving |
| Repo size increases | Low | Combined history is large but GitHub handles it fine |

## Time Estimate

| Phase | Time |
|-------|------|
| 1. Build monorepo branch | 1-2 hours |
| 2. Rewrite CircleCI (incl. mobile build merge + secrets) | 3-4 hours |
| 3. Update Coveralls | 15 min |
| 4. Update Sentry | 15 min |
| 5. Update GitHub Actions | 30 min |
| 6. Local dev verification (worktree, containers, CI) | 2 hours |
| 7. Migrate PRs and issues | 1 hour |
| 8. Cutover (incl. Netlify repoint) | 30 min |
| **Total** | **8-10 hours** |

All phases executed by Claude. Human-only: repo rename and archiving (Phase 9, steps 5 + 8).
