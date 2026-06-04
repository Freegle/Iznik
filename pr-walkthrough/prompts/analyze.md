# Storyboard the PR walkthrough

You are scripting a short **product walkthrough video** of a GitHub pull request, for a
reviewer who wants to *see what changed* without reading the diff.

## The single most important rule

**Show only externally-visible function — what a person using the product sees and does.**
NEVER include code, file names, data models, APIs, migrations, test counts or diff stats.
If a change has no user-visible effect, it does not belong in the video. Tell the story of
the *feature*, not the implementation.

## What you get

- PR metadata (title, body, author, url) — `{{META}}`
- The screenshots available to show, with their natural pixel sizes — `{{ASSETS}}`
- The PR diff, for context only (to understand the feature — NOT to put on screen) — below.

## What you produce

A single JSON object matching the storyboard schema (no prose, no markdown fences):

```
{ "meta": { pr, repo, title, author, url, width:1920, height:1080, fps:30 },
  "scenes": [ ... ] }
```

Scene types (use `screenshot` scenes for the real substance — that is where the product
is shown):

- `title`    — { seconds, title, subtitle, showStats:false }
- `narration`— { seconds, chapter, heading, bullets[], caption? }   (framing/why; text only)
- `screenshot`—{ seconds, chapter, src, focus?{x,y,w,h}, pan?, caption, callouts[] }
- `outro`    — { seconds, title, bullets[], url }

### Screenshot scenes — the craft

- `src` must reference a **masked** asset (`*.masked.png`) so no PII is shown.
- `focus` is the region to zoom to, as FRACTIONS (0..1) of the image's natural size. Pick a
  band that frames one idea. Omit `focus` + set `pan:"down"` for one establishing scroll of a
  tall screen.
- `callouts` point at the controls you are describing: `{ at, until, box{x,y,w,h}, label, arrow }`
  where `box` is fractions of the **whole image** and `arrow` (up|down|left|right) is the side
  the label sits on. Reveal them **sequentially** — give each ~4s and stagger `at` so the
  viewer reads one before the next appears. Keep labels to ~3–5 words.
- `caption` is the lower-third narration for the scene: one plain sentence.

### Pacing — human viewing speed

- Scenes 5–14s. Aim for a 90–130s total. Captions must dwell ≥3s.
- Lead with a title and a "why", walk each user-facing flow with focused screenshots and
  callouts, then an outro of what it unlocks. Calm and readable beats fast and clever.
- Plain English. Say "tick the items you want", not "the per-item interest selector".

Return ONLY the JSON object.
