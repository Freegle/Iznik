# Community News — curated source store

One JSON file per place (e.g. `oxford.json`). These are hand-curated, known-good
**local** sources (council news, local news outlets, local reuse/community/
environment orgs) that seed the Community News research for an area — so the model
checks them first, then supplements with web search. Ported from the original
zeitgeist "Oxford" persona; add more places over time.

Path is configurable via `COMMUNITY_NEWS_SOURCES_PATH`
(`config('freegle.communitynews.sources_path')`), default this folder. For
production durability across redeploys, point it at a persistent volume — the
files are written back to (health status, newly-discovered sources).

## File format

```json
{
  "place": "Oxford",
  "groups": ["OxfordFreegle"],        // Freegle group short-names this covers
  "last_discovered": "2026-07-05",     // last quarterly new-source discovery
  "sources": [
    {
      "name": "Oxford City Council News",
      "url": "https://www.oxford.gov.uk/rss/news",
      "type": "rss",                   // rss | podcast_rss | site
      "added": "2026-07-05",
      "last_checked": "2026-07-05",    // null until first health-check
      "last_ok": "2026-07-05",         // last time it fetched OK
      "status": "ok",                  // unchecked | ok | failing | dead
      "consecutive_failures": 0
    }
  ]
}
```

## How it's maintained

- **On every research run** (`community-news:research`) the area's sources are
  **health-checked** (throttled to ~once a day): a source that fails to fetch has
  `consecutive_failures` bumped and, after `source_dead_after` (default 3) in a
  row, is marked `dead` and dropped from the research seed. One good fetch revives
  it. This is how dead feeds get spotted.
- **Every ~3 months** (`community-news:discover-sources`, or `--force`) the model
  is asked to find NEW local sources for the place; each candidate URL is verified
  by fetching it before being appended, and `last_discovered` is stamped.

An area is matched to a place file if the area's group short-names intersect the
file's `groups`, or (fallback) the place name appears in the area name.
