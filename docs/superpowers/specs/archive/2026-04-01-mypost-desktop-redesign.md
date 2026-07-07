# MyPost Desktop Layout Redesign

**Date**: 2026-04-01
**Status**: Approved

## Problem

On desktop (lg+), MyMessage photo uses `padding-bottom: 50%` of container width with no max-height, making photos enormous on wide screens (500px+ tall). The browse page constrains photos at lg+ to a fixed 200x200px square in a side-by-side layout.

## Design

At lg (992px+) breakpoint, MyMessage switches from stacked to side-by-side layout matching the browse page's `MessageSummary` pattern:

### Layout (lg+)
- **Photo**: 200px fixed width, fills card height, `object-fit: cover`. Same as browse card.
- **Details panel** (right side):
  - Top-right: Edit button (pen icon)
  - OFFER/WANTED tag inline with title (not overlaid on photo)
  - Location · Group · Time ago · #ID — single compact line
  - Promised banner (when applicable) — inline bar with Unpromise button
  - Action buttons along the bottom: TAKEN/RECEIVED, Promise, Withdraw, Repost
- **Replies section**: Full width below the photo+details row, unchanged behaviour

### Layout (below lg)
- Completely unchanged — same stacked layout as today

### Removed on desktop
- Title overlay on photo gradient (title moves to details panel)
- Distance (you know where your own post is)
- Share button on photo (can go in actions or be removed)

## Files to modify
- `components/MyMessage.vue` — template restructure at lg+, CSS media queries

## Out of scope
- Reply section redesign
- Mobile layout changes
- MyMessageReply component changes
