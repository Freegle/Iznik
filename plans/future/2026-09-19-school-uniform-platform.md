# School uniform reuse platform: proposal review and build plan

- Source: "School Uniform Reuse Platform Proposal September 2026.docx" (draft, working name "My School Wardrobe")
- Reviewed: 2026-09-19, against `Freegle/Iznik` master `92dccb6e3` and the Lend & Tend fork at `/home/edward/lend-and-tend`
- Worktree: `FreegleDocker-uniforms`, branch `plan/school-uniform-platform`
- Status: plan only. Nothing built. The proposal itself says not to build a full platform before pilot funding and two positioning decisions.

## 1. Verdict in six lines

- The proposal is honest about market risk but silent on the two things that decide whether the tech is worth building: how a parent proves they belong to a school, and whether micro-payments can carry a take rate at all.
- At £3.50 an item, card processing costs more than the platform's share. The revenue model in section 14.3 does not include payment fees and collapses when they are added.
- Roughly 70% of the pilot feature list already exists in Freegle. The real new work is a schoolwear listing form, closed-group membership, per-school branding, and (later) payments.
- Lend & Tend shows the layer pattern works and Fly.io hosting works. It also shows a fork rots: it is 4,471 commits behind upstream after four months and cannot be merged.
- Recommendation: build it as a layer inside this repo on master (like `modtools/`), deploy as a separate stack on Fly.io using the L&T configs renamed, and run the pilot without in-platform payments.
- Do not start Phase 1 until the school-verification method and the free-vs-paid positioning are decided. Both change the data model.

## 2. Adversarial review of the proposal

The proposal's own section 17 covers market crowding, school disincentives, thin revenue, no moat, legal de-branding, promotion dependence, small-sample pilots, seasonality and mission drift. The points below are the ones it misses or understates.

| # | Finding | Why it matters | What to do |
|---|---|---|---|
| A1 | Payment fees are missing from the revenue model. Stripe UK cards cost 1.5% + 20p; a £3.50 sale costs about 25p, which is 7% before any take. The modelled 15% cut is 52p, so 27p is left to split and the platform's third is about 9p per item. The "strong engagement, larger secondary" scenario drops from £336 to roughly £170 platform revenue, and the school share to roughly £340 | This is the whole business case | Model with fees. Test a Vinted-style buyer fee, or no in-platform payments at all (see A3) |
| A2 | Section 1 says "£5--£1.5 billion" a year; section 3.5 says "hundreds of millions". The Brighton figure of 840kg thrown away sits oddly beside 29,000 uniforms a year | A funder or school will spot it | Fix the numbers and cite one source per figure |
| A3 | In-platform payment is presented as the core incentive ("removes the awkwardness of asking for money") with no evidence. Every named competitor lets people arrange payment themselves. Taking money makes the platform a payment service: Stripe Connect onboarding for each PTA, refunds, disputes, chargebacks, HMRC digital-platform seller reporting (in force since January 2024), and a VAT question on the platform fee | Largest single chunk of build and compliance work, for the least-evidenced claim | Pilot with prices shown and payment arranged at handover. Offer a "pay it forward" donation to the PTA using the PTA's own existing donation link. Add payments only if the pilot shows people want them |
| A4 | "Closed, verified" school groups are the safety and trust claim, but the proposal never says how a parent is verified. Wonde and Arbor are described as medium-term. Without them the options are an invite code from the PTA, or manual approval by the same "time, cash and technology poor" volunteer | Decides the data model, the onboarding flow and the safeguarding story | Decide before building. Recommended: per-school invite code held in the group's settings, PTA admin can rotate it, plus optional manual approval |
| A5 | The two positioning decisions in 11.2 are not independent. A white-label site carrying a school's logo and colours without the school's consent is trademark and reputational misuse. "Parent-led, smuggle it in" rules out white-label | The proposal treats them as separate choices to test in parallel | Present them as one decision with three legal combinations: school-endorsed white-label, school-endorsed with platform brand, parent-led with platform brand |
| A6 | The compliance pitch relies on DfE guidance that second-hand uniform be "freely accessible". A school whose only provision is a paid marketplace is exposed to the argument that it has not met the spirit of the duty | The strongest selling point weakens if free listings are second-class | Make free listings first-class in the UI and let the PTA list donated stock at zero price. Report free and paid volumes separately so a school can evidence both |
| A7 | "Freegle School" failed and the proposal says the lessons should shape this attempt, but never states what the lessons were | Without a post-mortem, "do it differently" is a hope | Add a half-page on what Freegle School was, what happened, and which of those causes this proposal removes |
| A8 | Facebook is the incumbent with zero switching cost and every parent already in it. Structured search and alerts only beat a Facebook group once volume makes scrolling painful, which is exactly the scale a one-school pilot will not reach | Chicken and egg: the differentiators need the volume the pilot cannot produce | Pilot a cluster of 3 to 5 schools in one town from the start, as 15.2 half-suggests. Seed stock before launch through a PTA collection day |
| A9 | The pilot has no baseline. Success metrics are absolute counts with nothing to compare against | An inconclusive result is the likeliest outcome (17 says so) | Before launch, count the pilot school's existing Facebook group posts and sales for one term. Compare like for like |
| A10 | No calendar. Peak demand is the end of the school year. A September 2026 proposal that funds in the autumn needs stock listed by May 2027 | Launching cold into July with empty shelves is the failure mode the proposal names in 15.4 | Fix dates: decisions by November 2026, working site by February, seeding March to May, measured peak June to July 2027 |
| A11 | Running costs are not in the £2,000. Hosting, domain, email sending, error monitoring, moderation time, and dispute handling between parents at the same school gate | The ask under-counts the true cost | Add a running-cost line (around £25 to £60 a month at pilot scale) and name who moderates |
| A12 | The Online Safety Act 2023 applies to any user-to-user service. Illegal-content risk assessment duties have applied since March 2025 regardless of size. Nothing in the proposal | A new service needs its own risk assessment and reporting route on day one | Reuse Freegle's report flow and moderation tooling; write the risk assessment in Phase 0 |
| A13 | Data protection: listings are indirectly children's data (school, year group, size). "Access for social support workers" and "graceful deregistration as children leave" both mean storing pupil-linked data | Needs a DPIA and a clear controller relationship with each school | Store nothing about the child. A listing is an adult's item with a size. Drop year-group fields from the pilot |
| A14 | "Data is a genuine point of differentiation". The platform sees only its own transactions, which the proposal admits are a minority of reuse. A few hundred items a year from a pilot is not a dataset | Weak as a moat, and school-linked data is sensitive | Keep reporting as a feature for schools, not as a strategic claim |
| A15 | Inclusivity: Facebook is criticised for excluding families without smartphones or English, and the answer is another app | The gap named in 9 is not closed | Name one concrete mechanism for the pilot: the PTA lists on behalf of families at a collection table, or a printed weekly list at the school office |
| A16 | Brand: Freegle means free. The proposal separates the brand but then routes recruitment through Freegle members and Freegle's research and infrastructure | A paid marketplace adjacent to Freegle risks the core message | Decide the brand relationship in writing before any public page exists |
| A17 | Stuff4Life de-branding is a retail supply channel, not a reuse feature. It belongs to a different business | Named mission drift in 17, but still listed as an add-on | Move it to section 16 (future) |
| A18 | AI-assisted listing from a photo is cheap because Freegle already has it, but uniform photos are mostly navy jumpers. Category and size come from a form, not a photo | Effort spent on the wrong friction | Keep the existing recogniser for the title only. Make size and category two taps |

## 3. Functional requirements extracted from the proposal

Priority: P1 = needed for the pilot, P2 = pilot if cheap, P3 = after the pilot.

| ID | Requirement | Source | Priority |
|---|---|---|---|
| FR-1 | A closed community per school; listings visible only to that school's members | 11.3 | P1 |
| FR-2 | A way to verify a member belongs to the school (invite code, PTA approval, or MIS integration) | 11.3, 13.3, A4 | P1 |
| FR-3 | Parents and the PTA or school itself can list items | 11.3 | P1 |
| FR-4 | Listings can be for sale (with a price) or free | 11.1, 14.4 | P1 |
| FR-5 | Category on input: clothing, PE kit, shoes, sports equipment, costumes, books, calculators, outdoor (DofE) kit, other | 2.3, 11.3 | P1 |
| FR-6 | Size and condition on input | 11.3 | P1 |
| FR-7 | Keyword search plus filters by category, size, price and condition | 11.3 | P1 |
| FR-8 | Saved searches with alerts when a matching item is listed | 11.3 | P1 |
| FR-9 | Wanted posts, so a parent can ask for a size 9 blazer | 12 (Schooly) | P1 |
| FR-10 | In-app chat with no personal contact details shared | 11.3 | P1 |
| FR-11 | A PTA or school administrator role: approve members, remove listings, edit the school page | 11.3 implied, 11.4 | P1 |
| FR-12 | Per-school branding: subdomain, logo, colours, photos | 11.2 | P1 (if school-endorsed) |
| FR-13 | Reporting for the school: listings, completed exchanges, free versus paid, estimated savings | 11.4, 4.4 | P2 |
| FR-14 | "Recommend to another parent" invitation by email or link | 11.3 | P2 |
| FR-15 | AI-assisted listing: suggest title from a photo | 11.3 | P2 |
| FR-16 | Optional "pay it forward" donation to the PTA on a free give | 14.4 | P2 |
| FR-17 | Graceful deregistration: a leaving date with a grace period to sell remaining items | 11.3 | P2 |
| FR-18 | Cross-school visibility for generic items, or for named partner schools | 11.3, 11.4 | P2 |
| FR-19 | In-platform payment with a configurable split between PTA and platform | 11.1, 14 | P3 |
| FR-20 | Bulk listing for the PTA (a collection day's stock, end-of-line uniform) | 11.4 | P3 |
| FR-21 | School "wanted" requests to parents (materials for projects) | 11.4 | P3 |
| FR-22 | Proxy access for family liaison or support workers | 11.4 | P3 |
| FR-23 | Rental for short-lived, higher-value items | 14.4 | P3 |
| FR-24 | Links to end-of-life routes (Recycle Now, Love Your Clothes, Sal's Shoes) | 11.4 | P2 (static content) |
| FR-25 | Email programme: welcome, new-listing alerts, digests, nudges before peak periods | 15.4 | P1 |
| FR-26 | Moderation and spam handling; report a listing or a member | A12 | P1 |

## 4. Requirements against the Freegle codebase

Paths are in `Freegle/Iznik` master unless marked L&T. "Gap" is what has to be written.

| FR | What exists | Gap | Size |
|---|---|---|---|
| FR-1 | `groups` table (`settings` JSON, `type`, `nameshort`, `profile`, `cover`, `tagline`, `description`); `memberships` with `collection` ENUM `Approved`/`Pending`/`Banned`; `messages_groups` scopes a post to a group; the Go API already filters listing and search by `groupids` (`iznik-server-go/message/search.go` `GetWordsExact` and siblings). L&T proved a single world group works as a partition with no schema change (`2026_05_21_000001_add_lat_columns_to_users.php`) | Mark a group as a school with `settings.schoolwear = true`. Hide school groups from Freegle's group lists and map (`publish`, `onmap`, `listable` columns already exist for this) | Small |
| FR-2 | Nothing. `group.go:1036` inserts every join as `Approved`. `groupWork.go:205` counts `Pending` members for moderators, so the concept survives in the API. ModTools has no pending-members page (`iznik-nuxt3/modtools/pages/members/` has `approved` only) | Go: on join, if `settings.invitecode` is set require a matching code, else insert `Pending` when `settings.approvemembers` is true. Layer: an admin page to approve or reject. No DDL | Small to medium |
| FR-3 | Any member posts a message (`POST /message`). Moderators and owners are `memberships.role` | None for parents. PTA account is an `Owner` member | None |
| FR-4, FR-5, FR-6 | `messages` has no price, size, condition or category (`2025_12_10_094529_create_messages_table.php`). `messages_items` links to `items` by name only | New table `messages_schoolwear` (DDL in section 8). Go: extend `PostMessageRequest` in `iznik-server-go/message/message.go` (around line 3165) and the message JSON. Layer: a form component, following L&T's `lat/components/lat/GardenFormFields.vue` | Medium |
| FR-7 | Keyword search: exact, typo, prefix and sounds-like in `search.go`, plus vector search in `vectorsearch.go` and `search_hybrid.go`. Filters: group, type, map bounds | Add category, size, price range and condition filters as a join on `messages_schoolwear` in `search.go` and the browse endpoint | Medium |
| FR-8 | `users_searches` table; `iznik-batch/app/Services/FirstReply/MatchMailService.php` mails members with a matching saved search or an open opposite-type post when a new post arrives | Matching is reach-aware and geographic. Add a group-scoped path for school groups. Size and category matching if wanted | Small to medium |
| FR-9 | `messages.type = 'Wanted'` and the two-way matcher above | None | None |
| FR-10 | `chat_rooms`, `chat_messages`, `chat_roster` (blocking), report flow. L&T reused it unchanged with skinned components (`lat/components/ChatPane.vue` and friends) | None | None |
| FR-11 | `memberships.role` Owner and Moderator; `rg.Patch("/group", group.PatchGroup)` at `router/routes.go:701` lets moderators edit group settings; ModTools pending-post queue; L&T shipped admin pages inside the layer (`lat/pages/admin/*`) rather than finishing `modtools-lat` | A small admin area in the layer: members (with approve), listings, school page. Reuse ModTools where a page already exists | Medium |
| FR-12 | `groups.profile` (logo attachment), `cover`, `tagline`, `description`. L&T branding is build-time and single-tenant (`lat/branding.config.ts`). No hostname-to-group routing anywhere in `iznik-nuxt3` | Runtime branding: a Nuxt server middleware maps `<nameshort>.<domain>` to a group, loads `settings.branding` (colours) and `profile`, and sets CSS variables in the head. L&T's `nuxt.config.ts` head `style` block is the template | Medium |
| FR-13 | `messages_outcomes` records Taken and Received; `stats` table and ModTools dashboard; `electricals/stats` (`router/routes.go:287`) is the precedent for a scheduled stats job served from a table | A `uniform:stats` batch command and a school report page | Medium |
| FR-14 | `users_invitations` table and `App\Models\UserInvitation`. No Go route and no page: the feature was in the retired PHP API | Go route to create an invitation, a mail, and a landing page that pre-fills the school code | Small |
| FR-15 | `messages_attachments_recognise` table; `PhotoUploader.vue` `recognise` prop; `items.suggestfromphoto` | None to reuse for title. Category and size stay as form fields | None |
| FR-16 | `DonationAskModal.vue`, `donations/stripe.go` `CreateIntent`. All wired to Freegle's own Stripe account | Use the PTA's own external donation link from `settings.donatelink`. No money handled by us in the pilot | Small |
| FR-17 | Nothing. Members leave a group at will | `memberships.settings.leaving_on` (JSON, no DDL) plus a batch command that mails a reminder and removes after the grace period | Small |
| FR-18 | `messages_groups` is many-to-many, so one post can sit in several groups. Rippling (`rippling_reach`) spreads posts geographically and is wrong for schools | Post to the school plus named partner schools from `settings.partners`. Keep rippling off for school groups | Small |
| FR-19 | `donations/stripe.go` (PaymentIntents, subscriptions), `donations/stripeipn.go` (webhooks). L&T's `lat/server/api/checkout.post.ts` and `stripe-webhook.post.ts` do a one-off Stripe Checkout for the £12 joining fee | Stripe Connect Express accounts per PTA, PaymentIntents with `application_fee_amount` and `transfer_data.destination`, refund and dispute handling, HMRC seller reporting, a VAT position. New tables for orders and payouts | Large. Phase 2 only |
| FR-20 | `iznik-nuxt3/pages/clearance/`, `iznik-server-go/message/bulkEdit.go`, `iznik-batch/app/Console/Commands/BulkOffer/*`, `Message/BulkPostCommand.php` | Schoolwear fields on bulk posts | Small, after FR-4 |
| FR-21 | `type = 'Wanted'` posted by the PTA account | None | None |
| FR-22 | `users.systemrole` Support; no post-on-behalf in the Go API | Out of scope for the pilot | Large |
| FR-23 | Nothing | Out of scope | Large |
| FR-24 | Static pages in the layer (L&T: `lat/pages/help.vue`, `what-to-expect.vue`) | Copy | Small |
| FR-25 | Laravel mail with MJML, spool, Mailgun in L&T (`iznik-batch/app/Mail/Lat/*`, `resources/views/emails/mjml/lat/*`, `routes/console.php` `LAT_BATCH_ONLY` schedule) | A `Uniform` mail set and schedule block on the same pattern | Medium |
| FR-26 | Content check, spam scoring, `messages_groups.collection` Pending queue, ModTools, report flows. L&T set the world group to `UNMODERATED` with a trigger (`2026_05_25_000001_lat_world_auto_unmoderated.php`) | Decide per school: PTA-moderated (default Pending) or auto-approve. Both exist | None |

Summary: FR-3, 9, 10, 15, 21, 26 need no code. FR-1, 2, 14, 16, 17, 18, 20, 24 are small. FR-4 to 8, 11, 12, 13, 25 are the real Phase 1 build. FR-19, 22, 23 are Phase 2 or never.

## 5. How the Lend & Tend fork was done

Facts from `/home/edward/lend-and-tend` (remote `edwh/lend-and-tend`, upstream `Freegle/Iznik`).

| Aspect | What L&T did |
|---|---|
| Repo | A separate GitHub fork with an `upstream` remote. 61 commits on top of a merge base from 2026-05-21. Never merged upstream since: now 4,471 commits behind |
| Frontend | A Nuxt layer at `iznik-nuxt3/lat/` (150 files) with `extends: ['../']`, the same mechanism as `iznik-nuxt3/modtools/`. Overrides navbar, chat, login, profile and promise components; adds map, listing, agreement, admin and marketing pages |
| Branding | Build-time, single-tenant `lat/branding.config.ts` plus a `plugins/branding-css.ts` and an inline critical-CSS block in `nuxt.config.ts`. Strips Freegle's ad scripts and Sentry from the merged config |
| Backend rule | Stated as "never touch `iznik-server-go` or `iznik-batch`". In practice the fork changed `message/message.go` (promise terms, an `AcceptAgreement` action), added three column migrations on `messages_promises`, three data migrations (world group, demo users, auto-unmoderated trigger), six `Lat` batch commands, nine `Lat` mail classes with MJML views, and a `LAT_BATCH_ONLY` schedule block in `routes/console.php` |
| Deletions | Removed the PHP API (`iznik-server/`) in the foundation commit; upstream has since done the same. Also deleted `auth/auth_jwt_test.go` and `misc/loki_client_test.go` from Go, and briefly added SQLite support that was then abandoned (`lat.db` still in the tree) |
| Unrelated cargo | `pr-walkthrough/` video tooling and a `status-nuxt` change travelled in the fork |
| Data | Own database (`lat`), own tusd upload store and weserv image delivery. Lesson recorded in commit `cc00c11a7` and `deploy/fly/README-FLY.md` gotcha 5: with `UPLOADS` unset the Go API builds image URLs on Freegle's `uploads.ilovefreegle.org` |
| Payments | Stripe Checkout for a one-off £12 joining fee in Nuxt server routes (`lat/server/api/checkout.post.ts`, `stripe-webhook.post.ts`), with a fail-safe fake-payment flag for dev |
| Mail | Mailgun SMTP for both Laravel mail and the Nuxt contact form; Mailpit in dev |
| Hosting 1 | Katapult VM with `docker-compose.lat.yml` (12 services) behind Caddy (`deploy/caddy/Caddyfile`), May to August 2026. Nuxt built at container start, so every recreate was a two-minute outage; `deploy/deploy-lat-nuxt.sh` rebuilt in place to avoid it |
| Hosting 2 | Fly.io from 2026-08-27 (`deploy/fly/`): ten apps, a Caddy proxy app for path routing, MySQL and the batch scheduler always on, everything else scale-to-zero, baked images. Measured cold start: Nuxt 4s, API 30s. The VM was backed up to `katapult-vm-backup/` and decommissioned |
| Admin | `modtools-lat/` never built (missing the layer's CSS wiring). Admin pages were put inside `lat/pages/admin/` instead |
| Tests | Playwright suite at `iznik-nuxt3/tests/e2e/lat/`; Laravel tests for the Lat mails; no CI |
| Docs | `CLAUDE.md` still says Fly was abandoned in favour of Katapult, three commits after Fly went live. The README's migration list and the `2026_05_21_000001_add_lat_columns_to_users.php` file name are both wrong about what the migration does |

Lessons for the uniform platform:

- Fork drift is the real cost. Four months without a merge made the fork unmergeable. Every upstream fix (security, rippling, moderation) is missing from L&T.
- "Never touch upstream" does not survive a real feature. Schema, batch and Go changes were needed within days. Put them upstream behind a group setting from the start.
- Build-time branding is single-tenant. Uniform needs per-school branding at runtime.
- Fly works, with the documented workarounds (flycast ports, MySQL over `.internal` with `mysql_native_password`, `performance_schema` off to fit 512MB, `UPLOADS` set, weserv resolver).
- Keep infrastructure config in the repo (they did) and keep the docs true (they did not).

## 6. Repo strategy: fork, branch, or in-repo layer

| Option | How | Cost | Verdict |
|---|---|---|---|
| A. Fork (the L&T way) | New repo forked from `Freegle/Iznik`, layer plus backend changes | Drift. Duplicate CI. Upstream fixes lost. Two codebases to know | No |
| B. Long-lived branch in this repo | `uniform` branch, periodic merges from master | Same drift as A minus the repo hop. CI runs the whole Freegle suite on every merge. Conflicts in shared Go and batch files | No |
| C. Layer on master | `iznik-nuxt3/uniform/` next to `modtools/`; batch commands under `app/Console/Commands/Uniform/`; Go changes gated on `settings.schoolwear`; migrations in the shared set; separate compose and deploy files. Ordinary feature branches and PRs | Freegle's CI covers it. Docs rule (same-PR) applies. Small risk of touching Freegle behaviour, controlled by the gate and by tests | Yes |

Deciding factor: C is right while this is Freegle's own product. If it becomes a separate company with its own developers (as Lend and Tend Ltd is), fork at that point from a master that already contains the layer. Forking early buys nothing.

## 7. Hosting: Fly.io or Katapult (Krystal Cloud)

| | Fly.io | Katapult VM |
|---|---|---|
| Precedent | L&T production since 2026-08-27; `lend-and-tend/deploy/fly/` is ten working app configs plus a gotcha list | L&T production May to August 2026; `docker-compose.lat.yml` plus `deploy/caddy/Caddyfile`. Freegle production already runs on Krystal VMs, so ops know it |
| Shape | Ten apps: proxy (Caddy), nuxt, api, admin, batch, mysql, redis, mjml, tusd, delivery. Private network, path routing in the proxy | One VM, one compose project, Caddy on the host with Let's Encrypt |
| Cost at pilot scale | About $20 to $35 a month: mysql and batch always on at shared-cpu-1x, api and nuxt kept warm (recommended, see below), the rest scale to zero, two 3GB volumes | About £20 to £40 a month for a VM with 4GB or more (the Nuxt build needs over 2GB unless images are built elsewhere) |
| Cold start | Nuxt 4s, API 30s when scaled to zero. A parent clicking an alert email at 8am must not wait 30s: set `min_machines_running = 1` on api and nuxt, about $10 a month more | None |
| Deploys | `fly deploy` per app with baked images; no outage | Container recreate is an outage unless built in place (`deploy-lat-nuxt.sh`) |
| Backups | None built in for the MySQL app. Fly volume snapshots are daily and short-lived. Needs a nightly `mysqldump` to object storage | Katapult backup storage, or the same dump job |
| Failure modes | Ten moving parts; MySQL on a 512MB machine only works with the tuned `my.cnf`; secrets spread across apps | One VM is a single point of failure; manual patching |
| Data residency | Region `lhr` | UK |

Recommendation: Fly.io for the pilot. It is proven for exactly this stack, costs near nothing while idle, and the config is a rename of L&T's. Add the two warm machines and a nightly dump to Fly Tigris or Katapult object storage before any real school is on it. Revisit at the point payments arrive: at that stage a managed MySQL or a Krystal VM next to Freegle's own infrastructure is the safer home.

Ruled out: adding it to Freegle's existing batch host as another compose project. That host is already overloaded.

## 8. Build plan

### Phase 0: decide, and stand up a real mock-up (2 to 3 weeks of elapsed time, a few days of work)

Decisions (section 9) come first. In parallel, build the layer skeleton with seeded data so the "mock-up" is the real product with fake content. This answers 15.3's worry about mock-ups being "cheating": nothing is faked except the data.

Files:

- `iznik-nuxt3/uniform/nuxt.config.ts`: copy of `lend-and-tend/iznik-nuxt3/lat/nuxt.config.ts` with the L&T-specific runtime config removed. Keep the CSS override, the ad-script strip, the Sentry blank, `routeRules`, the weserv `baseURL` override and the nitro block.
- `iznik-nuxt3/uniform/package.json`, `tsconfig.json`, `Dockerfile`: copies of the `lat/` equivalents with names changed.
- `iznik-nuxt3/uniform/layouts/default.vue`, `components/NavbarDesktop.vue`, `components/NavbarMobile.vue`: start from the `lat/` versions.
- `iznik-nuxt3/uniform/pages/index.vue` (school landing), `pages/browse.vue`, `pages/give.vue`, `pages/wanted.vue`, `pages/chats/[[id]].vue` (from `lat/`), `pages/admin/index.vue`.
- `iznik-nuxt3/uniform/server/middleware/school.ts`: resolve the group from the request hostname; expose id, name, logo and colours to the app.
- `iznik-batch/app/Console/Commands/Uniform/SeedDemoSchoolCommand.php`: create a school group (`settings.schoolwear = true`, `publish = 0`, `onmap = 0`, `listable = 0`), a PTA owner, twenty parents and forty listings across categories and sizes.
- `docker-compose.uniform.yml` at the repo root: from `lend-and-tend/docker-compose.lat.yml`, services renamed `uniform-*`, database `uniform`, ports from a new `PORT_UNIFORM_*` block in `docker-compose.ports.yml`.
- `docs/developers/uniform.md`: what the layer is and how to run it. Covers the paths above.

Not in Phase 0: any schema change. Price, size and category are shown from seeded `messages.textbody` until FR-4 lands.

### Phase 1: pilot build (estimate 4 to 6 weeks of work)

Schema. One table, plus JSON settings on existing tables. Needs sign-off before the migration is written.

```sql
CREATE TABLE messages_schoolwear (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  msgid BIGINT UNSIGNED NOT NULL,
  category ENUM('Uniform','PE kit','Shoes','Bag','Sports equipment',
                'Costume','Books','Calculator','Outdoor kit','Other') NOT NULL,
  size VARCHAR(32) NULL,
  itemcondition ENUM('New with tags','Excellent','Good','Fair') NULL,
  price_pence INT UNSIGNED NULL COMMENT 'NULL = free',
  UNIQUE KEY msgid (msgid),
  CONSTRAINT messages_schoolwear_msgid FOREIGN KEY (msgid)
    REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Group settings keys (JSON in `groups.settings`, no DDL): `schoolwear` (bool), `invitecode` (string), `approvemembers` (bool), `branding` (`{primary, secondary, logoattid}`), `donatelink` (URL), `partners` (group ids). Membership settings key: `leaving_on` (date).

Go (`iznik-server-go`), each gated on the group's `schoolwear` setting so Freegle is untouched:

- `group/group.go` join: invite code check and `Pending` insert (FR-2).
- `message/message.go`: `Schoolwear` fields on `PostMessageRequest`, written to `messages_schoolwear`; returned on the message struct (FR-4 to 6).
- `message/search.go` and the browse query: category, size, price and condition filters (FR-7).
- `user/user.go`: an invitation route using `users_invitations` (FR-14).
- Tests alongside each change, following the package's existing test files.

Batch (`iznik-batch`):

- `app/Console/Commands/Uniform/`: `SendListingAlertsCommand` (group-scoped saved-search matches, reusing `FirstReply/MatchMailService` logic), `SendWelcomeCommand`, `SendPeakNudgeCommand` (before Christmas, Easter and end of year), `ProcessLeaversCommand` (FR-17), `StatsCommand` (FR-13).
- `app/Mail/Uniform/*` and `resources/views/emails/mjml/uniform/*`: from the `Lat` equivalents.
- `routes/console.php`: a `UNIFORM_BATCH_ONLY` block on the `LAT_BATCH_ONLY` pattern, keeping `chats:process-incoming`, `queue:process-background`, `mail:spool:process` and the Uniform commands.
- Feature tests for each command in `tests/Feature/Uniform/`.

Layer (`iznik-nuxt3/uniform`):

- `components/SchoolwearFormFields.vue` (category, size, condition, price or free) used by `pages/give.vue` and `pages/wanted.vue`.
- `components/SchoolwearFilters.vue` used by `pages/browse.vue`.
- `pages/admin/members.vue` (approve, remove, rotate invite code), `pages/admin/listings.vue`, `pages/admin/school.vue` (name, logo, colours, donate link, partner schools).
- `pages/join/[code].vue`: invitation landing.
- `pages/help.vue`, `pages/end-of-life.vue` (FR-24), `pages/privacy.vue`, `pages/terms.vue`.
- Unit tests under `uniform/tests/unit/`, Playwright under `iznik-nuxt3/tests/e2e/uniform/` with a `playwright.uniform.config.js` on the L&T pattern.

Deployment (`deploy/fly/uniform/`): copies of `lend-and-tend/deploy/fly/*` with app names `uniform-proxy`, `uniform-nuxt`, `uniform-api`, `uniform-batch`, `uniform-mysql`, `uniform-redis`, `uniform-mjml`, `uniform-tusd`, `uniform-delivery`. Changes from L&T: `min_machines_running = 1` on api and nuxt; a `uniform-backup` scheduled machine running `mysqldump` to object storage nightly; wildcard certificate for `*.<domain>` on the proxy so each school gets a subdomain. Secrets as in `README-FLY.md`, never in the toml. Sentry to its own project, consent-gated as L&T did.

CI: the layer's unit tests join the existing vitest run; the Playwright config joins the orb (`.circleci/orb/freegle-tests.yml`) as a new job; the Laravel feature tests run with the rest. Update the orb version when the orb changes.

Excluded from Phase 1 on purpose: in-platform payments (FR-19), proxy access (FR-22), rental (FR-23), year-group fields (A13), rippling for school groups.

### Phase 2: only if the pilot shows demand

- FR-19 payments: Stripe Connect Express per PTA, orders and payouts tables, refunds, disputes, HMRC seller reporting, VAT position. Budget as a project of its own.
- FR-18 cross-school pooling beyond named partners.
- FR-13 report exports for local authorities.
- Wonde or Arbor onboarding to replace invite codes.
- Fork to a separate repo if the product gets its own organisation.

## 9. Decisions needed before Phase 1

| Decision | Options | Effect on the build |
|---|---|---|
| Brand and endorsement (A5) | School-endorsed white-label; school-endorsed with platform brand; parent-led with platform brand | FR-12 is built or dropped; whether a PTA admin exists on day one |
| Member verification (A4) | Invite code; PTA approval; both; MIS integration | FR-2 shape; onboarding copy; safeguarding statement |
| Free, paid or both in the pilot (A3, A6) | Free only; prices shown and paid at handover; in-platform payment | Whether `price_pence` exists in Phase 1; whether Phase 2 exists |
| Pilot cluster (A8) | One school; 3 to 5 schools in one town | Whether FR-18 is P1 |
| Name and domain | Revive "My School Wardrobe"; new name | Fly app names, certificates, email sender domain |
| Who moderates | PTA owner per school; Freegle volunteers; both | FR-26 defaults per group |

## 10. Legal and compliance items to close (not engineering)

- Online Safety Act 2023: illegal-content risk assessment for the new service, reporting route, complaints process (A12).
- UK GDPR: DPIA, controller relationship with each school, retention for leavers, no pupil data (A13).
- De-branding and resale of exclusive-supplier stock: the legal advice the proposal already recommends.
- If payments ever arrive: Stripe Connect terms for charities and unincorporated PTAs, HMRC digital-platform reporting, VAT on the platform fee, consumer-law position on private sales (A3).
- Trademark: no school logo or name on a site without written permission from that school (A5).
