# Freegle City — Design Spec
_2026-04-02_

## Overview

A standalone isometric city visualisation of Freegle activity. Lives in a separate GitHub repo (`Freegle/freegle-city`), statically served, no build step. The city looks constantly busy and cheerful — powered by a procedural simulation engine whose rhythms and vocabulary are seeded from real Freegle DB data. No live data feed at runtime.

The city is larger than the viewport. Users zoom and pan to explore. Interesting things are always happening somewhere.

---

## Repository

- **Repo**: `Freegle/freegle-city` (new repo in Freegle GitHub org)
- **Local path**: `/home/edward/freegle-city/` (peer to FreegleDockerWSL)
- **Stack**: HTML + PixiJS v8 (CDN), vanilla JS, no build step
- **Files**: `index.html`, `city.js`, `simulator.js`, `data.json`, `sprites/` (Kenney CC0 isometric city pack)
- **Served**: any static host (GitHub Pages works out of the box)

---

## Data Strategy

A one-time SQL query against the Freegle DB (read-only, via tunnel) produces `data.json`, committed to the repo. No runtime DB access.

`data.json` contains:
- **Item vocabulary** (~200 real item names: "armchair", "kids' bike", "chest of drawers"…)
- **Activity ratios**: offer %, wanted %, spam rate, approval rate, success rate
- **Time-of-day busyness curve**: 24 hourly weights (morning peak, lunchtime, evening)
- **Typical throughput**: average events per hour across the week

`data.json` is refreshed manually (or by a cron that re-runs the query and commits) whenever the vocabulary needs refreshing. The visualisation itself never calls an API.

---

## Architecture

```
index.html
  └─ PixiJS Application
       ├─ WorldContainer (zoomable/pannable, larger than viewport)
       │    ├─ SurfaceStage   (isometric city, top ~55% of world)
       │    ├─ GroundBand     (earth layer, tube entry holes)
       │    └─ UndergroundStage (cross-section tube network, bottom ~45%)
       ├─ HtmlOverlayLayer   (speech bubbles — positioned DOM divs)
       └─ CitySimulator      (procedural event engine, feeds both stages)
```

### Zoom & Pan
- Mouse wheel / pinch: zoom between 0.4× (city overview) and 2.5× (street-level detail)
- Click-drag / touch-drag: pan
- At low zoom the underground is hidden; at medium+ zoom the cross-section becomes visible below the ground band
- A minimap in the corner shows the full city and current viewport rectangle

### Coordinate System
Standard isometric projection: world `(x, y, z)` → screen via:
```
screenX = (x - y) * tileW/2
screenY = (x + y) * tileH/2 - z * tileH
```
Depth sorting by `y + x` (painter's algorithm, back-to-front).

---

## Surface City

### Layout
The world is ~40×40 isometric tiles. Three named neighbourhoods separated by roads:

| Neighbourhood | Feel |
|---|---|
| **Thornwick Lane** | Residential terraces, the Free Shop, families |
| **Brackenfield Common** | High street, corner shop, park |
| **Lower Puddleby** | Flats, repair café, zero-waste shop, charity shop |

Roads run between them. People walk along roads and pavements constantly.

### Buildings (Kenney isometric sprites)
Fixed buildings (always present):
- Terraced houses (various sizes)
- Corner shop ("Brackenfield Stores")
- Flats / apartment block

**The Free Shop** — a shipping container on a corner plot. Visually: weathered steel container, door open, shelves of items visible inside. People approach, drop things off, browse, pick things up. Always a little cluster of activity around it. This is the most prominent surface landmark.

**Unlockable buildings** (appear as `greenScore` thresholds are hit):

| Threshold | Unlock |
|---|---|
| 0 | Base city, faint smog layer |
| 50 giveaways | Street trees planted; smog starts lifting |
| 150 | Repair Café opens (ribbon-cutting animation, speech bubbles: "It's fixed!") |
| 300 | Zero Waste Shop + rooftop gardens on flats |
| 500 | Charity Shop + cyclists + flower boxes + clear air |

`greenScore` is the cumulative `item_given_away` event count, persisted in `localStorage`.

### The Bin Lorry
Spawns every 90–180 seconds (randomised), trundles along the main road left-to-right. Three protester figures appear in front of it with placards:
- "BOO! 👎"
- "CAREFUL NOW!"
- "DOWN WITH THIS SORT OF THING"

After 15 seconds the lorry exits frame and the protesters disperse. If `greenScore` > 300, bin lorry visits become less frequent (every 5–8 minutes) — the city is producing less waste.

### People
At least 8 figures always on screen, walking between buildings along road/pavement paths. At higher `greenScore` thresholds, cyclists and additional pedestrians appear. Figures have simple idle/walk animations from the Kenney sprite sheet.

### Mime Gestures (no text)

No speech bubbles anywhere. Figures express entirely through physical pantomime — pure PixiJS animation, no DOM overlay. A `MimeGesture` helper plays short tween sequences on any figure.

**Gesture library:**

| Category | Gestures |
|---|---|
| Positive | jump for joy, wave hello, hold item up triumphantly, nod, point excitedly, dust hands with satisfaction |
| Negative/comic | shrug, throw hands up, stamp foot, peer suspiciously, shake head slowly |
| Neutral | hold item out to offer, inspect closely, point at gauge/board, sit back down |

**Item events** show a tiny floating item sprite above the figure — a coloured shape representing the item (armchair = wide brown rectangle, bike = two wheels, sofa = long cushion) — which rises 20px and fades over 1.5s.

| Event | Mime | Figure |
|---|---|---|
| `offer_posted` | holds item sprite up, points at it | House figure at window |
| `wanted_posted` | shrugs, holds hands out (empty) | House figure at window |
| `item_given_away` | jumps for joy, pumps fist | Free Shop or house figure |
| `chat_message` | two nearby figures turn to face each other, nod | Nearest pair |
| `new_member` | new figure waves hello; nearby figure waves back | City edge arrival |
| `free_shop_drop` | figure sets item sprite down at entrance, steps back, dusts hands | Free Shop entrance |
| `free_shop_pick` | figure picks up item sprite, hugs it, walks off | Free Shop exit |

---

## Underground

Visible when zoom ≥ 0.7×. The ground band has glowing tube-entry holes; capsules drop through them.

### Tube Network
Two horizontal trunk tubes run the full width of the underground. Vertical branch tubes connect them to departments. Capsules travel along tube paths using PixiJS tweens (ease-in-out, ~1.2s per segment).

Capsule colours:
- 🟡 Yellow — new offer/wanted (inbound)
- 🔵 Blue — approved / notification (return)
- 🔴 Red — spam (rejected, one-way to Spam Dungeon)
- 🟢 Green — successful giveaway

### Moving Figures in the Underground

Every department has figures that **physically move** — walking between the tube inlet, their desk, and the next tube outlet. Figures carry visible capsule sprites while in transit. This movement is the primary animation; speech bubbles are secondary. A figure is never just standing still unless idle-animating at their desk.

Each figure follows a simple waypoint path per event:
1. Walk to tube inlet → pick up capsule (capsule sprite attaches to figure)
2. Walk to desk/workbench → perform action (open, stamp, inspect)
3. Walk to outbound tube → load capsule in → capsule launches along tube

Between events, figures do short idle loops: stretching, looking at papers, refilling tea, tapping at screens.

### Departments

**Sorting Office**
- 2 figures. When a yellow capsule arrives, one walks to the inlet, carries it to the inspection bench, opens it (animation: lid flips up), reads it. Speech bubble: *"Looks legit. Carry on."* or *"Hmm, smells fishy…"*
- First figure carries clean capsule to the right-hand trunk tube; second figure carries suspicious capsule to the Spam Dungeon drop-hatch
- Trigger: `offer_posted`, `wanted_posted`

**The Spam Dungeon**
- 1 figure who receives red capsules, carries them to a hatch, drops them in. Lid slams. Figure dusts hands, walks back.
- Pile of rejected capsules visibly accumulates at the bottom
- Trigger: `spam_detected`

**The Committee for Deciding Things**
- 2 figures at a long desk. One carries capsule in, places it on desk. Both figures lean in to inspect. One picks up rubber stamp, walks it to the capsule, stamps firmly. Speech bubble: *"Quorum achieved."* / *"On the board it goes!"*
- Stamped capsule turns green; second figure carries it to The Tube inlet
- Trigger: `message_approved`

**The ChitChat Café**
- 2 figures seated. When triggered, one figure stands, walks to a serving hatch, collects a tea tray, walks back, sits. Speech bubbles exchanged. Occasional: *"Anyone else think this biscuit is a bit stale?"*
- Figures occasionally swap seats between events
- Trigger: `chat_message`

**The Tube**
- 1 figure. Receives green capsule from Committee, carries it to the launcher (a tall upright tube with a swing-open door), loads it in, pulls a lever. Capsule shoots upward through the trunk tube to the surface. Figure watches it go, gives a satisfied nod.
- Speech bubble: *"Someone's interested! 💌"*
- Trigger: downstream of `message_approved`

**The Gaggle**
- 3 figures. Senior figure stands at a small chalkboard. When `new_member` fires, a fourth (smaller/newer) figure enters from stage-left, walks up to the group. Senior figure turns, waves, points at chalkboard. Speech bubble: *"Welcome! Have a seat."* Newcomer sits down.
- Between events, the three existing figures mill about, occasionally swapping seats or gathering around the chalkboard
- Trigger: `new_member`

**The Boiler Room**
- 1 lone figure. Walks a circuit: screen → pipe valve → gauge on the wall → back to screen. Occasionally crouches to look at something on the floor, stands.
- Fully mime — no text. Expressive gesture timer every 20–40s cycles through: shrug, throw hands up dramatically, crouch and peer at floor, point accusingly at gauge, stamp foot, then — when a capsule passes cleanly through — jump for joy and pump fist before returning to the circuit.
- Positive gestures mixed in (things do sometimes work!) to keep it cheerful.
- Not triggered by events — independent loop

---

## Procedural Simulation Engine (`simulator.js`)

`CitySimulator` runs on a 500ms tick. Each tick:

1. Get current hour from the city clock (default: runs at 3× real speed so a full day cycles every 8 hours; configurable)
2. Look up busyness weight from `data.json` time-of-day curve
3. Sample: roll for 0–4 events this tick (weighted by busyness)
4. For each event, pick type by probability (offer 35%, wanted 18%, chat 13%, spam 6%, success 15%, new_member 2%, free_shop_drop 8%, free_shop_pick 8%) — success rate kept high to reinforce Freegle's positive impact
5. Fire events on the Event Bus

Events always fire at a minimum background rate (1 event per 2 ticks) regardless of time of day — the city is never dead.

---

## Greenness System

`greenScore` = cumulative `item_given_away` count (lifetime, `localStorage`).

Visual changes applied as score crosses thresholds:
- Smog overlay alpha decreases linearly from 0.3 → 0 between score 0 and 300
- Trees added as PixiJS sprites at fixed positions, one by one as score increments
- Buildings unlock with a small fanfare animation (bunting, speech bubbles from nearby figures)
- Bin lorry spawn interval scales from 90–180s up to 300–480s at score 300+
- **Score 100**: Street litter bins gain a second recycling slot (two-colour bin sprite)
- **Score 200**: Street bins become three-way recycling (general / recyclables / compost). Solar panels appear on building top faces (blue rectangle overlays on roof tops).
- **Score 250**: Cars on roads replaced by buses (bus sprite swapped in for car sprite in traffic pool)
- Cyclists appear at score 300 (2 figures added to road traffic pool)
- At score 500: background sky tint shifts from dark grey-green to brighter green-blue

### People diversity
Figures use a diverse palette of skin tones, clothing colours, and mobility. The figure pool includes:
- Standard walkers (various colours)
- Wheelchair users (~15% of figures) — slightly wider sprite with wheel detail
- Cyclists (appear at score 300+)
All figure types use the same waypoint/walk system; wheelchair users move at the same speed on roads/pavements.

---

## UI

- **Minimap** (top-right corner): 120×80px, shows full world, current viewport as a dim rectangle, clickable to jump
- **Score badge** (top-left): *"🌱 347 items saved from landfill"*
- **No other UI chrome** — the city speaks for itself
- Speech bubbles appear and disappear; no persistent log or sidebar

---

## Sprites

Primary source: **Kenney.nl Isometric City Kit** (CC0). Covers: road tiles, grass, residential buildings, vehicles. Supplement with **Kenney Isometric Characters** pack for people.

The Free Shop (shipping container) will need a custom sprite — simple enough to draw as a PixiJS Graphics object (coloured rectangle with perspective faces and a door) if no suitable sprite is found.

---

## Repo Setup

```
freegle-city/
  index.html
  city.js          # PixiJS app, world/stage setup, zoom/pan, minimap
  simulator.js     # CitySimulator, Event Bus
  data.json        # Seeded from DB query (committed, refreshed manually)
  sprites/         # Kenney CC0 assets
  scripts/
    export-data.js # Node script: connects to DB via tunnel, writes data.json
  README.md
```

GitHub Pages auto-deploy from `main` branch.
