# Nuxt 3.21.5 → 4.5.1 upgrade + full dependency refresh

Worktree: `/home/edward/FreegleDocker-nuxt45` · branch `feature/nuxt45-upgrade` · status API :12533
Directive: upgrade to Nuxt 4.5, check release notes carefully, extra tests where required,
upgrade ALL dependencies, highlight adoptable new features, no human intervention.

## Why now
Nuxt 3 is EOL 2026-07-31. v4.5.1 is a security-mandatory release (RCE-class fixes; we jump
straight to it, never exposed to 4.5.0's issues). App already runs Vite 8 via package.json
overrides, so half the 4.5 toolchain prerequisite is already proven in prod.

## Key research facts (full detail: scratchpad/research-*.json of session 86d50c30)
- **ZERO useAsyncData/useFetch call sites** in app code (all data via Pinia stores → BaseAPI →
  native fetch + fetch-retry). The headline v4 data-fetching breaking set has no blast radius.
- Both apps have top-level `pages/` → v4 auto-keeps old structure; we pin `srcDir: '.'` +
  `dir.app: 'app'` explicitly in BOTH configs. NEVER move to app/ — file-sync.sh, .eslintrc
  globs, vitest coverage globs would silently break (vitest coverage would drop to 0%).
- unhead v3 (in 4.5) HARD-REMOVES `hid`/`vmid`/`body: true`/`children`. We have 17 `hid:`
  sites (useBuildHead.js ×10, modtools/composables/useMTBuildHead.js ×6, pages/message/[id].vue ×1)
  + many `hid:` entries in both configs' app.head.meta + 2 `body: true` script entries.
  Convert `hid:` → `key:`, `body: true` → `tagPosition: 'bodyClose'`.
- One production `route.meta` touchpoint: app.vue:273 `route.meta?.layout` (untested today).
- vitest is fully hand-mocked (#app/#imports → tests/unit/mocks/nuxt-app.js); @nuxt/test-utils
  NOT used by unit tests. 689 unit spec files; 49 Playwright specs vs prod containers.
- Unit suite cannot see build/module regressions — only Playwright vs rebuilt prod containers can.
- 4.2+: NuxtLink props now win attr collisions; mergeModels dropped from auto-imports (unused).
- 4.3: nitro tsconfig noUncheckedIndexedAccess default; 4.3.1 SSR URL query no longer decoded.
- 4.4: vue-router v5 internally (we hold vue-router ^4.6.4 — Nuxt manages it); useCookie
  decode switched destr→JSON.parse (audit our useCookie values); 4.4.7 security: navigateTo/
  NuxtLink protocol/origin validation; route rules match case-insensitively.
- Vite 8/Rolldown: no manualChunks/rollupOptions in our config (non-issue). plugin-legacy
  polyfill chunk can't be ES5 under Rolldown — only affects Netlify legacy build (chrome 49
  targets), zero CI coverage, and we're ALREADY on vite 8 in prod so not a new regression.
- nuxt-vite-legacy has NO Nuxt-4 release. Decision: try it; if module registration fails,
  wire @vitejs/plugin-legacy directly in vite.plugins gated on isNetlify (same targets);
  document either way.
- modtools layer re-declares @nuxt/image (propagation workaround) — retest with AND without
  workaround post-bump (v4 layer module order changed to layers-first).

## Dependency tiers (deps matrix in research-deps.json)
- **Move with Nuxt (same commit):** nuxt 4.5.1, @nuxt/image 2.1, pinia 4.0.2 + @pinia/nuxt 1.0.1
  + pinia-plugin-persistedstate 4.7.1, @nuxt/test-utils 4.1, vitest 4.1.10 + @vitest/coverage-v8
  4.1.10, vite-plugin-istanbul 9 (already broken vs vite 8 today!), typescript ^5.9.3,
  vite override → ^8.1.x or drop, @vitejs/plugin-legacy 8.2.2.
- **Delete (verified unused):** add, upgrade, lint, @vue/cli-plugin-eslint, @nuxtjs/sentry,
  @vue-stripe/vue-stripe. With Sentry rewrite: @sentry/tracing, @sentry/integrations.
- **Sentry rewrite:** @sentry/vue 7→10 (plugins/sentry.client.ts: browserTracingIntegration(),
  httpClientIntegration(), extraErrorDataIntegration(), drop vueRouterInstrumentation/
  createTracingMixins/attachErrorHandler — init({app, router}) wires it), @sentry/vite-plugin 2→5.
- **Majors to take:** FA 6→7 trio (+check renamed icons), uppy core/dashboard/drag-drop/
  status-bar/tus/vue/webcam →5 (compressor: peer-check, may stay 3.x; file-input/progress-bar
  stay 4.x — no v5 exists), jwt-decode 4 (named export in GoogleOneTap.vue), fetch-retry 6,
  marked 18, supercluster 8, @vue-leaflet 0.10.1 (+patch regen), leaflet-control-geocoder 4
  (test maps; revert to hold if broken), read-excel-file 9 (test BulkItemEditor),
  @formatjs 5/6, eslint-plugin-vue 10 (+vue-eslint-parser 10), eslint-plugin-n 18,
  eslint-config-prettier 10, eslint →8.57, sitemap 9, ipx 3.1.1 (NOT 4-beta), patch-package 8,
  on-change 6, @stripe/stripe-js 9, @google-pay/button-element 4, sass 1.102, vue 3.5.40
  (+regen 2 vue patches), bootstrap 5.3.8 (+regen patch), all safe minors.
  modtools: handsontable+@handsontable/vue3 →18 together (test ModSupportListGroups),
  diff 9, quill-html-edit-button 3.
- **HOLD (documented follow-ups, each with reason):** bootstrap-vue-next 0.24→0.45 (full
  reka-ui rewrite of core UI kit = own project), Capacitor 7→8 cluster (blocked on
  @freegle/capacitor-push-notifications-cap7 fork; native release), eslint 9/10 + flat config
  (@nuxtjs/eslint-config hard-peers eslint 8; needs @nuxt/eslint rewrite), prettier 2→3 +
  eslint-plugin-prettier 5 (whole-repo reformat = own PR, review noise), typescript 6/7 tsgo,
  vue-router 5 (Nuxt manages; pin ^4.6.4), leaflet 2 (alpha only).
- **patches/**: every patched pkg bump regenerates its patch in the SAME commit (patch-package
  matches exact version): vue×2 (3.5.40), bootstrap (5.3.8), vue-google-charts (1.1.1),
  @vue-leaflet (0.10.1), @uppy/core (5.x — recheck if fixed upstream first), vue-mention +
  postman-paf unchanged.

## Critic gaps folded in (research-critic.json)
1. patches regen (above). 2. Root `npm ci` (no flags) must pass — CI orb uses it unflagged;
aim to eliminate the need for --legacy-peer-deps entirely. 3. Align modtools-netlify.toml
(`npm i`) vs modtools/netlify.toml (`npm ci --legacy-peer-deps`). 4. Dockerfile.playwright:17
hardcodes @playwright/test@1.52.0 — bump with package.json + rebuild playwright image.
5. Legacy chunk has zero CI coverage — Netlify-only; note in PR for a deploy-preview check.
6. docs/developers/reference/seo.md `covers:` useBuildHead.js + message/[id].vue — update page
or last_reviewed same PR; run scripts/check-docs-freshness.mjs. 7. modtools/package-lock.json
regenerates independently. 8. Node floor: base image 22.23.1 ≥ vite 8 floor (verified).
9. vitest 4: verify pool:'forks'/maxWorkers/fileParallelism semantics line-by-line vs
vitest.config.mts (WSL OOM tuning). 10. After hard-required set, `npm ls bootstrap-vue-next
@sentry/vue` to prove holds aren't peer-forced. 11. Check CI orb for touched pins; republish
if changed. 12. Retest modtools NuxtPicture with/without @nuxt/image re-declaration.
13. nuxt-vite-legacy fallback decision (above). 14. Prod containers: rebuild image AND
restart (build runs at container START); do before every Playwright run.

## New tests (directive: add extra tests where required)
- tests/unit/app.spec.js — pin app.vue shouldShowNavbar / route.meta.layout (write FIRST,
  green pre-bump, must stay green post-bump).
- tests/e2e/test-head-meta-smoke.spec.js — real rendered title + meta description + og:*
  + NO-DUPLICATE-meta assertions on 2-3 pages vs prod container (unit mocks can't see unhead).
- tests/unit/composables/useBuildHead.spec.js additions — assert emitted entries use `key`
  (not `hid`) post-conversion.
- One-shot: disable Playwright hydration-mismatch allowlist, full run, before/after count.

## Adoption highlights (for PR "new features" section)
Adopt now: stable error codes (free), unctx v3 reliability (free), 4.3 route-rule `appLayout`
for modtools definePageMeta dedup (evaluate), 4.4 `useCookie refresh` for sliding sessions
(evaluate), NuxtLink prefetch slots (n/a — no custom links). Trial later: experimental SSR
streaming on message/browse pages (TTFB — needs mid-render mutation audit first),
experimental.prefetchPreloadTags, useLayout, named views, AbortController in store fetches,
useAnnouncer accessibility pass. Not now: compatibilityVersion 5, Rolldown full switch,
extractAsyncDataHandlers (no useAsyncData usage).

## Phases
0. Baseline: npm ci + nuxi prepare + local vitest run green pre-change; seed worktree DB.
1. Pin-tests (app.spec.js) green pre-bump.
2. Core bump (hard-required cluster) + config migration (srcDir pin, hid→key, body→tagPosition,
   vestige removal, compatibilityDate) + vitest 4 config + lockfiles. Local vitest green.
3. Dependency refresh commits: deletions → safe minors (+patch regens) → Sentry rewrite →
   majors (each verifiable subset separately) → modtools majors. Local vitest green each step.
4. Container rebuilds (dev + prod + playwright) in worktree; status-API vitest full; prod
   build works; Playwright full green (DB seeded, scheduler caveats per memory).
5. Browser validation (Chrome MCP isolatedContext=nuxt45): FD home/browse/message + meta tags
   + console; MT login/messages. Lint. Docs freshness. Orb check. npm ci (no flags) check.
6. Commit sequence, push, PR (embed feature highlights + holds + follow-ups + test plan).
   Monitor CI.

## Status
| # | Task | Status | Notes |
|---|------|--------|-------|
| 0a | npm ci + nuxi prepare + baseline vitest | ✅ | 14,564✓ 0✗ via status API :12533 |
| 0b | Seed worktree test DB | ✅ | |
| 1 | Pin test (composables/useNavbarVisibility + spec; app.vue refactored to use it) | ✅ | 4✓ pre-bump |
| 2a | Core bump package.json + lockfiles | ✅ | GTM module has no Nuxt-4 release → overrides["@zadigetvoltaire/nuxt-gtm"].nuxt=$nuxt. npm 10 arborist CRASHES (edgesOut) on this tree → use npx npm@11 for installs (lockfileVersion 3, npm 10 `npm ci` still fine). Resolves clean with NO --legacy-peer-deps. nuxt 4.5.1/vite 8.1.5/pinia 4.0.2/vue-router 4.6.4/unhead 3.2.3 confirmed. |
| 2b | nuxt.config migration both apps | ✅ | srcDir '.', dir.app pin; hid:→key: (19 root config + composables + message/[id]); body:true→tagPosition bodyClose ×2; removed target/render/webpack/routerOptions vestiges; compatibilityDate 2026-07-30 |
| 2c | Suite green on Nuxt-4 tree | ✅ | **FINAL: 14,564✓ 0✗ 0 unhandled errors.** Journey: run1 1261✗ (defineStore({id}) removed in pinia 3+ → converted 46 stores); run2 249✗ (vitest-4 constructor mocks + hid→key spec); run3 3✗ (jwt-decode v4 mock named export); run4 0✗ but 4 unhandled rejections (AutoComplete XHR arrow mock — vitest 4 fails runs on those); run5 CLEAN. Also fixed css entry '/assets/…'→'~/assets/…' (v4 drops leading-slash resolution — would have silently lost ALL global styles). |
| 3a | Deletions | ✅ | add, upgrade, lint, @vue/cli-plugin-eslint, @nuxtjs/sentry, @vue-stripe/vue-stripe, fetch-retry, sitemap, path-browserify, on-change, @uppy/{drag-drop,file-input,progress-bar}, csv-writer (modtools), @sentry/{tracing,integrations} |
| 3b | Safe minors + patch regens | ✅ | vue 3.5.40 (pins+overrides), patches regen: @vue/runtime-core+dom 3.5.40 (NOT fixed upstream), bootstrap 5.3.8, vue-google-charts 1.1.1, @uppy/core 5.2.0 (guard re-applied to lib+src); vue-leaflet patch DROPPED (0.10.1 has unbind guards + divIcon slot fix upstream) |
| 3c | Sentry v10 rewrite | ✅ | browserTracingIntegration({router})/httpClientIntegration()/extraErrorDataIntegration(); app:vueApp + attachProps/trackComponents/timeout/hooks in init; tracePropagationTargets top-level; removed createTracingMixins/attachErrorHandler |
| 3d | Majors (web) | ✅ | FA7 trio, uppy5 (+@uppy/screen-capture peer; DashboardModal → '@uppy/vue/dashboard-modal' default import + spec mocks), jwt-decode 4 (named import GoogleOneTap), marked 18, supercluster 8 (dist path still valid), @vue-leaflet 0.10.1, leaflet 1.9.4, formatjs 5/6 (paths verified), read-excel-file 9 (basic API unchanged), stripe-js 9, google-pay 4, ipx 3.1.1, sass 1.102, patch-package 8, eslint-plugin-vue 10 + n 18 + config-prettier 10 + eslint 8.57 (lint TBC). HOLDS: bootstrap-vue-next 0.24 (reka-ui rewrite), leaflet-control-geocoder 1.13 (v4 restructures src/ imports in PostMap), capacitor 7, eslint 9/10 flat, prettier 2, TS 5.9 (not tsgo 7), vue-router 4 (nuxt-managed) |
| 3e | Majors (modtools pkg) | ✅ | handsontable ^18.0.0 pair, diff ^9.0.0, quill-html-edit-button 3.0.0, geoman 2.20; modtools lockfile regen'd |
| 3f | Vite-8/exports-map code fixes | ✅ | supercluster bare import (v8 restricts subpaths) in ClusterMarker+spec+optimizeDeps; read-excel-file/browser in BulkItemEditor; @formatjs subpaths need .js suffix (OurUploader+config+spec); @uppy CSS moved to /css/style.css + Dashboard styles now separate pkg (added @uppy/dashboard/css import); DashboardModal subpath import in OurUploader+PhotoUploader+both spec mocks; plugins/vue-leaflet.client.js rewritten for dist-only 0.10 (named exports, lazy). FULL WEB BUILD GREEN: 30.8MB total vs 36.9MB pre-upgrade (-17%). |
| 3g | Lockfile npm-10 ci compatibility | ✅ | Root lockfile REGENERATED WITH npm 10: needed (a) literal ^4.5.1 instead of $nuxt ref in GTM override (npm 10 can't resolve $refs there), (b) overrides["@bomb.sh/tab"].cac="^7.0.0" (its cac ^6 peer under @nuxt/cli made npm ci's validator permanently disagree with reify). Plain `npm ci` (no flags) now PASSES = CI orb parity. modtools lockfile fine as-is. Modtools BUILD GREEN (handsontable 18 CSS: styles/handsontable.min.css + ht-theme-classic.min.css; vitest aliases updated). |
| 4a | Rebuild containers, status-API vitest green | ✅ | runner container npm ci from final lockfile; suite green (see 2c) |
| 4b | Prod containers rebuild+restart, Playwright green | ✅ | freegle-prod-local+modtools-prod-local+playwright images rebuilt & recreated, prod-local healthy after start-time build; DB reseeded, scheduler STOPPED (RESTART AFTER RUNS!), apiv2+status restarted; Playwright RUNNING |
| 4c | Head-meta e2e | ✅ | new spec green in full run (hydration-allowlist one-shot audit not done — noted as optional follow-up) |
| 4c2 | Playwright root causes | ✅ | run1 73✗→run3 12✗→run4 9✗. Fixed along the way: (a) modtools-prod served STALE image-baked build calling PROD API (local modtools/.output in tree; .dockerignore root '.output' does NOT match modtools/.output; start script skips build if .output exists); (b) PW-1.62 credentials message → allowlist; (c) logger.js guards dead under PW-1.62 bundled core (_Page/_Locator rename) → same-frame proxy errors; (d) dirty DB: reseed WITHOUT DROP can't restore auto-approved fixtures (INSERT IGNORE) — ALWAYS `DROP DATABASE iznik` first; (e) **REAL BUG (e7248a39e): vue-leaflet 0.10 imports bare 'leaflet' when window.L unset → 2nd Leaflet instance → cross-instance LatLngBounds fail instanceof → fitBounds throws "Bounds are not valid." → FATAL error page on MT pending** → plugin now pins window.L to canonical esm instance; (f) latent null.settings fatal on MT pending when user null at mount (Nuxt 4 mounts earlier) → guarded; (g) MessageMap unguarded fitBounds → isValid guard. Verified in browser: MT pending logged-out now shows login modal not error page. Unit suite re-green post-fixes (14,564✓). Run 5 on clean DB: **185✓ 0✗ GREEN**. |
| 5 | Browser validation + lint + docs freshness + orb + npm ci check | 🔄 | Browser: FD home ✓ (1× each meta, ad script in body via tagPosition, styles, navbar), /NationalReuseDay navbar hidden ✓ (route.meta.layout works), MT login renders ✓ (GSI in body). docs-freshness OK. Orb: no version pins to change. npm ci (no flags) passes. LINT: eslint-plugin-vue 10 → REVERTED to ^9.33 (@nuxtjs/eslint-config references plugin:vue/vue3-recommended, gone in v10; goes with the eslint-9 flat-config follow-up). Remaining 94 lint errors verified PRE-EXISTING on master (same plugin versions resolve; CI never lints repo-wide; only eslint-plugin-n moved and zero n/* errors fired) → reverted 172 lint-autofix-churn files to keep the diff reviewable (83 deliberate files remain); repo-wide lint cleanup = follow-up. |
| 6 | Push + PR + CI monitor | 🔄 | PR https://github.com/Freegle/Iznik/pull/1201; CI watch running |

## Phase 2 (2026-07-30 late): held workstreams — directive "do these"
Branch feature/nuxt45-toolchain (stacked on feature/nuxt45-upgrade → PR #1201).

| # | Task | Status | Notes |
|---|------|--------|-------|
| T1 | eslint 9/10 flat config (@nuxt/eslint-config, drop @nuxtjs/*) | ✅ | eslint.config.mjs committed; .eslintrc.js deleted; eslint ^10.8 via config pkg; repo lint 0 errors |
| T2 | prettier 3 (+eslint-plugin-prettier 5) | ✅ | .prettierrc pins trailingComma es5; reformat committed with T1 |
| T3 | TypeScript 7 (tsgo) | ❌ BLOCKED | typescript@7.0.2 passes `nuxi prepare` (Nuxt itself fine) but eslint dies at load: ts-api-utils `TypeError: Cannot read properties of undefined (reading 'Intrinsic')` (index.cjs:787) via @nuxt/eslint-config → @typescript-eslint/typescript-estree — typescript-eslint does not support tsgo yet. Reverted to ^5.9.3; retry when typescript-eslint ships TS7 support |
| T4 | leaflet-control-geocoder 4 | ✅ | 1.13→4.0.0; v4 root ESM exports `{ Geocoder, geocoders }` — PostMap.vue + DraggableMap.vue now `new geocoders.Photon(...)` (same options; Photon API unchanged). CSS path still export-mapped. optimizeDeps: two src/ entries → package root. Browser validation of place search still TODO |
| T5 | nuxt-vite-legacy → direct @vitejs/plugin-legacy | ✅ | Module + `legacy:` block + overrides entry dropped; `legacy({renderLegacyChunks:false, modernPolyfills:[7 items]})` in vite.plugins gated on isNetlify. NETLIFY=true local build: 0 legacy chunks, core-js polyfills confirmed in entry-loaded chunk (fromEntries/flatMap markers + __core-js_shared__). ES5/chrome49 path was already broken upstream (vitejs/vite#21951) |
| T6 | GTM module → plain plugin | ✅ | Pushed to #1201 as e343f62dd (module silently skipped on Nuxt 4 via compatibility gate; prod has GTM_ID live). plugins/gtm.client.ts + 4 specs |
| T7 | vue-router 5 | ✅ | Direct dep ^4.6.4→^5.2.0; collapses Phase-1 dual-router tree (nuxt already ran 5.2.0 internally); single 5.2.0 in lockfile |
| T8 | leaflet 2 alpha | ❌ BLOCKED | Alpha-only; @vue-leaflet peers ^1.6.0; geocoder expects leaflet 1 default export. Documented in inv-leaflet2.md — revisit at leaflet 2 stable |
| T9 | Nuxt UI migration scoping report | ✅ | plans/active/2026-07-30-nuxt-ui-scoping.md — recommend bvn 0.24→0.45 first, then incremental Nuxt UI, never big-bang |
| T10 | Capacitor 8 + cap8 push fork | ⬜ | SEPARATE branch/PR after toolchain; port Freegle/capacitor-push-notifications-cap7 via cap8 branch |
| T11 | SSR retry bug found during T5 validation | 🔄 | Netlify prerender: any failed fetch → retryOn callback calls useMiscStore() with no active pinia → throw → wrapper promise never settles + unhandledRejection (12/build). NOT a toolchain regression (Phase-1 local builds never prerender — Netlify-only path). Fix: import.meta.client gate around store access in composables/useFetchRetry.js + source-shape spec. Validation: NETLIFY rebuild expecting 0 rejections |
