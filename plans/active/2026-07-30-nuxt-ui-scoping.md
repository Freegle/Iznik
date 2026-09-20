# Nuxt UI migration scoping (2026-07-30)

> Commissioned as part of the Nuxt 4.5 follow-ups: could Freegle shift from
> bootstrap-vue-next 0.24 + Bootstrap 5 SCSS to Nuxt UI while preserving a very
> similar feel? Produced by a five-track investigation; inventory numbers are
> live greps against this tree.

# Scoping report: bootstrap-vue-next + Bootstrap 5 → Nuxt UI

Repo scanned: `iznik-nuxt3` (main site under `components/ pages/ layouts/`, ModTools under `modtools/`), read-only, 2026-07-30. All counts below are from live `grep` over source (excluding `node_modules` and test files unless stated).

## 1. Inventory

### 1.1 Component tag usage (`<b-*>`, kebab-case), main app + ModTools combined

643 source files scanned (336 `components/`, 94 `pages/`, 4 `layouts/`, 171 `modtools/components/`, 36 `modtools/pages/`, 2 `modtools/layouts/`). **2,678 total `<b-*>` tag instances across 56 distinct component names.**

| Rank | Component | Count | Rank | Component | Count |
|---|---|---|---|---|---|
| 1 | `b-button` | 659 | 15 | `b-input-group` | 45 |
| 2 | `b-col` | 382 | 16 | `b-tr` | 39 |
| 3 | `b-row` | 192 | 17 | `b-spinner` | 38 |
| 4 | `b-form-input` | 137 | 18 | `b-tab` | 36 |
| 5 | `b-badge` | 110 | 19 | `b-card-text` | 30 |
| 6 | `b-td` | 104 | 20 | `b-card-header` | 29 |
| 7 | `b-modal` | 93 | 21 | `b-form-text` | 26 |
| 8 | `b-img` | 85 | 22 | `b-alert` | 25 |
| 9 | `b-card` | 78 | 23 | `b-dropdown-item` | 24 |
| 10 | `b-th` | 75 | 24 | `b-list-group-item` | 20 |
| 11 | `b-form-select` | 67 | 24= | `b-form-checkbox` | 20 |
| 12 | `b-form-group` | 65 | 24= | `b-container` | 20 |
| 13 | `b-form-textarea` | 56 | 27 | `b-form-radio` | 15 |
| 14 | `b-card-body` | 54 | 28 | `b-collapse` | 13 |

Long tail (56 names total, ≤12 each): `b-thead/b-tbody/b-table-simple/b-card-title` (12), `b-tabs` (11), `b-input/b-form-select-option/b-card-footer` (9), `b-form` (7), `b-table` (6), `b-list-group/b-dropdown` (5), `b-popover/b-input-group-text` (4), `b-navbar/b-nav-item/b-button-group` (3), `b-progress/b-progress-bar/b-form-invalid-feedback/b-form-checkbox-group` (2), and nine components used exactly once.

**PascalCase `<Bxxx>` usage is negligible**: only 13 hits, and all but two (`<BCarousel>`, `<BCarouselSlide>`) are false positives — Freegle's own components starting with `B` (`BirthdayHero`, `BulkItemEditor`, etc.). Effectively the whole codebase uses kebab-case, registered as a fixed 22-component allow-list in `nuxt.config.ts` (~line 449-470).

Split by app: **Main site** leans on `b-button`(412)/`b-col`(245)/`b-row`(126)/`b-img`(79)/`b-modal`(62)/`b-badge`(60). **ModTools** leans equally on buttons/grid but additionally hammers raw table tags (`b-td` 73, `b-th` 58, `b-tr`, `b-table-simple`) for its data-grid screens.

### 1.2 Directives and programmatic APIs

- `v-b-tooltip*`: 52. `v-b-toggle*`: 11. `v-b-modal*`: 0 (modals opened via refs/`.show()`).
- `useToast()`/`$bvModal`/`$toast`/`createToast`: **0 anywhere**. No toast library is wired up at all — a stale SCSS comment mentions `vue-toasted` but it's not a dependency. Notices are bespoke components/modals. This removes one of the trickiest bits of a typical UI-kit swap.
- Direct `bootstrap` JS imports: **0**. bootstrap-vue-next is pure Vue; the `bootstrap` npm package is pulled in only for its SCSS.

### 1.3 SCSS / design-token surface

- `assets/css/bootstrap-custom.scss` (70 lines, duplicated at `modtools/assets/css/bootstrap-custom.scss`) is a **hand-pruned** subset of ~24 Bootstrap SCSS partials plus `bootstrap-vue-next/dist/bootstrap-vue-next.css` — accordion/breadcrumb/pagination/toasts/offcanvas are explicitly commented out as unused.
- `assets/css/_color-vars.scss` (85 lines): ~30 `$color-*` variables, **1,640 usages** across 64 distinct names.
- `assets/css/_design-tokens.scss` (63 lines) **already exists** as a CSS-custom-property bridge (`--color-primary`, `--shadow-*`, `--radius-*`) derived from the same `$color-*` vars, with **277 existing `var(--color-*)` usages**. This is a half-finished bridge toward a Tailwind/Nuxt-UI-style token system, built for unrelated reasons but directly reusable.
- Utility-class density (both apps): `row` 871, `mb-N` 676, `align-items-*` 577, `justify-content-*` 498, `text-muted` 443, `me-N` 413, `d-flex` 381, `p-N` 239, `ms-N` 207, `text-center` 152, `d-none` 146. **7,332 total `class="..."` attributes across 581 files.** This layer is separate from `<b-*>` components and survives/dies with whichever CSS framework is loaded.

## 2. Nuxt UI current state

- Latest is **@nuxt/ui 4.10.0**, Tailwind CSS 4 + reka-ui 2.10.1 based, requires Nuxt 4 (`@nuxt/kit ^4.4.8`) — Freegle is already on `nuxt ^4.5.1`, so that gate is cleared. Deps also show `@tanstack/vue-table` (Table), `embla-carousel-vue` (Carousel), `@iconify/vue`+`@nuxt/icon` (icon system).
- Catalogue: 125+ components across Layout/Element/Form/Data/Navigation/Overlay/Page/Dashboard groups — everything Freegle needs exists (Table, Modal, Toast, Popover, Collapsible, NavigationMenu, Select, Form, Input, Carousel, Tabs, Alert, Badge, Card, DropdownMenu, Progress, Tooltip).
- Licensing: Nuxt UI's docs describe it as free/open source; the historical Pro/paid split was merged into the free core as of v3 — no components needed here appear gated as paid (worth a final license-file check at kickoff since this moves fast).

## 3. Mapping table — top components

| bootstrap-vue-next | Count | Nuxt UI equivalent | Fit | Notes |
|---|---:|---|---|---|
| `b-button` | 659 | `UButton` | Good | Prop rename (`variant`/`color` split). |
| `b-col`/`b-row`/`b-container` | 594+20 | *(none — no grid system)* | **Gap** | Nuxt UI assumes Tailwind flex/grid utilities directly. Highest-volume, highest-risk item — every `<b-row><b-col>` needs hand conversion. |
| `b-form-input`/`b-input` | 137+9 | `UInput` | Good | v-model compatible. |
| `b-badge` | 110 | `UBadge` | Good | |
| `b-td`/`b-th`/`b-tr`/`b-thead`/`b-tbody`/`b-table-simple` | 104+75+39+12+12+12 | `UTable` | **API mismatch** | `UTable` is a `@tanstack/vue-table` columns/rows config, not composable `<tr>/<td>` tags — ModTools' bespoke inline-editing grids need genuine rewrites. Highest-*effort*-per-instance item. |
| `b-modal` | 93 | `UModal` | Behavioural gap | ref-driven `.show()` → state-driven `v-model:open`; slot names differ. |
| `b-img` | 85 | plain `<img>`/`NuxtImg` | Trivial | No Nuxt UI image component; drop it. |
| `b-card`+sub-components | 78+54+29+30+12+9+1 | `UCard` | Good | Fewer named sub-slots than bvn; mechanical, ~213 instances. |
| `b-form-select`(+option) | 67+9 | `USelect`/`USelectMenu` | Behavioural gap | `{value,text}` → `{label,value}` rename. |
| `b-form-group` | 65 | `UFormField` | Good | |
| `b-form-textarea` | 56 | `UTextarea` | Good | |
| `b-input-group`(+text) | 45+4 | *(no direct equivalent)* | **Gap** | Replaced by `leading`/`trailing` slots/props on `UInput`. |
| `b-spinner` | 38 | *(no free-catalogue equivalent)* | **Gap** | Freegle already has its own `Spinner` component; keep it regardless. |
| `b-tab`/`b-tabs` | 36+11 | `UTabs` | API mismatch | items-array + slots, not child components. |
| `b-alert` | 25 | `UAlert` | Good | |
| `b-dropdown`(+item/divider) | 5+24+1 | `UDropdownMenu` | API mismatch | items-array pattern. |
| `b-list-group`(+item) | 5+20 | *(none direct)* | **Gap** | Easy to hand-roll. |
| `b-form-checkbox`(+group) | 20+2 | `UCheckbox`(Group) | Good | |
| `b-form-radio`(+group) | 15+1 | `URadioGroup` | Good | Restructuring (children → items prop). |
| `b-collapse` | 13 | `UCollapsible` | Good | |
| `b-popover` | 4 | `UPopover` | Good | |
| `b-navbar`(+nav/brand) | 5 total | custom/`UHeader` | Behavioural gap | Freegle's navbar is bespoke either way — low risk given low count. |
| `b-progress`(+bar) | 4 total | `UProgress` | Good | |
| `b-carousel`(+slide) | 2 total | `UCarousel` | Good | |
| `v-b-tooltip` | 52 | `UTooltip` (wrapping component, no directive) | Behavioural gap | Mechanical but touches tight table/badge markup. |
| `v-b-toggle` | 11 | `UCollapsible` trigger slot | Behavioural gap | |

**No toast/BvModal-style global API exists to migrate** — eliminates a usually-significant risk category.

## 4. Feel preservation

**Maps cleanly**: Freegle's green palette already has a CSS-custom-property bridge (`_design-tokens.scss`) with 277 live usages; Nuxt UI's theming is also CSS-variable-driven (`--ui-primary` etc., generated from a Tailwind color scale). Both systems converge on "CSS variables carrying brand colors," so palette + border-radius (`--radius-sm/md/lg/xl` already exist at 6/8/12/20px) mapping is a moderate theming pass, not a hard problem, for **newly-authored** Nuxt UI components.

**Cannot be preserved cheaply**: the ~7,332 Bootstrap utility-class usages (`d-flex`, `mb-3`, the entire `row`/`col-N` grid) only work while Bootstrap's compiled CSS stays loaded. Nuxt UI ships Tailwind CSS 4, whose `preflight` reset actively fights Bootstrap's reboot the moment both are on a page (box-sizing, heading/list resets, form-control styling all disagree). Crucially, the grid/utility classes are **not part of bootstrap-vue-next** — they're plain Bootstrap CSS — so swapping the JS component library does not remove this dependency; it's a separate, larger job.

Coexistence options, best to worst: (1) disable Tailwind `preflight` + prefix Tailwind utilities (`tw-`) so `d-flex`/`tw-flex` can't collide — least invasive, but Nuxt UI's own component internals ship unprefixed Tailwind classes, so preflight must still be globally disabled or `@layer` ordering used; (2) explicit CSS `@layer` ordering between Bootstrap and Tailwind layers — cleaner, but needs auditing any source-order-dependent Bootstrap overrides; (3) "keep Bootstrap CSS, drop bvn JS only" — not really viable since Nuxt UI components bake in their own Tailwind classes; (4) page-level CSS isolation — too heavy for Freegle's shared-layout SPA structure. **Net: component swap and utility/grid migration are two separate projects usually bundled but not required to be — bounding the blast radius means keeping Bootstrap's grid/utilities loaded, layered under Tailwind with preflight off and a prefix, for the duration.**

## 5. Migration strategies with effort estimates

Sizing context: **739 Vitest spec files, ~13,970 `it()`/`test()` cases, 49 Playwright e2e specs.** Global stubs live in **one file**, `tests/unit/setup.ts`, stubbing only 10 `b-*` names — not scattered as the "690 files" framing implied. However **338 spec files additionally carry their own local `stubs:`/mount overrides** referencing `b-*` tags (233 reference `'b-button'`, 82 `'b-modal'`, 69 `'b-img'`), so the *local* per-test stub surface genuinely is wide, close to the scale implied.

- **(a) Upgrade bvn 0.24 → 0.45.** bvn 0.45's own deps confirm it has **already moved internals to `reka-ui`** — same headless engine Nuxt UI uses — while keeping its Bootstrap-flavoured API/CSS. Mostly a compatibility-patch exercise. **Estimate: 1–2 dev-weeks.** Low risk, no visual change, doesn't unlock SSR.
- **(b) Full Nuxt UI big-bang.** All 2,678 tag instances converted, ~614 grid tags structurally reworked, ~254 ModTools raw-table tags rewritten onto `UTable`'s model, 338 spec files' stubs rewritten (plus most of the other 739 touched at least lightly via global config ripple), 49 Playwright specs audited for Bootstrap-class selectors, full CSS-coexistence problem solved everywhere at once. **Estimate: 10–16 dev-weeks** for 1-2 devs to reach parity + green tests, before visual QA across ~130 pages.
- **(c) Incremental, page-by-page coexistence.** Both frameworks loaded per the layered/prefixed strategy; migrate leaf components first (`BBadge`, `BAlert`) before touching the grid; tests migrate in lockstep. **Estimate: 12–20 dev-weeks total, spread over 4–8 calendar months** alongside normal feature work. Lowest risk; never blocks shipping; pays a sustained dual-stack CSS tax throughout.
- **(d) Hybrid: bvn 0.45 now, Nuxt UI later.** Do (a) immediately, treat (b)/(c) as a separately-scoped later project. **Estimate: 1–2 dev-weeks now**, defers the big decision without foreclosing it (bvn 0.45 and Nuxt UI now share the reka-ui primitive layer, so it's not wasted motion).

## 6. Recommendation

**Ranked: (d) hybrid now → (c) incremental later. Not (b) big-bang. Not indefinite (a)-only.**

1. **Now**: upgrade to bvn 0.45 (1–2 weeks) — low cost, removes security/version drift, not wasted if Nuxt UI comes later since bvn already runs on reka-ui.
2. **Medium term**: pursue incremental (c), not big-bang (b) — the grid/table/tabs/dropdown items need structural rewrites, not renames, making a multi-month freeze (b) hard to justify for a moderate visual refresh.
3. Start new pages directly in Nuxt UI; prove the CSS-coexistence strategy on 2-3 low-grid leaf components (`Badge`, `Alert`, `Card`) before touching `b-row`/`b-col`.
4. Defer the grid and ModTools' raw table markup to last — highest effort, easiest to visibly get wrong.

**Top 5 risks:**
1. **Tailwind preflight vs Bootstrap reboot collision** — breaks headings/lists/forms sitewide immediately unless prefix/layer strategy is settled and prototyped before any component migration begins.
2. **ModTools table rewrites** — `b-td`/`b-th`/`b-tr`/`b-table-simple` (254 instances) don't map onto `UTable`'s model without genuine rewrites, in Freegle's most operationally sensitive surface (moderation tooling).
3. **Test-suite churn masking real regressions** — 338 spec files with bespoke Bootstrap-class-keyed stubs risk becoming "fix the stub to pass" busywork under a big-bang; incremental migration keeps this ratio manageable per PR.
4. **SSR is currently blocked specifically by Bootstrap/bvn** (`nuxt.config.ts` comment says so explicitly), forcing `ssr:false` on `/browse`, `/chats`, `/post`, and all of ModTools. A full migration could unlock SSR/SEO gains — but only once the *last* bvn component leaves a route, which cuts against the incremental strategy's main advantage and should be weighed explicitly for high-traffic public routes.
5. **Icon-system mismatch** — Nuxt UI expects Iconify string names via `@nuxt/icon`; Freegle uses `@fortawesome/vue-fontawesome` component instances via a custom `v-icon` global. Needs a FontAwesome-as-Iconify-collection bridge or slot-based overrides, or it silently breaks icon props on nearly every button/input touched.

Full report file: `/tmp/claude-1000/-home-edward-FreegleDockerWSL/86d50c30-4019-401d-a0dc-723b5f430b0d/scratchpad/nuxt-ui-migration-report.md`