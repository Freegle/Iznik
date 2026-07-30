# Community Clearance — UX polish pass (live review 2026-07-17)

Feedback captured from a live walkthrough of the WAGGGS clearance. Work through in
coherent commits; rebuild through the test gate; verify in WSL Chrome (GPU off).

## Structural
- [ ] **Stage rename/redesign**: `research · locked · ready · live · closed` →
      **`research · prepare · live · allocating · collecting · closed`**.
      Merge locked+ready→prepare; split live→live/allocating/collecting.
      Update lifecycle.ts, stepper, canTransition, routes, tests, normaliseStage (legacy active/locked/ready).
- [ ] **Collections page = table**, nearest **deadline** at top, summary info per row.
- [ ] **Deadline prominent** on the collection card / dashboard header.
- [ ] **Proposals == Review** → drop the duplicate Proposals tab (fold into Review).

## Review / Overview / Assistant
- [ ] Review must NOT flag an already-handled reply (exclude allocated/collected/closed recipients).
- [ ] Live-stage header copy: not "outreach is under way" when we're collecting — stage-aware.
- [ ] Overview leads with the review/attention items at the top.
- [ ] Assistant = a simple chat box.

## Recipients / Inbox
- [ ] Recipients sorted responded-first; cleaner table layout.
- [ ] Call them **Takers**. There will always be hundreds — no big long list; group them
      (by status, collapsed groups with counts, responded expanded first).
- [ ] Special section for **Freegle** as its own channel (the bulk offer + repliers there),
      distinct from emailed orgs.
- [ ] Explain Tier 1/2, Confidence etc — legend/tooltips (CcHelpTip).
- [ ] **Waitlist is per-ITEM, not per-org**: "Add to waiting list" at org level makes no
      sense; waitlisted currently does nothing and there's no delist. Rework: waitlist
      entries = (taker, item), visible on both the item and the taker, removable.
- [ ] Inbox message bodies show line breaks (pre-wrap + re-export data without whitespace collapse).
- [ ] From the inbox, show each recipient's state clearly.
- [ ] Recipient-state **flowchart in a modal** (explains the states).

## Activity / tabs / nav / admin / settings
- [ ] Activity: missing space after email.
- [ ] Activity info-icon shows a cryptic id — gate/humanise it.
- [ ] Non-link text styled to look clickable — fix.
- [ ] Tab styling poor + layout shift on switch — restyle cc-tabnav, stabilise height.
- [ ] Navbar title alignment ("Community Clearance from Freegle").
- [ ] Admin: duplicate edward accounts.
- [ ] Google config unclear / something missing — clarify Settings + help.

## Phase 2 (after the overhaul workflow lands)
- [ ] **Readability**: grey-on-green is hard to read — pure WHITE page background, LARGER default
      font (bump base size), darker body text (drop the palest muted greys).
- [ ] Tab rename: Activity → **Logs**.
- [ ] Tier labels: Tier 1 → "Reuse specialists", Tier 2 → "Community groups", no tier → **"Other"**.
- [ ] **Pricing** section in clearance Settings: amount upfront, on completion, per item, cost cap —
      surfaced in Monitor.
- [ ] **Monitor timeline graphs** (hand-rolled SVG, no deps): outreach sent over time, replies
      arriving (rising/tailing off), collections arranged. Much more visual.
- [ ] **Collections (pickups) tab** inside a clearance: when/what per pickup, badge counts FUTURE
      pickups, list includes past ones + confirmed-it-happened flag. Upcoming pickups also shown
      on the Overview dashboard. Rename top-level entity UI label to "Clearances" to free the word.
- [ ] Token saved to communityclearance/.env as ANTHROPIC_API_KEY (format looks like a setup-token
      browser code `code#state`, not sk-ant-oat… — verify AI works after rebuild; may need re-exchange).
- [ ] **Freegle impact API**: build in iznik-server-go (weights/CO2/£ per item name from the real
      Freegle model), then point communityclearance's impact at it (local table stays as fallback).

## Impact
- [ ] Impact shows CO2 (kg) and £ figures **per item**, not just weight.
- [ ] Cabinet weight 0 is a bug — use Freegle's averaging/default-weight approach so nothing is 0.

## Post-commit polish (2026-07-20, in progress)
- [x] AI token: `sk-ant-oat` must be Bearer + `apiKey:null` (env leaked as x-api-key) + oauth beta header. Fixed ai.ts. Live completion still blocked by shared-token 429 — verify when rate limit clears.
- [x] Qty 0 wrong → manifest qty floored to ≥1 (Bisley cabinet, Table).
- [x] Disposed-externally state: new `disposed` item state (gone but NOT rehomed); excluded from impact diverted/CO2/£; shown as its own Items tab + Impact subtotal + Monitor line. Bisley/Table mapped to disposed.
- [x] Harriet/Fuse pickup date was stale (13 Jul) → real rescheduled date Mon 20 Jul 2pm (her message). "Upcoming" now spans whole of today (start-of-today boundary) across Overview widget, Collections badge, PickupsPanel.
- [x] "Needs attention" calm/compact when empty (no orange !, one line).
- [x] Assistant shrinks until there's history (1-line intro, no tall empty box).
- [x] Tabs: darker/prominent text, equal weight active/inactive (kills layout-shift reflow), red action-counts vs grey info-counts.
- [x] "Items cleared" bar: rounded clipped fill (no square-corner poke), split rehomed/awaiting/disposed, names awaiting-collection items.
- 562 tests pass; rebuild + reimport in flight.

## Done
- [x] Items tab defaults to a non-empty tab (WAGGGS had 0 Available).
- [x] WAGGGS dimensions not imported — importer now carries bulkitems[].dimensions/condition/
      description from the live apiv2 offer (7/10 items have dims). RE-RUN IMPORT at the
      rebuild step (import is destructive to app-made state; deliberately deferred until the
      overhaul workflow finishes its /data investigations).
</content>
