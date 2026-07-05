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
| `group:set-community-news --group=<short> {--on\|--off}` | Toggle the per-group setting. |

All the sending/posting commands take `--dry-run`.

## Configuration

`config('freegle.communitynews.*')` (env in brackets):

- `enabled` (`COMMUNITY_NEWS_ENABLED`, default false) — global kill switch for the
  **scheduled** runs; manual `artisan` invocation always works.
- `anthropic_api_key` (`ANTHROPIC_API_KEY`) — for the research call.
- `model` (`COMMUNITY_NEWS_MODEL`, default `claude-opus-4-8`).
- `system_user_email` (`COMMUNITY_NEWS_SYSTEM_USER_EMAIL`, default the noreply
  address) — the "Freegle" account ChitChat posts are attributed to.
- `area_cluster_miles` (default 20), `items_per_area` (6),
  `chitchat_items_per_post` (1), `chitchat_min_days` (3), `email_min_days` (7),
  `email_max_items` (6), `item_freshness_days` (10), `max_search_iterations` (8).

## Turning it on (production)

Community News is inert until **all** of these are true:

1. `COMMUNITY_NEWS_ENABLED=true` (for the scheduled runs).
2. `ANTHROPIC_API_KEY` is set for the batch/batch-prod container (see
   `.env.background.example`; also wired into the dev batch service in
   `docker-compose.yml`).
3. At least one community has `communitynews` enabled.
4. **Email only:** `CommunityNews` is present in `FREEGLE_MAIL_ENABLED_TYPES`.
   The ChitChat trial does **not** need this — run the trial first, watch
   engagement, then flip on the email.

## Schedule

Registered in `routes/console.php`, each gated on `communitynews.enabled`:
`community-news:research` daily 06:30, `community-news:post-chitchat` daily 09:15,
`community-news:email` weekly (Wed 10:00). The commands self-gate per area, so a
daily cadence simply tops up / drips as each area falls due.

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
