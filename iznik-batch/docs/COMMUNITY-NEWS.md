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

1. **ChitChat trial** — drip individual items onto the newsfeed (a "ChitChat"
   post) as the **Freegle** account, placed at the area centre so members nearby
   see them, every few days. This is the cheap engagement signal we watch before
   committing to email.
2. **Weekly email** — a Freegle-branded MJML digest bundling the recent items for
   an area, sent to deduplicated, opted-in members.

(The design is a "rip-off" of the zeitgeist-tape digest: gather locality content →
one model call writes the friendly briefing → deliver. Everything else is
deterministic PHP.)

## How it fits together

```
communitynews-enabled groups
        │  cluster by centre distance (union-find, ~30-min reach approximation)
        ▼
   community_news_areas ──研究──► Anthropic (web_search) ──► community_news_items
        │                                                          │
        │ post-chitchat (every few days)                           │ email (weekly)
        ▼                                                          ▼
   newsfeed 'Message' as Freegle           Freegle-branded MJML digest to members
   (engagement: loves + replies)           (opt-out = newslettersallowed)
```

## Per-community opt-in

Community News is **off by default** — a community opts in via the `communitynews`
key in the group's `settings` JSON.

- Toggle from the command line:
  `php artisan group:set-community-news --group=EdinburghFreegle --on` (`--off` to disable).
- Or in ModTools: **Group settings → Community News** toggle.

## Areas

Small towns (Edinburgh, Oxford) are a sensible research/delivery unit on their own;
dense boroughs are too granular, so neighbouring enabled groups are **clustered**:
a greedy union-find connects any two enabled groups whose centres
(`groups.lat/lng`) are within `area_cluster_miles` (default 20), and each connected
component becomes an area keyed by its lowest groupid (`anchorgroupid`). This
approximates the ~30-minute Rippling-Out drive-time reach without a routing call; a
drive-time refinement can later replace `CommunityNewsAreaService::clusterByDistance()`.

Members are deduplicated across an area, so someone in three of its groups gets one
email.

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

Reuses the **existing** "Newsletters & stories" preference, `users.newslettersallowed`
— the same switch Stories Newsletter honours — so there is one familiar "no more of
these" control. The email footer links to `/unsubscribe` and `/settings` (never the
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

## Scheduling: ChitChat trial on, weekly email off

`routes/console.php` schedules the ChitChat pipeline — `community-news:research`
(daily 06:30), `:post-chitchat` (daily 09:15) and `:discover-sources`
(quarterly) — each gated on `communitynews.enabled`, so nothing fires until ops
sets `COMMUNITY_NEWS_ENABLED=true`. The commands self-gate per area, so a daily
cadence just tops up / drips as each area falls due. On live the research
credential is the existing `CLAUDE_CODE_OAUTH_TOKEN` in `.env.background`
(subscription via the bundled `claude` CLI).

`community-news:email` is deliberately **not scheduled** — the ChitChat drip
runs first and we judge engagement before any email goes out. When the trial
proves out: uncomment its schedule block and add `CommunityNews` to
`FREEGLE_MAIL_ENABLED_TYPES` (the email path is feature-flag gated on top of
the kill switch).

## Measuring the trial

`community-news:engagement` reports **loves** (`newsfeed_likes`) and **replies**
(child `newsfeed` rows) per trial post. Note: the newsfeed has **no per-post view
counter** (unlike classified messages), so views aren't available without new
instrumentation — judge the trial on loves + replies (and, if needed, Loki
`page_view` events for `/chitchat/<id>` in production).

## Schema

- `community_news_areas` — one row per area (`anchorgroupid` unique, `name`,
  `intro`, `lat`/`lng`, `groupids` JSON, cadence timers `lastresearched` /
  `lastposted` / `lastemailed`).
- `community_news_items` — researched nuggets (`areaid`, `title`, `snippet`, `url`,
  `source`, `researched_at`, `newsfeedid`/`posted_at` for the ChitChat post,
  `emailed_at` for the digest).

Laravel migration `2026_07_05_000001_create_community_news_tables.php` is the source
of truth; the paired `*_migration.sql` is the idempotent production DDL.
