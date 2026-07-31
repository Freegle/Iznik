# Community News

A warm, gently quirky round-up of genuinely local goings-on for Freegle members —
community events, litter picks, swaps, festivals, library/park bits, local
good-news and reuse/environment stories. Written for ordinary people who care a
little about their community and about not being wasteful, never corporate or
worthy.

**Repair cafés are deliberately excluded** from the research prompt: Freegle
already surfaces those as native CommunityEvents, synced from the Restart Project
(`RestartProjectService`) and Repair Café Wales (`RepairCafeWalesService`), so
including them here would duplicate.

It has **two delivery channels that share one research core**:

1. **ChitChat trial** — drip individual items onto the newsfeed, placed at the
   area centre so members nearby see them, every few days. This is the cheap
   engagement signal we watch before committing to email. Posts are **type
   `Alert`**, which the clients render with a hard-coded Freegle logo and
   "Freegle" byline (`NewsAlert.vue`) so provenance is unmistakable — Alerts
   get the same position filtering as Messages, so targeting is unchanged.
   Each post sets **both** content columns: plain `message` (read by the
   newsfeed digest email, notifications and the duplicate guard) and `html`
   (an escaped, title-hyperlinked rendering that clients display in
   preference, suppressing their stacked link-preview cards). `html` is not
   settable through the public API, so it stays trusted; `composeHtml()`
   escapes everything and only links http(s) URLs. Where the source page has
   an og:image, it is re-hosted through TUS/delivery
   (`CommunityNewsImageService`) and attached as the post picture.
2. **Weekly email** — a Freegle-branded MJML digest bundling the recent items
   for an area (each with its re-hosted og:image where available), plus at
   most **one member story** from the area's groups told since the last mail:
   candidates must carry the moderator newsletter flags (`public` +
   `newsletterreviewed` + `newsletter`, the Stories Newsletter bar) and Gemini
   then picks one that is genuinely positive and clearly written — or none.
   Sent to deduplicated, opted-in members **whose home group is in the area**:
   a member is mailed only when the catchment (`groups.polyindex`) of a group
   they belong to covers their location (`settings.mylocation`, else
   `lastlocation`) — membership alone is not enough, so a far-flung join
   (living in Edinburgh, member of Oxford) gets nothing. The footer deliberately does NOT
   carry the generic "Unsubscribe" link (that leaves Freegle completely);
   it names the one relevant control — "Newsletters &amp; stories" in
   Settings — and links there.

(The design is a "rip-off" of the zeitgeist-tape digest: gather locality content →
one model call writes the friendly briefing → deliver. Everything else is
deterministic PHP.)

## How it fits together

```
communitynews-enabled groups
        │  each joins its nearest town (`towns` table, within area_cluster_miles)
        ▼
   community_news_areas ──研究──► Anthropic (web_search) ──► community_news_items
        │                                                          │
        │ post-chitchat (every few days)                           │ email (weekly)
        ▼                                                          ▼
   newsfeed 'Alert' as Freegle             Freegle-branded MJML digest to members
   (logo byline; loves + replies)          (+1 AI-picked member story; opt-out =
                                            newslettersallowed via Settings)
```

## Per-community opt-in

Community News is **off by default** — a community opts in via the `communitynews`
key in the group's `settings` JSON.

- Toggle from the command line:
  `php artisan group:set-community-news --group=EdinburghFreegle --on` (`--off` to disable).
- Or in ModTools: **Group settings → Community News** toggle.

## Areas

The research call searches around the area's **name**, and local news supply is
organised by named place (a local paper's patch, a council's what's-on page) —
so the area unit must be a real, searchable town. Areas are anchored on the
**`towns` table** (~234 curated UK towns): each enabled group joins its nearest
town within `area_cluster_miles` (default 20), and the town's name and centre
become the area's. Each used town is one area, keyed by the lowest enabled
groupid on it (`anchorgroupid`) so re-runs keep the same row and its cadence
timers. A group with no town inside the cap — and every group when the towns
table is empty (dev) — stands alone as its own area, named from the group.

Distance clustering (union-find) was the first design, but it chains
transitively: simulated with every group enabled at 20 miles, mainland England
collapses into ONE 400-group area spanning 314 miles. Town anchoring cannot
chain — at full activation it yields ~240 areas, every one named after a place
that actually shows up in search results.

Members are deduplicated across an area, so someone in three of its groups gets one
email — and only mailed at all if at least one of those groups is their **home
group** (its catchment covers where they live; see Opt-out).

## Curated sources (seed + self-maintenance)

The research is seeded with hand-curated **local** feeds per place, stored as JSON
files under `data/community-news-sources/` (path configurable via
`COMMUNITY_NEWS_SOURCES_PATH`). When an area matches a place file, its live feeds
are passed to the model to **check first** (via `web_fetch`), and web search fills
the gaps. Oxford is seeded from the original zeitgeist persona (~14 local RSS
feeds + 3 podcasts — council news, local outlets, local reuse/community/environment
orgs); Edinburgh and everywhere else are web-search-only until someone curates a
file for them.

The store self-maintains (see `data/community-news-sources/README.md`):
- **On each research run**, the area's sources are health-checked (throttled to
  ~daily). A feed that fails `source_dead_after` (default 3) fetches in a row is
  marked `dead` and dropped from the seed; one good fetch revives it. That's how
  dead feeds are spotted.
- **~Quarterly** (`community-news:discover-sources`, gated by
  `source_discovery_days`, default 90), the model is asked for new local sources;
  each candidate URL is fetched to verify it's live before being appended.

Matching: an area uses a place file if the area's group short-names intersect the
file's `groups`, or the place name appears in the area name.

## Opt-out

Three things gate the email. **Per group**, the ModTools "Send newsletters to
members?" toggle (`settings.newsletter`) must be **explicitly on** — for
Community News it defaults off, stricter than the Stories Newsletter's
default-on reading of the same setting. **Per member**, it
reuses the **existing** "Newsletters & stories" preference, `users.newslettersallowed`
— the same switch Stories Newsletter honours — so there is one familiar "no more of
these" control. **Geographically**, the member's location (`settings.mylocation`,
else `users.lastlocation`) must fall inside the catchment (`groups.polyindex`) of
a group they are a member of — their **home group** — so far-flung memberships
don't attract another town's news. Members with no known location, and groups
with no catchment polygon (`polyindex` is a fallback `POINT`), are simply not
mailed. The email footer links to `/unsubscribe` and `/settings` (never the
account-deleting one-click route). ChitChat posts are public newsfeed items and are
not opt-out-gated per user.

## Commands

| Command | What it does |
|---|---|
| `community-news:research [--area=] [--min-days=] [--force] [--dry-run]` | (Re)cluster areas, then research due areas via one Anthropic web-search call and store items. |
| `community-news:post-chitchat [--area=] [--dry-run]` | Drip un-posted items to ChitChat as Freegle, for areas that are due. |
| `community-news:email [--area=] [--dry-run]` | Send the weekly digest for due areas to deduplicated, opted-in members. |
| `community-news:engagement [--area=]` | Report loves + replies on the trial ChitChat posts. |
| `community-news:discover-sources [--force]` | Maintain the curated source store: health-check feeds (spot dead ones) and, ~quarterly, discover new local sources. |
| `group:set-community-news --group=<short> {--on\|--off}` | Toggle the per-group setting. |

All the sending/posting commands take `--dry-run`.

## Configuration

`config('freegle.communitynews.*')` (env in brackets):

- `enabled` (`COMMUNITY_NEWS_ENABLED`, default false) — global kill switch for the
  **scheduled** runs; manual `artisan` invocation always works.
- `anthropic_api_key` (`ANTHROPIC_API_KEY`) — metered key for the research call.
- `oauth_token` (`COMMUNITY_NEWS_OAUTH_TOKEN`, falls back to `CLAUDE_CODE_OAUTH_TOKEN`)
  — a `claude setup-token` token. **When set it takes precedence**: the research
  runs on a Claude *subscription* by shelling out to the `claude` CLI (WebSearch
  tool) instead of the metered Messages API. A raw OAuth Bearer call to
  `/v1/messages` is *not* an Anthropic-supported auth method, so we go through the
  CLI (the batch image bundles it). `claude_bin` (`COMMUNITY_NEWS_CLAUDE_BIN`) and
  `claude_config_dir` (`COMMUNITY_NEWS_CLAUDE_CONFIG_DIR`, blank => a clean per-run
  temp dir) tune that path.
- `model` (`COMMUNITY_NEWS_MODEL`, default `claude-opus-4-8`).
- `system_user_email` (`COMMUNITY_NEWS_SYSTEM_USER_EMAIL`, default the noreply
  address) — the "Freegle" account ChitChat posts are attributed to.
- `area_cluster_miles` (default 20), `items_per_area` (6),
  `chitchat_items_per_post` (1), `chitchat_min_days` (3), `email_min_days` (7),
  `email_max_items` (6), `item_freshness_days` (10), `max_search_iterations` (8).

## Turning it on (production)

Community News is inert until **all** of these are true:

1. `COMMUNITY_NEWS_ENABLED=true` (for the scheduled runs).
2. A research credential is set for the batch/batch-prod container: either
   `ANTHROPIC_API_KEY` (metered) or `COMMUNITY_NEWS_OAUTH_TOKEN` /
   `CLAUDE_CODE_OAUTH_TOKEN` (subscription, via the bundled `claude` CLI) — see
   `.env.background.example`; both are wired into the dev batch service in
   `docker-compose.yml`.
3. At least one community has `communitynews` enabled.
4. **Email only:** `CommunityNews` is present in `FREEGLE_MAIL_ENABLED_TYPES`.
   The ChitChat trial does **not** need this — run the trial first, watch
   engagement, then flip on the email.

## Running it manually

The commands also run by hand, regardless of the schedule and the
`COMMUNITY_NEWS_ENABLED` flag — useful for a one-off research or post while
judging the trial:

```bash
# 1. Opt a few communities in.
php artisan group:set-community-news --group=EdinburghFreegle --on
php artisan group:set-community-news --group=OxfordFreegle --on

# 2. Cluster into areas and research each (prints "#<areaid> <name>: N item(s)").
#    Needs a research credential (CLAUDE_CODE_OAUTH_TOKEN / COMMUNITY_NEWS_OAUTH_TOKEN
#    or ANTHROPIC_API_KEY). Add --dry-run to preview without storing.
php artisan community-news:research

# 3. Post to ChitChat as Freegle. --force ignores the per-area cadence and
#    --count=N posts several at once; --dry-run composes without writing.
php artisan community-news:post-chitchat --area=<id> --force --count=3

# 4. Watch engagement (loves + replies) on the trial posts.
php artisan community-news:engagement
```

`--area`, `--force`, `--count` and `--dry-run` make one-off manual runs
predictable.

## Scheduling: both channels live

`routes/console.php` schedules the whole pipeline — `community-news:research`
(hourly at xx:30), `:post-chitchat` (hourly at xx:45, 08:00–21:00 only),
`:email` (**Fridays 11:00**) and `:discover-sources` (quarterly) — each gated
on `communitynews.enabled` (`COMMUNITY_NEWS_ENABLED`). The commands self-gate
per area, so hourly runs are near-free no-ops until an area falls due — the
point of the hourly cadence is that **a group which enables the feature starts
immediately**: its new area is researched within the hour and gets its first
ChitChat post at the next drip slot, instead of waiting for a next-day run.
The research credential on live is the Claude subscription token (via the
bundled `claude` CLI).

The email additionally requires `CommunityNews` in `FREEGLE_MAIL_ENABLED_TYPES`
(feature-flag gated on top of the kill switch). Live sets
`COMMUNITY_NEWS_EMAIL_MIN_DAYS=6` (not the default 7) so a Friday send that ran
late can never make the next Friday's 11:00 cron skip the week.

Went live 2026-07-31 for Edinburgh, Oxford and Ribble Valley.

## Measuring the trial

`community-news:engagement` reports both channels:

- **ChitChat**: **loves** (`newsfeed_likes`) and **replies** (child `newsfeed`
  rows) per trial post. The newsfeed has **no per-post view counter** (unlike
  classified messages), so views aren't available without new instrumentation.
- **Email**: the mailable uses the standard `TrackableEmail` machinery
  (`email_type=CommunityNews`, area in metadata) — an **open pixel** and
  **click redirects** on every link, with items coded `item_1…item_N` so the
  per-link table shows which stories pull. Opens/clicks apply to sends after
  2026-07-31 (the first launch send predated the wiring).

## Schema

- `community_news_areas` — one row per area (`anchorgroupid` unique, `name`,
  `intro`, `lat`/`lng`, `groupids` JSON, cadence timers `lastresearched` /
  `lastposted` / `lastemailed`).
- `community_news_items` — researched nuggets (`areaid`, `title`, `snippet`, `url`,
  `source`, `researched_at`, `newsfeedid`/`posted_at` for the ChitChat post,
  `emailed_at` for the digest).

Laravel migration `2026_07_05_000001_create_community_news_tables.php` is the source
of truth; the paired `*_migration.sql` is the idempotent production DDL.
