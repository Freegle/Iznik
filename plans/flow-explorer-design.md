# Flow Explorer: Visual UX Pain Point Diagnostics

## Concept

A web-based tool that visualises user journeys through Freegle as a flow diagram. Each node represents a component on a page. Edges represent transitions (user moved from one component/page to the next). Nodes are coloured by conversion rate — green for healthy, amber for concerning, red for broken. Clicking a node drills into its metrics.

The tool answers: "Where are users struggling, and in what context?"

## Data Model

The tool reads from Loki logs (via the existing systemlogs API) or pre-computed JSON from the audit scripts. Three data sources combine:

```
Impressions:  { component, page, count }         — "was it visible?"
Interactions: { component, page, action, count }  — "did they click?"
Page Views:   { page, next_page, count }          — "where did they go?"
```

These produce a graph:

```
Nodes = unique (page, component) pairs
Edges = transitions between pages, weighted by frequency
Node metrics = impressions, clicks, dead_clicks, conversion_to_next_step
```

## Visual Design

### Main View: Journey Flow

```
┌─────────────────────────────────────────────────────────────┐
│  Flow Explorer          [Give Flow ▾]  [7 days ▾]  [All ▾] │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌──────────┐    88%    ┌──────────────┐    79%             │
│  │  /give   │──────────▶│ /give/mobile │──────────▶ ...     │
│  │          │           │  /whereami   │                    │
│  │ 4,125    │           │  2,614       │                    │
│  │ sessions │           │  sessions    │                    │
│  └──────────┘           └──────────────┘                    │
│       │                       │                             │
│       │ 12% abandon           │ 0% abandon                 │
│       ▼                       ▼                             │
│   (exit)                 ┌──────────────┐    79%            │
│                          │ /give/mobile │──────────▶ ...    │
│                          │  /photos     │                   │
│                          │  2,613       │                   │
│                          └──────────────┘                   │
│                               │                             │
│                               │ 21% abandon  ◀── PAIN POINT│
│                               ▼                             │
│                          ┌──────────────┐                   │
│                          │ /give/mobile │                   │
│                          │  /details    │                   │
│                          │  2,073       │                   │
│                          └──────────────┘                   │
│                               │                             │
│                               │ 9% abandon                 │
│                               ▼                             │
│                          ┌──────────────┐                   │
│                          │ /give/mobile │                   │
│                          │  /options    │                   │
│                          │  1,880       │                   │
│                          └──────────────┘                   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

Each node is a box showing:
- Page/step name
- Session count
- Conversion rate to next step (on the edge)
- Abandon rate (on the exit edge)

Colour coding:
- **Green** (>80% conversion): healthy
- **Amber** (50-80%): worth investigating
- **Red** (<50%): pain point

### Drill-Down View: Component Detail

Clicking a node (e.g. `/give/mobile/details`) expands to show the components on that page and their individual metrics:

```
┌─────────────────────────────────────────────────────────────┐
│  /give/mobile/details                      [Back to flow]   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Component          Impr   Clicks  Dead%  Rage  Status     │
│  ─────────────────────────────────────────────────────────  │
│  DescriptionField   2,073  1,640   12%    23/d  🔴 PAIN    │
│  PhotoPreview       2,073    135    4%     26/d  🟡         │
│  BackButton         2,073    312    0%      3/d  🟢         │
│  SubmitButton       2,073  1,880    0%      0/d  🟢         │
│  CategorySelect     2,073    890    0%      0/d  🟢         │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ DescriptionField detail:                            │    │
│  │                                                     │    │
│  │ Impressions: 2,073                                  │    │
│  │ Clicks: 1,640 (79%)                                 │    │
│  │ Dead clicks: 197 (12% of clicks)                    │    │
│  │ Rage clicks: 23/day                                 │    │
│  │ Rage target: "Condition, size, why you're giving    │    │
│  │              it away..." (placeholder text)          │    │
│  │                                                     │    │
│  │ 21% of users who see this component don't proceed   │    │
│  │ to the next step. This is the #1 drop-off in the    │    │
│  │ Give flow.                                          │    │
│  │                                                     │    │
│  │ Device breakdown:                                   │    │
│  │   Mobile:  27% drop-off                             │    │
│  │   App:     13% drop-off                             │    │
│  │   Desktop:  8% drop-off                             │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Overview: All Flows Dashboard

The landing page shows all defined flows as horizontal strips with colour-coded steps:

```
┌─────────────────────────────────────────────────────────────┐
│  Flow Explorer                                              │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Give (Offer)   4,125 sessions/week                        │
│  ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐                            │
│  │ 🟢│→│ 🟢│→│ 🟢│→│ 🔴│→│ 🟡│  46% complete              │
│  └───┘ └───┘ └───┘ └───┘ └───┘                            │
│  give  where  photo detail opts                             │
│                                                             │
│  Find (Wanted)  3,569 sessions/week                        │
│  ┌───┐ ┌───┐ ┌───┐ ┌───┐                                  │
│  │ 🟡│→│ 🟢│→│ 🟢│→│ 🔴│      56% complete                │
│  └───┘ └───┘ └───┘ └───┘                                  │
│  find  where  photo detail                                  │
│                                                             │
│  Reply to Offer  15,929 message views/week                 │
│  ┌───┐ ┌───┐ ┌───┐                                        │
│  │ 🟢│→│ 🔴│→│ 🟢│      7% complete                       │
│  └───┘ └───┘ └───┘                                        │
│  view  reply  send                                          │
│                                                             │
│  Donate  8,420 impressions/week                            │
│  ┌───┐ ┌───┐ ┌───┐                                        │
│  │ 🟡│→│ 🔴│→│ 🟡│      9% complete                       │
│  └───┘ └───┘ └───┘                                        │
│  shown  form  paid                                          │
│                                                             │
│  Contact Mod Team  149 messages/week                       │
│  ┌───┐ ┌───┐                                               │
│  │ 🟢│→│ 🔴│            64% get a reply                    │
│  └───┘ └───┘                                               │
│  sent  replied                                              │
│                                                             │
│  ─────────────────────────────────────────────────────────  │
│  Pain Points (auto-detected):                              │
│  🔴 Give/details: 21% drop-off, 23 rage clicks/day        │
│  🔴 Reply: 93% of message viewers don't reply              │
│  🔴 Donate: 91% abandon payment form                       │
│  🔴 Mod contact: 36% never get a reply                     │
│  🟡 Find/details: 25% drop-off                             │
│  🟡 Photo upload: 71 rage clicks/day                       │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Architecture

### Flow Definitions

Flows are defined in a YAML config file, not in component code:

```yaml
# flows.yaml
flows:
  give_offer:
    label: "Give (Offer)"
    steps:
      - page: /give
        label: Entry
      - page: /give/mobile/whereami
        label: Location
      - page: /give/mobile/photos
        label: Photos
      - page: /give/mobile/details
        label: Description
        components:        # optional: track specific components
          - DescriptionField
          - PhotoPreview
      - page: /give/mobile/options
        label: Options
    success: page /post    # or API call

  reply_to_offer:
    label: "Reply to Offer"
    steps:
      - page: /message/*
        label: View message
        components:
          - ReplyButton
      - action: "click: Reply"
        label: Click reply
      - action: "click: Send"
        label: Send message

  donate:
    label: "Donate"
    steps:
      - component: DonationAskModal
        label: Modal shown
      - component: StripeDonate
        label: Payment form
      - api: stripecreateintent
        label: Payment submitted
```

### Data Pipeline

```
                    ┌────────────┐
                    │  Loki logs  │
                    └──────┬─────┘
                           │
                    ┌──────▼─────┐
                    │ Go API     │  New endpoint: GET /api/flowexplorer
                    │ endpoint   │  Queries Loki, computes metrics
                    └──────┬─────┘  Reads flows.yaml for definitions
                           │
                    ┌──────▼─────┐
                    │ Mod Tools  │  /flowexplorer page
                    │ page       │  Vue components, renders flows
                    └────────────┘  Calls API, renders diagrams
```

**Backend:** A new Go API endpoint (`GET /api/flowexplorer`) following the same pattern as the existing `/api/systemlogs` endpoint. Uses the same Loki query infrastructure, same auth middleware (Support/Admin), same approach:
1. Reads flow definitions (either from a config file or hardcoded initially)
2. Queries Loki using the existing `systemlogs` package's Loki client
3. Matches sessions against flow steps to compute conversion per step
4. Enriches with dead click rates, rage clicks, device segmentation
5. Returns JSON

**Frontend:** A new mod tools page at `/flowexplorer` that:
- Calls the API endpoint
- Renders the dashboard, flow view, and drill-down
- Is a standard mod tools Vue page using existing layout/auth
- **Must be excluded from its own instrumentation** — the impression tracker and interaction capture should skip events where `page_url` starts with `/flowexplorer` to avoid the tool polluting its own data

**Self-exclusion:** The interaction capture plugin and impression tracker should check:
```js
if (window.location.pathname.startsWith('/flowexplorer')) return
```
This prevents the analytics page from generating analytics noise. Similarly, page views of `/flowexplorer` should be filtered out of the flow analysis.

### Technology

- **Frontend:** Standard mod tools Vue page. Uses existing mod tools layout, auth, and API patterns. Flow diagrams rendered with D3.js dagre for the tree view, CSS grid for the strip overview. Both are lightweight client-side rendering.
- **Backend:** Go endpoint in `iznik-server-go`, queries Loki via the existing `systemlogs` package. Flow definitions loaded from a YAML file bundled with the Go binary or read from a config path.
- **Drill-down:** clicking a node calls the API with a step filter, returns component-level metrics for that page. All server-computed from Loki data.
- **Auto-detection:** the API flags nodes where conversion drops below the flow average by >1 standard deviation, or where dead click / rage click rates exceed thresholds. These become the "Pain Points" list.
- **Comparison:** the API accepts `start`/`end` time range params, so the UI can show trends — "this step's conversion dropped from 85% to 72% this week."
- **Caching:** Flow data changes slowly. The API caches computed results for 1 hour to avoid hammering Loki on repeated page loads.

## Graceful Degradation

The explorer should work with whatever data is available and improve as instrumentation is added. Three tiers:

### Tier 1: Existing data (no code changes)

Available now. Page views + session IDs + interaction events with action labels.

- **Flow strip overview:** fully functional. Step conversion rates computed from page view sequences per session.
- **Flow tree view:** fully functional. Session counts, drop-off rates, time between steps, device segmentation.
- **Component drill-down:** shows elements by CSS selector (tag.class#id) instead of Vue component names. Noisier but usable. Groups `button.back-button`, `div.content-description`, etc.
- **Dead clicks:** shown as "unlabelled clicks" rather than definitively "dead." Displayed as a data quality indicator.
- **Impressions:** unavailable. Approximated at page level (page view = all components impressed once). UI shows "impression data not available — page-level approximation used."

### Tier 2: After interaction capture fixes (issue #198 items 1-2)

Accurate click labels, component names on every interaction.

- **Component drill-down:** shows real Vue component names. Aggregation becomes clean.
- **Dead clicks:** accurately classified. Icon-only buttons no longer false-positive.
- **Impressions:** still unavailable at component level.

### Tier 3: After impression tracker (issue #198 item 3)

Full data: impressions + interactions + conversions per component per page.

- **Conversion rates per component:** impressions / clicks / goal achieved.
- **Below-the-fold detection:** component has low impressions relative to page views.
- **Auto-detection:** statistical anomaly flagging with full confidence.

### Data quality indicator

The API response includes a `data_quality` field:

```json
{
  "data_quality": {
    "has_component_names": false,
    "has_impressions": false,
    "unlabelled_click_pct": 27,
    "sample_period_days": 7
  }
}
```

The UI uses this to:
- Show/hide component-level views
- Display confidence indicators on metrics
- Show a banner explaining what data is available and what's pending

## Implementation Order

1. **Flow definitions YAML** — define the 5-6 key flows based on what we learned from the audit
2. **Go API endpoint** — `/api/flowexplorer` with Loki query logic, flow YAML parsing, and metric computation
3. **Mod tools page + strip overview** — `/flowexplorer` page with the dashboard showing all flows as colour-coded strips
4. **Detailed flow view** — the expanded tree diagram for individual flows (D3 dagre)
5. **Component drill-down** — per-component metrics within a step (requires instrumentation from issue #198)
6. **Auto-detection** — statistical flagging of pain points and trend changes
7. **Self-exclusion** — ensure `/flowexplorer` page views and interactions are filtered from the data

Steps 1-4 work with existing data (page views + interactions). Step 5 needs the impression tracking from issue #198. Step 6 needs a few weeks of historical data to establish baselines.

## Access Control

The flow explorer shows aggregate analytics, not individual user data. Restricted to Support and Admin roles (same as system logs). Lives within the existing Support Tools section of mod tools — accessed via `/support` navigation, not a top-level sidebar item. This keeps it alongside the other diagnostic tools (system logs, user lookup) that support/admin users already use.
