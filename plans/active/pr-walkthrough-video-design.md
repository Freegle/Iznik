# PR Walkthrough Video Generator

**Status**: Design → Implementation
**Created**: 2026-06-04
**Branch**: `feature/pr-walkthrough-video`
**Example PR**: [Freegle/Iznik #618](https://github.com/Freegle/Iznik/pull/618) — bulk-offer clearance

## The problem

We increasingly ship significant features built with AI (e.g. PR 618, 639). Reviewing
them from a raw diff is slow, and a Playwright test video is the wrong tool — it
"whazzes through" the screens as fast as it can, with no annotation, to satisfy an
assertion. A reviewer wants the opposite: a **calm, narrated walkthrough at human
viewing speed** that points at the changed UI and explains *what* changed and *why*.

**Scope rule (decided):** the walkthrough shows **externally-visible function only** —
what a person using the product sees and does. No code, data models, APIs, migrations,
test counts or diff stats. A `code` scene type exists in the toolkit but is off by
default and unused for product walkthroughs.

**PII (decided):** real PRs carry screenshots of real people's data, so masking is a
first-class pipeline step — see "PII masking" below.

## What we build

A self-contained tool, `pr-walkthrough/`, that turns a PR into an annotated MP4:

```
gh PR ──▶ fetch ──▶ analyze ──▶ storyboard.json ──▶ render (Remotion) ──▶ walkthrough.mp4
```

Three decoupled stages, each independently runnable and testable:

1. **fetch** (`src/fetch.mjs`) — pull PR metadata (`gh pr view --json`), the full diff
   (`gh pr diff`), and download every image referenced in the PR body into
   `examples/pr-<n>/assets/`. Pure I/O; no judgement.

2. **analyze** (`src/analyze.mjs`) — turn the raw material into a **storyboard** (the
   "script" handed to Remotion): an ordered list of scenes, each with a type, a
   duration, caption text, and — for UI scenes — timed callouts pointing at regions of
   a screenshot. This is the step that decides *what to highlight*. It is the AI step.
   - The storyboard is a plain JSON document validated against a small schema
     (`src/storyboard-schema.mjs`). The schema is the contract between analyze and
     render, so the two halves can evolve independently.
   - Runners are pluggable: `--analyzer manual` consumes a hand-authored
     `storyboard.json` (used for the 618 example and as a golden reference); `--analyzer
     claude` shells out to `claude -p` with the diff + asset list and the documented
     prompt in `prompts/analyze.md`. The Claude runner is **never** invoked
     automatically — it is opt-in, because it spends tokens.

3. **render** (`src/render.mjs` + the Remotion project) — copy the example's assets into
   `public/`, then `remotion render` the `Walkthrough` composition with the storyboard
   as input props. Output: `examples/pr-<n>/out/pr-<n>-walkthrough.mp4`.

`pr-walkthrough.mjs <pr> [--repo o/r] [--analyzer manual|claude]` chains the three.

## The storyboard (script) schema

Resolution-independent so a human (or Claude) can author it by eye. Callout boxes and
pan targets are fractions (0..1) of the image's natural size.

```jsonc
{
  "meta": { "pr", "repo", "title", "author", "url",
            "additions", "deletions", "files",
            "width": 1920, "height": 1080, "fps": 30 },
  "scenes": [
    { "type": "title",      "seconds": 5,  "chapter", "title", "subtitle", "stats" },
    { "type": "narration",  "seconds": 8,  "chapter", "heading", "caption", "bullets":[] },
    { "type": "screenshot", "seconds": 13, "chapter", "src",
      "focus": { "x","y","w","h" },          // crop/zoom target (fractions), optional
      "pan":   "down" | "none",              // slow Ken-Burns reveal of a tall image
      "caption",
      "callouts": [ { "at": 2, "until": 7,
                      "box": { "x","y","w","h" },   // fractions of the image
                      "label", "arrow": "left|right|up|down" } ] },
    { "type": "code",       "seconds": 9,  "chapter", "file", "language",
      "code", "highlight": [lineNos], "caption" },
    { "type": "outro",      "seconds": 7,  "title", "bullets":[], "url" }
  ]
}
```

## Rendering — "human viewing speed"

- 1920×1080 @ 30fps. Scene durations 5–14s; total ≈ 90–130s.
- Captions are subtitle-style lower-thirds that dwell ≥3s.
- Callouts reveal **sequentially** (~1.5s ease-in each) and stay long enough to read —
  the viewer sees the screen first, *then* the highlight lands on the relevant control.
- Tall full-page screenshots get a slow vertical Ken-Burns pan so every item scrolls
  past at a readable pace, with callouts timed to their region.
- Cross-fades between scenes; a persistent thin progress bar + chapter label + Freegle
  green frame give the video a branded, oriented feel.
- On-screen captions (not TTS) carry the narration: no local TTS engine is installed,
  and robotic audio would cheapen it. Captions read naturally and keep the file small.

## PII masking

`examples/pr-<n>/masks.json` lists regions (fractions of an image) to pixelate / blur /
box; `src/imageutil.py` **bakes** them into `*.masked.png` copies. The storyboard only
ever references the masked copies, and `render.mjs` stages only those into `public/` — so
the sensitive pixels never reach the rendered frames (not merely covered by a CSS box).
Regions are measured by overlaying a labelled grid (`imageutil.py grid`). For 618 the
poster's avatar and name are pixelated; the town/postcode-district Freegle shows by
design is left as-is.

## Storage & embedding

Rendered MP4s do **not** go in the repo. Two facts shape the recommendation:

- This content is mostly static screens + text, so H.264 compresses it to a **few MB**,
  not "enormous" — small enough to attach directly to a PR.
- GitHub renders an inline `<video>` player only for files uploaded as **PR/issue
  attachments** (`github.com/user-attachments/assets/…`). That upload endpoint is not
  exposed by the `gh` CLI/API, so the reliable inline-embed path is a one-time
  drag-drop of the MP4 into the PR description.

So:
- **Recommended**: drag-drop `walkthrough.mp4` into the PR description → GitHub hosts and
  renders it inline. `src/publish.mjs` prints the exact markdown to paste.
- **Automatable fallback**: `publish.mjs --release` uploads the MP4 as a GitHub Release
  asset (`gh release`) and prints a stable URL.
- **Git LFS**: `.gitattributes` tracks `pr-walkthrough/**/out/*.mp4` so that *if* a video
  is ever committed it goes to LFS, never the pack. By default `out/` is git-ignored.

## Why these choices

- **Storyboard as a validated JSON contract** — decouples "decide what to show" (AI,
  taste, changes per PR) from "render it" (deterministic, reusable). I can read the
  storyboard against the PR and judge whether it does the brief *before* spending a
  render.
- **Remotion** — React components rendered deterministically to frames; lets us animate
  callouts/captions/pans precisely and re-render on a prop change, which a screen
  recorder cannot.
- **Claude runner opt-in, manual default** — the 618 storyboard is authored by reading
  the diff and screenshots directly (the analysis the brief asks for), kept as the
  golden example; automated per-PR generation is available but never spends tokens
  without being asked.

## Testing

- `storyboard-schema` unit-validates the example storyboard (shape, fractions in 0..1,
  callout `until` > `at`, durations > 0, referenced `src` files exist).
- A smoke render of a 1-scene composition verifies Remotion works headless in WSL.
- The 618 example is rendered end-to-end and self-reviewed frame-by-frame against the PR.
