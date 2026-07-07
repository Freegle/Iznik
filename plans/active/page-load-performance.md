# Page Load Performance Investigation & Improvement Plan

**Date**: 2026-02-09
**Branch**: `feature/page-load-performance` (to be created)
**Status**: INVESTIGATION & PLANNING (Phase 1B complete)

## Executive Summary

This plan investigates and proposes improvements to page load times for ilovefreegle.org, a Nuxt 3.18 application deployed on Netlify, serving UK-only users. The analysis covers the current architecture, identifies specific bottlenecks, and proposes phased improvements ranked by effort vs impact.

## Context: UK-Only User Base

All Freegle users are in the UK. This means:
- Edge/CDN geo-distribution adds no value (Netlify's UK PoP is sufficient)
- Edge Functions running closer to users provides no benefit over a single-region server
- Focus should be on reducing bytes transferred and improving render/hydration times
- UK mobile networks (4G/5G) are generally good but rural coverage varies

---

## Current Architecture Analysis

### Rendering Strategy (nuxt.config.ts)
| Route Pattern | Strategy | Notes |
|---------------|----------|-------|
| `/`, `/explore`, `/about`, etc. (11 routes) | **Pre-rendered** at build time | Good - instant TTFB from CDN |
| `/browse/**`, `/chats/**`, `/myposts`, etc. | **`ssr: false`** (client-only SPA) | Logged-in pages - acceptable |
| `/message/**`, `/shortlink/**` | **ISR** (10 min cache) | Good for SEO + freshness |
| `/explore/**`, `/story/**`, `/volunteering/**` | **ISR** (1 hour cache) | Good |
| Everything else | **SSR** (default) | Server-rendered each request |

### What's Already Done Well
1. **Prefetch/preload disabled** (lines 237-242, 284-292) - prevents unnecessary resource loading on mobile
2. **Async component loading** via `defineAsyncComponent()` in ~15 pages
3. **Message fetch batching** - 50ms delay batches parallel message fetches
4. **Request deduplication** - parallel requests to same ID share a Promise
5. **10-minute client TTL** on message cache
6. **ISR on Netlify** for public-facing content pages
7. **esbuild minification** in production
8. **CSS extraction** enabled
9. **PWA with service worker** (Workbox, 5MB max file cache)

### Identified Bottlenecks

#### 1. ProxyImage NOT Using NuxtPicture/NuxtImg (HIGH IMPACT)
**File**: `components/ProxyImage.vue`

The component has `NuxtPicture` **commented out** with a TODO:
```html
<!-- TODO: Fix up so that NuxtPicture works. Seems to go wrong in MT chats list -->
<img :src="src" :alt="alt" :width="width" :height="height" />
```

This means:
- **No responsive images** (no srcset/sizes)
- **No WebP/AVIF format negotiation**
- **No automatic resizing** to screen-appropriate dimensions
- **No blur-up placeholders** (LQIP)
- Images are served at full resolution regardless of device
- On message listing pages with 20+ images, this is significant

#### 2. Heavy Third-Party Script Loading (HIGH IMPACT)
**File**: `nuxt.config.ts` (head scripts section, 300+ lines)

Current loading chain:
1. **Google Sign-In (GSI)** - async, body
2. **CookieYes** - consent management, blocks ads
3. **Playwire** - ad network (or Prebid + GPT fallback)

Issues:
- Scripts loaded via raw `<script>` tags in head config, not using `@nuxt/scripts` module
- CookieYes → Playwire → ad rendering is a sequential waterfall
- No web worker offloading (Partytown or equivalent)
- These scripts compete with hydration on main thread

#### 3. Bootstrap Vue Next SSR Workarounds (MEDIUM IMPACT)
The app wraps many components in `<client-only>` because Bootstrap Vue Next doesn't support SSR:
```html
<client-only>
  <NuxtLink to="/give" class="action-btn">Give</NuxtLink>
  ...
</client-only>
```

This means:
- CTA buttons, navigation, and interactive elements render only client-side
- Users see empty space until hydration completes
- SSR HTML is missing key content that would improve FCP/LCP

#### 4. No Lazy Hydration (MEDIUM-HIGH IMPACT)
The app uses Nuxt 3.18 which supports native lazy hydration (added in 3.16), but doesn't use it anywhere:
- `hydrate-on-visible` for below-fold components
- `hydrate-on-idle` for non-critical UI
- `hydrate-on-interaction` for modals, dropdowns

Currently all components hydrate immediately on page load, competing for main thread time.

#### 5. Custom Data Fetching Bypasses Nuxt SSR Optimizations (MEDIUM IMPACT)
The app uses a custom `BaseAPI.js` + `useFetchRetry.js` wrapper instead of Nuxt's built-in `useFetch`/`useAsyncData`:
- No automatic SSR → client payload transfer
- No built-in deduplication across SSR and client
- Data fetched on SSR must be re-fetched on client for hydration
- The `Cache-Control: max-age=0, must-revalidate, no-cache, no-store, private` header on ALL API calls prevents any HTTP-level caching

#### 6. Large optimizeDeps Include List (LOW-MEDIUM IMPACT)
47 dependencies pre-bundled in `vite.optimizeDeps.include` including heavy libraries loaded on every page:
- Leaflet + plugins (mapping - only needed on explore/browse)
- Quill editor (only needed in compose/chat)
- Vue Google Charts (only needed on stats)
- Uppy file uploader (only needed in compose)
- Bootstrap Vue Next components (many individual imports)

#### 7. FontAwesome Full CSS (LOW IMPACT)
`@fortawesome/fontawesome-svg-core/styles.css` is loaded globally. Only icons actually used should be included (tree-shaking may already handle the JS, but the CSS definitions include all icon classes).

#### 8. Server Middleware Logging (LOW IMPACT)
`server/middleware/log.js` logs every request with `console.log()`:
```js
console.log(event.node.req.method + ' request: ' + event.node.req.url)
```
This adds I/O overhead to every SSR request (minor but unnecessary in production).

---

## Proposed Improvements (Phased)

### Phase 1: Quick Wins (Low Risk, High Return)

#### 1A. Enable Lazy Hydration on Below-Fold Components
**Effort**: Low | **Impact**: Medium-High | **Risk**: Low

Add `hydrate-on-visible` to components that appear below the fold:
```html
<LazyMobileVisualiseList hydrate-on-visible class="sample-grid" />
<LazyFreeglerPhotosCarousel hydrate-on-idle />
```

Target components on landing page:
- `MobileVisualiseList` (sample offers grid) → `hydrate-on-visible`
- `FreeglerPhotosCarousel` → `hydrate-on-idle`
- App download section → `hydrate-on-visible`
- Stats/testimonials → `hydrate-on-visible`

Target components on browse page:
- `MicroVolunteering` → `hydrate-on-visible`
- `BirthdayModal` → `hydrate-on-interaction`
- `AboutMeModal` → `hydrate-on-interaction`
- `ExpectedRepliesWarning` → `hydrate-on-visible`

**Expected improvement**: Reduced TTI by 200-500ms, improved INP

#### 1B. Fix ProxyImage to Use NuxtPicture ✅ DONE (2026-02-09)
**Effort**: Medium | **Impact**: High | **Risk**: Medium (need to test MT chats)

**Root cause found**: NuxtPicture was commented out because ModTools chat list showed wrong images. The underlying issue was that ModTools uses the v1 chat API which returns `chat.icon` as `tuimg_*.jpg` URLs. These redirect through a chain that ultimately returns default gravatar images. When NuxtPicture processed these through weserv, the result was generic gravatar defaults instead of the colorful generated avatars the code expected.

**Fixes applied**:
1. **ProxyImage.vue**: Restored `<NuxtPicture>` with weserv provider (was commented out with raw `<img>` tag)
2. **ProfileImage.vue**: Fixed `sizes` prop from hardcoded `"100px sm:25px"` (backwards - served 25px on desktop) to dynamic `width + 'px'`. Added `:width` and `:height` props to ProxyImage call.
3. **ChatListEntry.vue**: Added `resolvedIcon` computed that fetches user profiles via v2 API on ModTools, using `profile.paththumb` instead of relying on `chat.icon` (tuimg URLs). Guarded by `miscStore.modtools` so Freegle site is unaffected.
4. **ModChatHeader.vue**: Fixed to check `profile.paththumb` (v2 API field) in addition to `profile.turl` (v1 field) when resolving the chat header profile image.

**Result**: Images now load through weserv delivery with WebP format, responsive sizing, and proper user profile resolution on ModTools.

#### 1C. Disable Server Middleware Logging in Production
**Effort**: Trivial | **Impact**: Low | **Risk**: None

Wrap `server/middleware/log.js` in a development check or remove it:
```js
if (process.dev) {
  console.log(event.node.req.method + ' request: ' + event.node.req.url)
}
```

### Phase 2: Third-Party Script Optimization (Medium Risk, High Return)

#### 2A. Adopt @nuxt/scripts Module
**Effort**: Medium | **Impact**: High | **Risk**: Medium (ads revenue depends on correct loading)

Replace raw `<script>` tags with `@nuxt/scripts`:
- Built-in SSR support and proxy layer
- Non-blocking loading triggered by page load, user interaction, or consent
- Script registry for common integrations (includes Google Analytics)
- Better control over when scripts execute

Migration path:
1. Install `@nuxt/scripts`
2. Migrate CookieYes loading to use `useScript()` with appropriate trigger
3. Migrate Playwire/ad scripts to use `useScript()` with consent-gated trigger
4. Migrate GSI to use `useScript()` with idle trigger
5. Test ad revenue is not affected

#### 2B. Consider Partytown for Ad Scripts
**Effort**: High | **Impact**: High | **Risk**: High (Partytown still beta, ad scripts may break)

Move ad-related scripts to web worker via Partytown:
- Playwire/Prebid header bidding runs in web worker
- Main thread freed for user interaction
- ~6KB library overhead

**Recommendation**: Try `@nuxt/scripts` first (2A). Only pursue Partytown if ads still significantly impact main thread after 2A.

### Phase 3: SSR & Caching Optimization (Medium Effort, Medium Return)

#### 3A. Improve ISR/SWR Configuration for Netlify
**Effort**: Low | **Impact**: Medium | **Risk**: Low

Current ISR setup works but could be tuned:

```typescript
routeRules: {
  '/': { prerender: true },
  '/explore': { prerender: true },
  // Add Netlify-specific caching headers for better CDN behavior
  '/message/**': {
    isr: 600,
    headers: {
      'Netlify-CDN-Cache-Control': 'public, max-age=600, stale-while-revalidate=86400, durable',
    },
  },
  '/explore/**': {
    isr: 3600,
    headers: {
      'Netlify-CDN-Cache-Control': 'public, max-age=3600, stale-while-revalidate=86400, durable',
    },
  },
}
```

The `durable` directive enables Netlify's global edge cache. Since all users are UK-based, this specifically helps with Netlify's UK PoP serving cached responses.

Add `stale-while-revalidate` to serve stale content instantly while refreshing in background.

#### 3B. Add SWR Caching for Browse Page
**Effort**: Low | **Impact**: Medium | **Risk**: Low

The browse page is currently `ssr: false`. For logged-out users browsing, consider:
```typescript
'/browse/**': { swr: 300 }, // 5-minute SWR instead of pure client-side
```

This gives logged-out browsers an SSR page that updates every 5 minutes, rather than a blank page that must fully client-render.

#### 3C. Investigate Nuxt Fonts Module
**Effort**: Low | **Impact**: Low-Medium | **Risk**: Low

Add `@nuxt/fonts` module:
- Auto-optimizes font loading
- Self-hosts fonts (eliminates external font requests)
- Generates fallback metrics to reduce CLS from font swap
- Currently the app loads FontAwesome CSS globally

### Phase 4: Bundle Size Reduction (Medium Effort, Medium Return)

#### 4A. Audit and Optimize Dependencies
**Effort**: Medium | **Impact**: Medium | **Risk**: Low

Use `nuxi analyze` to generate bundle visualization:
```bash
npx nuxi analyze
```

Specific targets:
- **Leaflet**: Only import on pages that use maps (explore, browse). Currently in `optimizeDeps.include` so it's pre-bundled
- **Quill editor**: Only needed in compose. Verify it's code-split
- **Vue Google Charts**: Only needed on stats page. Verify code splitting
- **Uppy**: Only needed in compose. Verify code splitting
- **twemoji**: Large library. Consider only importing needed emoji

#### 4B. Review Bootstrap Vue Next Tree Shaking
**Effort**: Low | **Impact**: Low-Medium | **Risk**: Low

The config already imports individual BVN components. Verify tree-shaking is working:
- Check if `@bootstrap-vue-next/nuxt` auto-imports everything or only used components
- If auto-importing all, switch to manual component registration
- Consider importing only needed CSS utilities

### Phase 5: Advanced Optimizations (Higher Effort, Variable Return)

#### 5A. Netlify Image CDN Integration
**Effort**: Medium | **Impact**: High | **Risk**: Low

When deployed on Netlify, `@nuxt/image` can automatically use Netlify Image CDN:
- On-demand image transformation at edge
- Automatic format negotiation (AVIF/WebP)
- No build-time processing needed
- Currently using weserv provider (self-hosted image proxy)

Evaluate:
- Would Netlify Image CDN be more performant than weserv?
- Cost implications?
- Can we use Netlify Image CDN for the production site while keeping weserv for Docker dev?

#### 5B. Enable Component Islands (Experimental)
**Effort**: High | **Impact**: Medium | **Risk**: High (experimental feature)

For static content sections (footer, about text, FAQ):
```typescript
// nuxt.config.ts
experimental: {
  componentIslands: true
}
```

Then rename static components:
- `AppFooter.vue` → `AppFooter.server.vue`
- Static info sections → `.server.vue`

**Caution**: Each NuxtIsland renders as a full Nuxt app on server. Use sparingly. This feature is still experimental and may have issues at scale.

**Recommendation**: Wait until this is stable. Lazy hydration (Phase 1A) provides similar benefits with less risk.

#### 5C. Migrate Data Fetching to useFetch/useAsyncData
**Effort**: Very High | **Impact**: Medium | **Risk**: High (massive refactor)

The custom `BaseAPI.js` / Pinia store pattern works but misses Nuxt's SSR payload transfer. Migrating to `useFetch` would:
- Automatically transfer server-fetched data to client via payload
- Eliminate double-fetching on SSR + hydration
- Enable `pick` option to reduce payload size

**Recommendation**: NOT recommended as a standalone task. This is a fundamental architecture change that would touch 50+ API classes and 15+ stores. Consider for individual new features going forward rather than a mass migration.

---

## Should We Consider Astro?

### Analysis

**Astro's strengths**:
- Zero JS by default for static content
- Islands architecture for selective interactivity
- Can use Vue components
- Sub-500ms LCP for static pages

**Why it's NOT suitable for Freegle**:
1. **Freegle is a web application, not a content site** - most pages require authentication, real-time data, interactive features (maps, chat, compose)
2. **Migration cost is enormous** - ~200+ Vue components, 28+ pages, 15+ Pinia stores, 50+ API classes would need rewriting or wrapping
3. **Nuxt 3 already has hybrid rendering** - the same benefits can be achieved with routeRules (prerender, ISR, SWR) without changing frameworks
4. **Nuxt 3.16+ has lazy hydration** - the "don't hydrate what you don't need" benefit of Astro can be replicated with `hydrate-on-visible`, `hydrate-on-idle`, etc.
5. **Astro isn't SPA-native** - for logged-in user flows (browse → message → chat → reply), Nuxt's SPA navigation is superior

**Verdict**: Stay with Nuxt 3. The improvements available within Nuxt (lazy hydration, better ISR, image optimization, script optimization) would deliver similar performance gains at a fraction of the migration cost.

### Could Astro Be Used for Just Landing Pages?

Theoretically yes (micro-frontend approach), but:
- Adds build complexity (two frameworks, two deploy pipelines)
- The landing page is already pre-rendered by Nuxt
- The performance gap is small once lazy hydration and image optimization are applied
- Not worth the operational overhead for a small team

---

## Netlify-Specific Opportunities

### What Netlify Offers That We Could Use Better

1. **Netlify Image CDN** - automatic image optimization at edge (currently using weserv instead)
2. **ISR with `durable` directive** - global edge caching (add to routeRules headers)
3. **`Netlify-CDN-Cache-Control`** - separate browser vs CDN cache control
4. **`Netlify-Vary`** - cache by query params or cookies (useful for browse page filters)
5. **`@netlify/nuxt` module** - integrates Netlify platform features into Nuxt dev server
6. **Cache tags + on-demand revalidation** - invalidate specific cached pages when data changes

### What's NOT Useful (UK-Only)

- **Edge Functions** - no benefit, all users are in UK anyway
- **Multi-region deployment** - same reason
- **Split testing** - not a performance feature, though useful for A/B testing changes

---

## Measurement Plan

Before making changes, establish baselines:

### Tools
1. **PageSpeed Insights** - lab + field data (CrUX)
2. **WebPageTest** (London server) - detailed waterfall analysis
3. **Lighthouse CI** - automated regression tracking
4. **Nuxt DevTools** - component render times, bundle analysis

### Key Metrics to Track
| Metric | Target | Current (est.) |
|--------|--------|----------------|
| LCP (mobile) | < 2.5s | TBD - measure |
| INP (mobile) | < 200ms | TBD - measure |
| CLS (mobile) | < 0.1 | TBD - measure |
| FCP (mobile) | < 1.8s | TBD - measure |
| TTFB | < 800ms | TBD - measure |
| Total JS (compressed) | TBD | TBD - nuxi analyze |
| Total page weight | TBD | TBD - measure |

### Pages to Measure
1. **Homepage** (`/`) - pre-rendered, first impression
2. **Explore page** (`/explore/[groupname]`) - ISR, common landing from search
3. **Message page** (`/message/[id]`) - ISR, shared on social media
4. **Browse page** (`/browse`) - client-rendered, most-used logged-in page

---

## Implementation Priority (Recommended Order)

| Priority | Task | Effort | Impact | Risk |
|----------|------|--------|--------|------|
| 1 | Measure baselines | Low | Critical | None |
| 2 | 1A: Lazy hydration | Low | Medium-High | Low |
| 3 | ~~1B: Fix ProxyImage → NuxtPicture~~ ✅ | Medium | High | Medium |
| 4 | 1C: Disable prod logging | Trivial | Low | None |
| 5 | 2A: @nuxt/scripts for 3P | Medium | High | Medium |
| 6 | 3A: Netlify ISR/SWR headers | Low | Medium | Low |
| 7 | 3C: @nuxt/fonts | Low | Low-Medium | Low |
| 8 | 4A: Bundle audit | Medium | Medium | Low |
| 9 | 3B: SWR for browse | Low | Medium | Low |
| 10 | 5A: Netlify Image CDN | Medium | High | Low |

---

## Sources

- [Nuxt 3 Performance Best Practices](https://nuxt.com/docs/3.x/guide/best-practices/performance)
- [Nuxt SSR Performance - DebugBear](https://www.debugbear.com/blog/nuxt-ssr-performance)
- [Nuxt SSR Hydration & Islands](https://alisoueidan.com/blog/deep-dive-into-nuxt-ssr-hydration-and-islands)
- [Lazy Hydration - Nuxt 3.16](https://nuxt.com/blog/v3-16)
- [Nuxt Scripts Module](https://scripts.nuxt.com/)
- [ISR and Advanced Caching with Nuxt on Netlify](https://developers.netlify.com/guides/isr-and-advanced-caching-with-nuxt-v4-on-netlify/)
- [Nuxt on Netlify](https://docs.netlify.com/build/frameworks/framework-setup-guides/nuxt/)
- [Netlify Image CDN with Nuxt](https://developers.netlify.com/guides/avoiding-lock-in-for-your-image-pipeline-with-nuxt-image-and-netlify-image-cdn/)
- [Nuxt vs Astro Framework Comparison 2026](https://www.nunuqs.com/blog/nuxt-vs-next-js-vs-astro-vs-sveltekit-2026-frontend-framework-showdown)
- [Partytown - Web Workers for 3P Scripts](https://partytown.builder.io/)
- [AVIF vs WebP Comparison](https://speedvitals.com/blog/webp-vs-avif/)
