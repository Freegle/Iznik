# Charity Partner Badge & Landing Page — Design Spec

**Date**: 2026-03-31
**Status**: Demo/visual build — not wired to backend

## Summary

Add a Charity Partner landing page with inline signup form, and a reusable badge component, to iznik-nuxt3. This is a frontend-only, demo-quality build intended to show the page and feel to the partnerships team. No backend changes, no actual account creation.

## Design Decisions

- **Badge icon**: Blue heart+checkmark (SVG) — follows platform conventions (JustGiving, GoFundMe, Facebook)
- **Badge colour**: Blue (#2563eb) — trust/verified feel, distinct from gold Supporter badge
- **Post styling**: Blue heart+checkmark badge next to poster name + 2px solid blue border on message card
- **Landing page**: Single page at `/charity` with info sections and inline signup form
- **Signup form**: Visual only — submit shows "coming soon" state
- **Normal registration**: Completely untouched

## Pages

### `/pages/charity/index.vue`

Single page with these sections:

1. **Hero**: "Charity Partner" heading with badge preview, intro paragraph about benefits for charitable orgs
2. **Benefits**: 3-4 bullet points (visible badge, distinctive posts, org profile, local community reach)
3. **Post preview mockup**: Side-by-side showing a normal post and a charity partner post with badge + blue border
4. **Inline signup form** (non-functional):
   - Organisation name (text input)
   - Charity type: radio — "Registered charity" (shows charity number field) / "Other community organisation" (shows freeform text field)
   - Website URL (text input)
   - Social media handles (text input)
   - Logo upload area (visual only)
   - Organisation description (textarea)
   - Submit button — disabled/coming-soon state
5. **Footer link**: "Not a charity? Sign up as an individual" → `/explore`

## Components

### `CharityBadge.vue`

Small inline badge component, follows `SupporterInfo.vue` pattern:
- Blue heart+checkmark SVG icon + "Charity Partner" text
- Click opens `CharityInfoModal.vue`
- Props: `size` (default 'md')

### `CharityInfoModal.vue`

Modal explaining Charity Partner status, follows `SupporterInfoModal.vue` pattern:
- Header with heart+checkmark icon + "Charity Partner" title
- Body: explanation of what Charity Partner means, benefits
- Close button

## Verification & Charity Number

Just collect the data — no auto-lookup. Charity number is a text field. Auto-lookup via Charity Commission API (E&W only, no CORS, needs server proxy) deferred to future work.

## Out of Scope

- Backend user model changes (no `charity` flag yet)
- Actual account creation from the signup form
- Badge display on real posts (no backend flag to key off)
- Post border styling on real message cards (deferred until backend exists)
- Charity Commission API integration
- Scotland/NI charity register lookup
