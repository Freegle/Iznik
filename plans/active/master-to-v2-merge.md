# Merge Master CSS/Style Changes into V2 Branch

## Strategy

Cherry-pick from master into `feature/v2-unified-migration`, one commit at a time.
The V2 branch has different JavaScript/API code (V2 API calls, props-to-IDs, etc.)
but CSS/style changes should apply cleanly since they only touch templates and SCSS.

**CRITICAL**: Never auto-merge. Each cherry-pick must be reviewed for conflicts
with V2 code before accepting.

## Commit Categories

### Phase 1: Safe CSS-only commits (templates + SCSS only)
These change Bootstrap class names (mr→me, font-weight-bold→fw-bold),
color variables, border-radius, etc. They should not conflict with V2 logic.

- [ ] `3850d779` style: Modernize CSS design system
- [ ] `3eff4db2` style: Warm community feel
- [ ] `d9451dca` style: Fix remaining square corners
- [ ] `de267b92` style: Comprehensive design token migration
- [ ] `03794a57` style: Sync modtools design tokens
- [ ] `fe1b11f0` style: Migrate BS4 classes
- [ ] `a9429f42` style: Standardize color variable naming
- [ ] `984790d8` style: Fix barely-visible Photo button
- [ ] `484ae391` style: Improve chat page contrast
- [ ] `c6a74929` style: Improve empty states and text contrast
- [ ] `3ae60cf4` style: Fix square corners on filters/chat/message cards
- [ ] `4dff48c3` style: Add border-radius to custom buttons
- [ ] `cba2d986` style: Consolidate green palette
- [ ] `50b869f2` style: Remove legacy #0000FF link color
- [ ] `059923a7` style: Fix WCAG contrast
- [ ] `e9e711af` fix: Input-group radius
- [ ] `ad4f961e` style: Global default radius
- [ ] `191095ec` style: Add squircle corner-shape
- [ ] `c1e36546` style: Add border-radius to photo count pills
- [ ] `964b1c0b` style: Fix social login buttons
- [ ] `d3a656a2` fix: Restore navbar badge icon-overlap offsets
- [ ] `02a5acaf` style: Extract hardcoded toggle color
- [ ] `cc3fb084` style: Fix more hardcoded colors
- [ ] `d274ad1c` style: Round 2 improvements
- [ ] `05597574` style: Add rounded corners to story cards

### Phase 2: Docs/chore (safe, no code)
- [ ] `05ddada9` docs: Rewrite style board
- [ ] `144c45f7` docs: Update style board
- [ ] `57aee651` docs: Update style audit
- [ ] `ea76787e` chore: Remove adversarial style audit document

### Phase 3: Fix/feat commits (need careful review)
- [ ] `d98a2d9e` fix: Remove UserRatings force-fetch
- [ ] `f69d30d1` fix: ModMember.vue CSS custom property
- [ ] `2b3dffd3` feat: Add marketing opt-out page
- [ ] `45a85b0e` fix: Update registered address
- [ ] `f6f34be9` fix: Capture scroll events
- [ ] `1062cccd` fix: Guard fetchUser against uninitialized $api
- [ ] `0491fe1e` fix: Add status-reporter.js
- [ ] `21e8fc27` fix: Update Vitest assertions for BS4→BS5

### Phase 4: Test infrastructure (careful - V2 has different test files)
- [ ] `9dbedab6` fix: Social login test
- [ ] `a9e447c1` fix: Remove test.skip from social login test
- [ ] `baa8d2db` fix: Run Playwright tests against prod containers
- [ ] `1aa6f0b7` fix: Broaden allowed error patterns
- [ ] `49814f41` fix: Add loginModToolsViaAPI utility
- [ ] `1a8addc3` fix: Fix ModTools move-message test
- [ ] `e4162f37` Reduce Playwright workers to 1

### Phase 5: Merge/Revert commits (skip - handled by cherry-picking individual commits)
- [skip] `895e09c6` style: Modernize CSS design system (#197) — merge commit
- [skip] `51314060` Merge pull request #194
- [skip] `44d51fc7` Merge feature/playwright-test-isolation into master
- [skip] `f06efec5` Revert merge
- [skip] `f93077b0` Revert restore workers
- [skip] `a79900d4` fix: Restore 11 Playwright workers
- [skip] `2edce9ec` fix: Remove move-message test

## Process

1. Create a working branch from v2: `git checkout -b merge-master-css feature/v2-unified-migration`
2. Cherry-pick Phase 1 commits oldest-first
3. After each cherry-pick, check for conflicts with V2 code
4. Run `npx eslint --fix` on any conflicting files
5. After all Phase 1, run Vitest
6. Cherry-pick Phase 2 (docs)
7. Cherry-pick Phase 3 one at a time, reviewing each
8. Run full Vitest + Playwright
9. Do NOT merge to v2 branch yet — just verify
