---
paths:
  - "iznik-nuxt3/pages/browse/**"
  - "iznik-nuxt3/components/PostMap*.vue"
  - "iznik-server-go/isochrone/**"
  - "iznik-server-go/message/**"
---

# Traps in Browse and search

## There are three feed endpoints, not one

The Browse page picks between three depending on the member's view. **Any per-post field you
add - a flag, a score, a distance - must be added to all three**, or it silently does nothing
for most members while working perfectly in whichever one you tested.

The unread badge is a fourth reader of the same universe, and a badge that disagrees with the
feed usually means one of them was updated and the others were not.

## The feed re-runs itself and loses what it had

The member-facing Browse page re-runs the same search roughly every minute, tearing down and
re-rendering rather than filtering. A member watching results go from nine items to one, with
the page jumping back to the top, is seeing that, not results being removed.

Separately, a deploy during a session kills the lazily-loaded chunk the page needs. The error
handler reloads to the path, which drops the search term, so the member lands on an unfiltered
Browse and reports "an error, then it reset". Both arrive as "search is broken" and neither is
the search.

## "New to you" floats old posts once you have seen everything

In that sort, when nothing is unseen the whole feed becomes the seen block ordered by relevance
score, and with default weights that systematically puts old posts first. The member sees stale
items at the top of a feed labelled by novelty.

## Junk results are a threshold and an ordering, together

Two independent causes, and fixing one alone leaves the complaint standing:

1. The minimum score sits inside the noise band, so weak matches qualify at all.
2. Search **ranks before** it applies the distance cap, so a strong but distant match takes a
   slot that a nearer, weaker one would have had.

Order matters here. Judge any change to search quality on both.

## Stacking: what is above what

- **Bootstrap modals set their z-index inline**, so a class intended to lift them above the ad
  banner is defeated and they sit far lower than intended. A full-screen overlay above that
  value covers the modal and swallows its clicks. Gate the overlay's visibility on the state
  that raised the modal rather than fighting the numbers.
- **The photo viewer is opened from inside modals** and must outrank them. A rule that lifts
  modals above it buries the viewer behind the thing that opened it.

## A list that renders blank on a cold load

Community names come from a store filled by a fetch that is deliberately not awaited, and the
list does not re-evaluate when it lands. On a full page load the member sees entries with no
names and nothing to choose. Anything reading that store on first paint needs to handle the
empty case and react when it fills.

## See also

- `.claude/rules/rippling.md` - what "reached you" means for the Nearby feed.
- `.claude/rules/frontend-traps.md` - hanging awaits and build flags.
