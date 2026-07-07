# Freegle City Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone isometric city visualisation of Freegle activity — a zoomable/pannable city above ground and a pneumatic tube network below, driven by a procedural simulation engine seeded from real Freegle data.

**Architecture:** PixiJS v8 renders a `WorldContainer` larger than the viewport with zoom/pan. A `SurfaceStage` (isometric city with walking figures, speech bubbles, Free Shop, bin lorry) sits above a `GroundBand`, below which an `UndergroundStage` shows a cross-section tube network with seven named departments. A `CitySimulator` fires synthetic events on an `EventBus`; both stages listen and animate. No runtime API calls — all data is in `data.json`.

**Tech Stack:** PixiJS v8 (CDN, no build step), vanilla JS ES modules, Kenney.nl CC0 isometric sprites, Node.js (only for `scripts/export-data.js`), native browser `localStorage`.

---

## File Map

```
/home/edward/freegle-city/
  index.html                  # Canvas, speech-bubble container div, script tags
  src/
    main.js                   # Wires app: creates PixiJS app, all stages, simulator
    isometric.js              # worldToScreen(), screenToWorld(), depthKey()
    event-bus.js              # EventBus: on(), off(), emit()
    city-simulator.js         # CitySimulator: tick loop, time curve, event sampling
    greenness.js              # GreenScore: localStorage, threshold definitions, unlock callbacks
    world-container.js        # WorldContainer: PixiJS Container + zoom/pan input
    minimap.js                # Minimap: small canvas overlay showing full world + viewport rect
    surface/
      surface-stage.js        # SurfaceStage: tile grid, building sprites, people pool
      figure.js               # Figure: walking entity with waypoint queue, idle animation
      mime-gesture.js         # MimeGesture: tween sequences (jump, shrug, hold item, wave, etc.) on a Figure
      free-shop.js            # FreeShop: container sprite + drop-off/pick-up animation
      bin-lorry.js            # BinLorry: lorry + 3 protesters, spawned on timer
      buildings.js            # Building definitions: sprite keys, positions, unlock thresholds
    underground/
      underground-stage.js    # UndergroundStage: cross-section container, tube trunks
      tube-network.js         # TubeNetwork: draws tube runs, animates Capsule along a path
      capsule.js              # Capsule: coloured sprite, carried-by or in-tube state
      base-department.js      # BaseDepartment: inlet/outlet positions, figure queue, event hook
      sorting-office.js       # SortingOffice extends BaseDepartment
      spam-dungeon.js         # SpamDungeon extends BaseDepartment
      committee.js            # Committee extends BaseDepartment
      chitchat-cafe.js        # ChitchatCafe extends BaseDepartment
      the-tube-dept.js        # TheTubeDept extends BaseDepartment
      the-gaggle.js           # TheGaggle extends BaseDepartment
      boiler-room.js          # BoilerRoom: independent loop, snarky bubbles
  data/
    data.json                 # Item vocabulary + ratios + time curve (committed)
    data.example.json         # Example structure showing all fields
  sprites/                    # Kenney CC0 assets (downloaded in Task 1)
    kenney-isometric-city/    # Road tiles, buildings, grass
    kenney-characters/        # Walking figure spritesheets
  scripts/
    export-data.js            # Node: connects to DB via tunnel, writes data/data.json
  tests/
    test-isometric.js         # node tests/test-isometric.js
    test-event-bus.js         # node tests/test-event-bus.js
    test-simulator.js         # node tests/test-simulator.js
    test-greenness.js         # node tests/test-greenness.js
  README.md
```

---

## Task 1: Repo scaffold + PixiJS canvas

**Files:**
- Create: `/home/edward/freegle-city/` (new directory)
- Create: `index.html`
- Create: `src/main.js`
- Create: `data/data.example.json`
- Create: `README.md`

- [ ] **Step 1: Create directory and init git**

```bash
mkdir /home/edward/freegle-city && cd /home/edward/freegle-city
git init
echo "sprites/kenney-*" >> .gitignore
echo "node_modules/" >> .gitignore
```

- [ ] **Step 2: Download Kenney sprite packs**

```bash
mkdir -p sprites
# Kenney Isometric City Kit (CC0) — download from kenney.nl
# Direct zip URL:
curl -L "https://kenney.nl/content/assets/isometric-city-kit-v2.zip" -o sprites/kenney-city.zip 2>/dev/null \
  || echo "Download manually from https://kenney.nl/assets/isometric-city-kit — place extracted folder at sprites/kenney-isometric-city/"
# Kenney Isometric Characters (CC0):
curl -L "https://kenney.nl/content/assets/isometric-characters.zip" -o sprites/kenney-chars.zip 2>/dev/null \
  || echo "Download manually from https://kenney.nl/assets/isometric-characters — place at sprites/kenney-characters/"
```

Note: if curl fails (Kenney uses CDN), download ZIPs manually from kenney.nl and extract to `sprites/kenney-isometric-city/` and `sprites/kenney-characters/`. The app works without them (falls back to coloured rectangles) — sprites enhance but don't block.

- [ ] **Step 3: Create `index.html`**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
  <title>Freegle City</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #060e06; overflow: hidden; width: 100vw; height: 100vh; }
    #city-canvas { display: block; }
    #bubble-layer {
      position: fixed; inset: 0; pointer-events: none; overflow: hidden;
    }
    .speech-bubble {
      position: absolute;
      background: #0e2216;
      border: 1.5px solid #4de86a;
      border-radius: 8px;
      padding: 5px 10px;
      color: #7ef;
      font-family: monospace;
      font-size: 12px;
      white-space: nowrap;
      pointer-events: none;
      transform: translate(-50%, -100%);
      margin-top: -8px;
      opacity: 1;
      transition: opacity 0.5s ease;
    }
    .speech-bubble.fading { opacity: 0; }
    .speech-bubble::after {
      content: '';
      position: absolute;
      bottom: -7px; left: 50%;
      transform: translateX(-50%);
      border: 6px solid transparent;
      border-top-color: #4de86a;
    }
    .speech-bubble.underground {
      background: #0e0e08; border-color: #8a8a22; color: #cc8;
    }
    .speech-bubble.underground::after { border-top-color: #8a8a22; }
    #score-badge {
      position: fixed; top: 12px; left: 12px;
      background: rgba(6,14,6,0.85); border: 1px solid #3a6a3a;
      color: #5de87a; font-family: monospace; font-size: 13px;
      padding: 6px 12px; border-radius: 6px; z-index: 10;
    }
    #minimap-canvas {
      position: fixed; top: 12px; right: 12px;
      border: 1px solid #3a6a3a; cursor: pointer; z-index: 10;
    }
  </style>
</head>
<body>
  <canvas id="city-canvas"></canvas>
  <div id="bubble-layer"></div>
  <div id="score-badge">🌱 0 items saved from landfill</div>
  <canvas id="minimap-canvas" width="150" height="100"></canvas>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pixi.js/8.7.0/pixi.min.js"></script>
  <script type="module" src="src/main.js"></script>
</body>
</html>
```

- [ ] **Step 4: Create `src/main.js` (skeleton)**

```js
// src/main.js
import { EventBus } from './event-bus.js';
import { CitySimulator } from './city-simulator.js';
import { WorldContainer } from './world-container.js';

async function main() {
  const app = new PIXI.Application();
  await app.init({
    canvas: document.getElementById('city-canvas'),
    width: window.innerWidth,
    height: window.innerHeight,
    background: 0x060e06,
    antialias: true,
    resolution: window.devicePixelRatio || 1,
    autoDensity: true,
  });

  window.addEventListener('resize', () => {
    app.renderer.resize(window.innerWidth, window.innerHeight);
  });

  const bus = new EventBus();
  const world = new WorldContainer(app);
  app.stage.addChild(world.container);

  // Placeholder tick
  app.ticker.add(() => world.update());

  console.log('Freegle City initialised');
}

main().catch(console.error);
```

- [ ] **Step 5: Create `data/data.example.json`**

```json
{
  "items": [
    "armchair", "chest of drawers", "kids' bike", "sofa",
    "pushchair", "coffee table", "lamp", "bookshelf",
    "dining chairs", "microwave", "garden tools", "desk"
  ],
  "ratios": {
    "offer": 0.35, "wanted": 0.18, "chat": 0.13,
    "spam": 0.06, "success": 0.15, "new_member": 0.02,
    "free_shop_drop": 0.08, "free_shop_pick": 0.08
  },
  "timeCurve": [
    0.2, 0.1, 0.05, 0.05, 0.1, 0.3,
    0.6, 0.8, 0.9, 0.85, 0.8, 0.85,
    0.9, 0.8, 0.75, 0.8, 0.9, 1.0,
    0.95, 0.85, 0.7, 0.55, 0.4, 0.3
  ],
  "throughputPerHour": 45
}
```

- [ ] **Step 6: Copy example to data.json**

```bash
cp data/data.example.json data/data.json
```

- [ ] **Step 7: Verify PixiJS loads**

Open `index.html` in a browser (e.g. `python3 -m http.server 8080` from `freegle-city/`). Open DevTools console — should see `Freegle City initialised` with no errors.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: scaffold repo, index.html, PixiJS canvas, example data.json"
```

---

## Task 2: Isometric coordinate system

**Files:**
- Create: `src/isometric.js`
- Create: `tests/test-isometric.js`

The entire city uses one coordinate space. `worldToScreen` is called everywhere. Get this right first.

- [ ] **Step 1: Write failing tests**

```js
// tests/test-isometric.js
import assert from 'assert';
import { worldToScreen, screenToWorld, depthKey } from '../src/isometric.js';

const TILE_W = 64;
const TILE_H = 32;

// Origin maps to screen origin
const s0 = worldToScreen(0, 0, 0, TILE_W, TILE_H);
assert.strictEqual(s0.x, 0, 'origin x');
assert.strictEqual(s0.y, 0, 'origin y');

// x=1, y=0 → right-down
const s1 = worldToScreen(1, 0, 0, TILE_W, TILE_H);
assert.strictEqual(s1.x, TILE_W / 2, 'x=1 screenX');
assert.strictEqual(s1.y, TILE_H / 2, 'x=1 screenY');

// x=0, y=1 → left-down
const s2 = worldToScreen(0, 1, 0, TILE_W, TILE_H);
assert.strictEqual(s2.x, -TILE_W / 2, 'y=1 screenX');
assert.strictEqual(s2.y, TILE_H / 2, 'y=1 screenY');

// z=1 → up
const s3 = worldToScreen(0, 0, 1, TILE_W, TILE_H);
assert.strictEqual(s3.x, 0, 'z=1 screenX');
assert.strictEqual(s3.y, -TILE_H, 'z=1 screenY');

// depthKey: larger x+y = drawn later (in front)
assert.ok(depthKey(2, 2) > depthKey(1, 1), 'depth ordering');

// round-trip: worldToScreen then screenToWorld
const wx = 5, wy = 3;
const sc = worldToScreen(wx, wy, 0, TILE_W, TILE_H);
const back = screenToWorld(sc.x, sc.y, TILE_W, TILE_H);
assert.ok(Math.abs(back.x - wx) < 0.001, 'round-trip x');
assert.ok(Math.abs(back.y - wy) < 0.001, 'round-trip y');

console.log('✅ isometric tests pass');
```

- [ ] **Step 2: Run — verify it fails**

```bash
cd /home/edward/freegle-city
node --input-type=module < tests/test-isometric.js
```

Expected: `Error: Cannot find module '../src/isometric.js'`

- [ ] **Step 3: Implement `src/isometric.js`**

```js
// src/isometric.js

/**
 * Convert isometric world coords to screen (canvas) coords.
 * @param {number} wx  World x (right = east)
 * @param {number} wy  World y (down = south)
 * @param {number} wz  World z (up)
 * @param {number} tileW  Tile width in pixels (default 64)
 * @param {number} tileH  Tile height in pixels (default 32)
 */
export function worldToScreen(wx, wy, wz, tileW = 64, tileH = 32) {
  return {
    x: (wx - wy) * (tileW / 2),
    y: (wx + wy) * (tileH / 2) - wz * tileH,
  };
}

/**
 * Convert screen coords back to world x/y (z=0 plane).
 */
export function screenToWorld(sx, sy, tileW = 64, tileH = 32) {
  const x = (sx / (tileW / 2) + sy / (tileH / 2)) / 2;
  const y = (sy / (tileH / 2) - sx / (tileW / 2)) / 2;
  return { x, y };
}

/**
 * Depth sort key: higher = drawn later (in front).
 * Use as zIndex on PixiJS containers.
 */
export function depthKey(wx, wy) {
  return wx + wy;
}
```

- [ ] **Step 4: Run — verify it passes**

```bash
node --input-type=module < tests/test-isometric.js
```

Expected: `✅ isometric tests pass`

- [ ] **Step 5: Commit**

```bash
git add src/isometric.js tests/test-isometric.js
git commit -m "feat: isometric coordinate system with tests"
```

---

## Task 3: Event Bus

**Files:**
- Create: `src/event-bus.js`
- Create: `tests/test-event-bus.js`

- [ ] **Step 1: Write failing tests**

```js
// tests/test-event-bus.js
import assert from 'assert';
import { EventBus } from '../src/event-bus.js';

const bus = new EventBus();

// Basic emit/on
let received = null;
bus.on('offer_posted', (e) => { received = e; });
bus.emit('offer_posted', { item: 'armchair' });
assert.deepStrictEqual(received, { item: 'armchair' }, 'receives event');

// Multiple listeners
let count = 0;
bus.on('test', () => count++);
bus.on('test', () => count++);
bus.emit('test', {});
assert.strictEqual(count, 2, 'multiple listeners');

// off() removes listener
const handler = () => count++;
bus.on('remove_me', handler);
bus.off('remove_me', handler);
const before = count;
bus.emit('remove_me', {});
assert.strictEqual(count, before, 'off() removes listener');

// Unknown event emitted — no error thrown
assert.doesNotThrow(() => bus.emit('no_listeners', {}), 'no error for unknown event');

console.log('✅ event-bus tests pass');
```

- [ ] **Step 2: Run — verify it fails**

```bash
node --input-type=module < tests/test-event-bus.js
```

Expected: `Error: Cannot find module '../src/event-bus.js'`

- [ ] **Step 3: Implement `src/event-bus.js`**

```js
// src/event-bus.js
export class EventBus {
  #listeners = new Map();

  on(event, fn) {
    if (!this.#listeners.has(event)) this.#listeners.set(event, []);
    this.#listeners.get(event).push(fn);
  }

  off(event, fn) {
    if (!this.#listeners.has(event)) return;
    this.#listeners.set(event, this.#listeners.get(event).filter(f => f !== fn));
  }

  emit(event, data) {
    (this.#listeners.get(event) ?? []).forEach(fn => fn(data));
  }
}
```

- [ ] **Step 4: Run — verify it passes**

```bash
node --input-type=module < tests/test-event-bus.js
```

Expected: `✅ event-bus tests pass`

- [ ] **Step 5: Commit**

```bash
git add src/event-bus.js tests/test-event-bus.js
git commit -m "feat: event bus with tests"
```

---

## Task 4: City Simulator

**Files:**
- Create: `src/city-simulator.js`
- Create: `tests/test-simulator.js`

The simulator is pure logic — no PixiJS, no DOM. Fully testable in Node.

- [ ] **Step 1: Write failing tests**

```js
// tests/test-simulator.js
import assert from 'assert';
import { CitySimulator } from '../src/city-simulator.js';
import { EventBus } from '../src/event-bus.js';

const DATA = {
  items: ['armchair', 'sofa', 'bike'],
  ratios: {
    offer: 0.35, wanted: 0.18, chat: 0.13,
    spam: 0.06, success: 0.15, new_member: 0.02,
    free_shop_drop: 0.08, free_shop_pick: 0.03,
  },
  timeCurve: Array(24).fill(1.0), // flat curve for testing
  throughputPerHour: 120,         // high rate so events fire reliably in tests
};

const bus = new EventBus();
const sim = new CitySimulator(DATA, bus);

// tick() emits at least some events over 100 ticks
const fired = [];
['offer_posted','wanted_posted','chat_message','spam_detected',
 'item_given_away','new_member','free_shop_drop','free_shop_pick']
  .forEach(t => bus.on(t, (e) => fired.push({ type: t, ...e })));

for (let i = 0; i < 100; i++) sim.tick(12); // noon = full busyness

assert.ok(fired.length > 0, 'events fired over 100 ticks');

// All event types appear within 500 ticks (probabilistic but reliable at high throughput)
for (let i = 0; i < 500; i++) sim.tick(12);
const types = new Set(fired.map(e => e.type));
assert.ok(types.has('offer_posted'), 'offer_posted fires');
assert.ok(types.has('spam_detected'), 'spam_detected fires');
assert.ok(types.has('item_given_away'), 'item_given_away fires');

// offer events include an item name from vocabulary
const offer = fired.find(e => e.type === 'offer_posted');
assert.ok(DATA.items.includes(offer.item), 'offer has item from vocabulary');

// minimum background rate: even at hour 3 (low busyness) events still fire
const lowFired = [];
bus.on('offer_posted', (e) => lowFired.push(e));
const beforeLow = lowFired.length;
for (let i = 0; i < 200; i++) sim.tick(3); // 3am
assert.ok(lowFired.length > beforeLow, 'background rate fires even at low hour');

console.log('✅ simulator tests pass');
```

- [ ] **Step 2: Run — verify it fails**

```bash
node --input-type=module < tests/test-simulator.js
```

Expected: `Error: Cannot find module '../src/city-simulator.js'`

- [ ] **Step 3: Implement `src/city-simulator.js`**

```js
// src/city-simulator.js

const EVENT_TYPES = [
  'offer_posted', 'wanted_posted', 'chat_message', 'spam_detected',
  'item_given_away', 'new_member', 'free_shop_drop', 'free_shop_pick',
];

const MIN_EVENTS_PER_TICK = 0.5; // background rate: 1 event per 2 ticks

export class CitySimulator {
  #data;
  #bus;
  #cumulativeWeight = 0;

  constructor(data, bus) {
    this.#data = data;
    this.#bus = bus;
    // Pre-build cumulative ratio table for fast sampling
    this.#cumulativeWeight = this.#buildCumulative(data.ratios);
  }

  #buildCumulative(ratios) {
    const entries = EVENT_TYPES.map(t => [t, ratios[t] ?? 0]);
    let sum = 0;
    return entries.map(([t, w]) => { sum += w; return [t, sum]; });
  }

  /**
   * Call once per tick. `hour` is 0–23 (real or city clock).
   */
  tick(hour) {
    const busyness = this.#data.timeCurve[Math.floor(hour) % 24];
    // Expected events per tick = throughputPerHour / (3600 / 0.5) + background
    const ticksPerHour = 2000; // 500ms tick × 2000 = 1000s ≈ 1 hour at 3× city speed
    const expected = (this.#data.throughputPerHour / ticksPerHour) * busyness;
    const count = Math.max(MIN_EVENTS_PER_TICK, expected);

    // Poisson-like: emit floor(count) events, plus one more with probability frac
    const whole = Math.floor(count);
    const frac = count - whole;
    const n = whole + (Math.random() < frac ? 1 : 0);

    for (let i = 0; i < n; i++) {
      const type = this.#sampleType();
      const payload = { item: this.#randomItem() };
      this.#bus.emit(type, payload);
    }
  }

  #sampleType() {
    const r = Math.random();
    for (const [type, cumW] of this.#cumulativeWeight) {
      if (r <= cumW) return type;
    }
    return 'offer_posted'; // fallback
  }

  #randomItem() {
    const items = this.#data.items;
    return items[Math.floor(Math.random() * items.length)];
  }
}
```

- [ ] **Step 4: Run — verify it passes**

```bash
node --input-type=module < tests/test-simulator.js
```

Expected: `✅ simulator tests pass`

- [ ] **Step 5: Commit**

```bash
git add src/city-simulator.js tests/test-simulator.js
git commit -m "feat: procedural city simulator with tests"
```

---

## Task 5: Greenness system

**Files:**
- Create: `src/greenness.js`
- Create: `tests/test-greenness.js`

- [ ] **Step 1: Write failing tests**

```js
// tests/test-greenness.js
import assert from 'assert';

// Minimal localStorage shim for Node
global.localStorage = (() => {
  let store = {};
  return {
    getItem: (k) => store[k] ?? null,
    setItem: (k, v) => { store[k] = String(v); },
    clear: () => { store = {}; },
  };
})();

import { GreenScore } from '../src/greenness.js';

// Starts at 0 (fresh localStorage)
localStorage.clear();
const g = new GreenScore();
assert.strictEqual(g.score, 0, 'starts at 0');

// Increments
g.increment();
assert.strictEqual(g.score, 1, 'increments to 1');

// Threshold detection
const unlocks = [];
g.onThreshold(50, () => unlocks.push(50));
g.onThreshold(150, () => unlocks.push(150));

for (let i = 0; i < 49; i++) g.increment(); // score = 50
assert.ok(unlocks.includes(50), 'fires threshold at 50');
assert.ok(!unlocks.includes(150), 'does not fire 150 yet');

for (let i = 0; i < 100; i++) g.increment(); // score = 150
assert.ok(unlocks.includes(150), 'fires threshold at 150');

// Persists to localStorage
assert.strictEqual(Number(localStorage.getItem('freegleCity_greenScore')), 150, 'persists score');

// Restores from localStorage
const g2 = new GreenScore();
assert.strictEqual(g2.score, 150, 'restores from localStorage');

// smogAlpha: 0.3 at score 0, 0 at score 300
const g3 = new GreenScore();
g3._score = 0;
assert.ok(Math.abs(g3.smogAlpha - 0.3) < 0.001, 'smog 0.3 at score 0');
g3._score = 300;
assert.ok(Math.abs(g3.smogAlpha - 0.0) < 0.001, 'smog 0 at score 300');
g3._score = 150;
assert.ok(Math.abs(g3.smogAlpha - 0.15) < 0.001, 'smog 0.15 at score 150');

console.log('✅ greenness tests pass');
```

- [ ] **Step 2: Run — verify it fails**

```bash
node --input-type=module < tests/test-greenness.js
```

Expected: `Error: Cannot find module '../src/greenness.js'`

- [ ] **Step 3: Implement `src/greenness.js`**

```js
// src/greenness.js
const STORAGE_KEY = 'freegleCity_greenScore';

export class GreenScore {
  _score = 0;
  #thresholds = [];

  constructor() {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved !== null) this._score = Number(saved);
  }

  get score() { return this._score; }

  increment() {
    this._score++;
    localStorage.setItem(STORAGE_KEY, this._score);
    this.#thresholds
      .filter(t => !t.fired && this._score >= t.value)
      .forEach(t => { t.fired = true; t.fn(); });
  }

  onThreshold(value, fn) {
    const alreadyPast = this._score >= value;
    this.#thresholds.push({ value, fn, fired: alreadyPast });
    if (alreadyPast) fn(); // fire immediately if already past
  }

  /** Alpha for smog overlay: 0.3 at score 0, 0 at score 300, clamped. */
  get smogAlpha() {
    return Math.max(0, 0.3 * (1 - this._score / 300));
  }
}
```

- [ ] **Step 4: Run — verify passes**

```bash
node --input-type=module < tests/test-greenness.js
```

Expected: `✅ greenness tests pass`

- [ ] **Step 5: Commit**

```bash
git add src/greenness.js tests/test-greenness.js
git commit -m "feat: greenness score system with localStorage and threshold callbacks"
```

---

## Task 6: WorldContainer (zoom + pan)

**Files:**
- Create: `src/world-container.js`
- Modify: `src/main.js`

No unit tests (PixiJS/DOM). Verify visually.

- [ ] **Step 1: Implement `src/world-container.js`**

```js
// src/world-container.js

const MIN_ZOOM = 0.35;
const MAX_ZOOM = 2.8;
const ZOOM_SPEED = 0.001;

export class WorldContainer {
  container;        // PixiJS Container — all world content goes in here
  #app;
  #zoom = 1.0;
  #dragStart = null;
  #posStart = null;

  constructor(app) {
    this.#app = app;
    this.container = new PIXI.Container();
    this.container.eventMode = 'static';
    this.container.hitArea = new PIXI.Rectangle(-10000, -10000, 20000, 20000);

    // Start centred on the world
    this.container.x = app.screen.width / 2;
    this.container.y = app.screen.height / 3;

    this.#attachEvents();
  }

  #attachEvents() {
    const canvas = this.#app.canvas;

    // Zoom: mouse wheel
    canvas.addEventListener('wheel', (e) => {
      e.preventDefault();
      const delta = -e.deltaY * ZOOM_SPEED;
      const newZoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, this.#zoom + delta * this.#zoom));
      // Zoom towards cursor
      const mx = e.clientX - this.container.x;
      const my = e.clientY - this.container.y;
      this.container.x += mx * (1 - newZoom / this.#zoom);
      this.container.y += my * (1 - newZoom / this.#zoom);
      this.#zoom = newZoom;
      this.container.scale.set(this.#zoom);
    }, { passive: false });

    // Pan: pointer drag
    canvas.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      this.#dragStart = { x: e.clientX, y: e.clientY };
      this.#posStart = { x: this.container.x, y: this.container.y };
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!this.#dragStart) return;
      this.container.x = this.#posStart.x + (e.clientX - this.#dragStart.x);
      this.container.y = this.#posStart.y + (e.clientY - this.#dragStart.y);
    });
    canvas.addEventListener('pointerup', () => { this.#dragStart = null; });
    canvas.addEventListener('pointerleave', () => { this.#dragStart = null; });

    // Pinch zoom (touch)
    let lastDist = null;
    canvas.addEventListener('touchmove', (e) => {
      if (e.touches.length !== 2) return;
      e.preventDefault();
      const dx = e.touches[0].clientX - e.touches[1].clientX;
      const dy = e.touches[0].clientY - e.touches[1].clientY;
      const dist = Math.hypot(dx, dy);
      if (lastDist !== null) {
        const scale = dist / lastDist;
        const newZoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, this.#zoom * scale));
        this.#zoom = newZoom;
        this.container.scale.set(this.#zoom);
      }
      lastDist = dist;
    }, { passive: false });
    canvas.addEventListener('touchend', () => { lastDist = null; });
  }

  /** World coords → screen coords (accounting for current pan/zoom). */
  worldToDOM(wx, wy) {
    return {
      x: this.container.x + wx * this.#zoom,
      y: this.container.y + wy * this.#zoom,
    };
  }

  get zoom() { return this.#zoom; }

  update() {
    // Sort children by depthKey each frame for painter's algorithm
    this.container.children.sort((a, b) => (a._depthKey ?? 0) - (b._depthKey ?? 0));
  }
}
```

- [ ] **Step 2: Update `src/main.js` to use WorldContainer**

```js
// src/main.js
import { EventBus } from './event-bus.js';
import { WorldContainer } from './world-container.js';

async function main() {
  const app = new PIXI.Application();
  await app.init({
    canvas: document.getElementById('city-canvas'),
    width: window.innerWidth,
    height: window.innerHeight,
    background: 0x060e06,
    antialias: true,
    resolution: window.devicePixelRatio || 1,
    autoDensity: true,
  });

  window.addEventListener('resize', () => {
    app.renderer.resize(window.innerWidth, window.innerHeight);
  });

  const bus = new EventBus();
  const world = new WorldContainer(app);
  app.stage.addChild(world.container);

  // Smoke-test: drop a green circle at world origin
  const dot = new PIXI.Graphics().circle(0, 0, 8).fill(0x44ff88);
  dot._depthKey = 0;
  world.container.addChild(dot);

  app.ticker.add(() => world.update());
  console.log('Freegle City — zoom/pan ready. Scroll to zoom, drag to pan.');
}

main().catch(console.error);
```

- [ ] **Step 3: Verify visually**

Start server: `python3 -m http.server 8080` from `freegle-city/`
Open `http://localhost:8080`. Check:
- Green dot visible at centre
- Mouse wheel zooms towards cursor ✓
- Click-drag pans the view ✓
- No console errors ✓

- [ ] **Step 4: Commit**

```bash
git add src/world-container.js src/main.js
git commit -m "feat: zoomable/pannable WorldContainer"
```

---

## Task 7: Isometric tile grid (surface)

**Files:**
- Create: `src/surface/surface-stage.js`
- Modify: `src/main.js`

Lay down the isometric ground tile grid. Use coloured PIXI.Graphics rectangles as placeholder tiles until sprites are loaded.

- [ ] **Step 1: Create `src/surface/surface-stage.js`**

```js
// src/surface/surface-stage.js
import { worldToScreen, depthKey } from '../isometric.js';

const TILE_W = 64;
const TILE_H = 32;
const GRID_W = 40; // world tiles wide
const GRID_H = 40; // world tiles deep

// Colour bands for neighbourhood zones
const ZONE_COLOURS = {
  road:      0x1e2a1e,
  thornwick: 0x1a3020,
  bracken:   0x1e3820,
  puddleby:  0x182e1a,
  park:      0x1e4022,
};

export class SurfaceStage {
  container;
  #tileW = TILE_W;
  #tileH = TILE_H;

  constructor() {
    this.container = new PIXI.Container();
    this.#buildGrid();
  }

  #buildGrid() {
    for (let x = 0; x < GRID_W; x++) {
      for (let y = 0; y < GRID_H; y++) {
        const colour = this.#zoneColour(x, y);
        const tile = this.#makeTile(colour);
        const { x: sx, y: sy } = worldToScreen(x, y, 0, TILE_W, TILE_H);
        tile.x = sx;
        tile.y = sy;
        tile._depthKey = depthKey(x, y);
        this.container.addChild(tile);
      }
    }
  }

  #zoneColour(x, y) {
    // Main road: x=18-22 (horizontal), y=18-22 (vertical)
    if ((x >= 17 && x <= 22) || (y >= 17 && y <= 22)) return ZONE_COLOURS.road;
    if (x < 17 && y < 17) return ZONE_COLOURS.thornwick;
    if (x > 22 && y < 17) return ZONE_COLOURS.bracken;
    if (x < 17 && y > 22) return ZONE_COLOURS.puddleby;
    return ZONE_COLOURS.park;
  }

  #makeTile(colour) {
    const g = new PIXI.Graphics();
    // Isometric diamond
    g.poly([
      0, 0,
      TILE_W / 2, TILE_H / 2,
      0, TILE_H,
      -TILE_W / 2, TILE_H / 2,
    ]).fill(colour);
    g.poly([
      0, 0,
      TILE_W / 2, TILE_H / 2,
      0, TILE_H,
      -TILE_W / 2, TILE_H / 2,
    ]).stroke({ color: 0x1a2a1a, width: 0.5, alpha: 0.4 });
    return g;
  }
}
```

- [ ] **Step 2: Update `src/main.js`**

```js
// src/main.js
import { EventBus } from './event-bus.js';
import { WorldContainer } from './world-container.js';
import { SurfaceStage } from './surface/surface-stage.js';

async function main() {
  const app = new PIXI.Application();
  await app.init({
    canvas: document.getElementById('city-canvas'),
    width: window.innerWidth,
    height: window.innerHeight,
    background: 0x060e06,
    antialias: true,
    resolution: window.devicePixelRatio || 1,
    autoDensity: true,
  });

  window.addEventListener('resize', () => app.renderer.resize(window.innerWidth, window.innerHeight));

  const bus = new EventBus();
  const world = new WorldContainer(app);
  app.stage.addChild(world.container);

  const surface = new SurfaceStage();
  world.container.addChild(surface.container);

  app.ticker.add(() => world.update());
  console.log('Freegle City — tile grid ready');
}

main().catch(console.error);
```

- [ ] **Step 3: Verify visually**

Reload `http://localhost:8080`. Check:
- Isometric diamond grid fills the viewport ✓
- Three coloured zones visible (Thornwick, Brackenfield, Puddleby) ✓
- Road grid visible as darker strips ✓
- Zoom and pan work on the tiled surface ✓

- [ ] **Step 4: Commit**

```bash
git add src/surface/surface-stage.js src/main.js
git commit -m "feat: isometric tile grid with neighbourhood zones"
```

---

## Task 8: Buildings

**Files:**
- Create: `src/surface/buildings.js`
- Modify: `src/surface/surface-stage.js`

Place isometric buildings (coloured box primitives for now; replace with Kenney sprites later). Buildings are 3D boxes with top/right/left faces.

- [ ] **Step 1: Create `src/surface/buildings.js`**

```js
// src/surface/buildings.js
// Building definitions: world position, size (tiles), colour theme, unlock threshold
// All positions in world tile coords (wx, wy).

export const BUILDING_DEFS = [
  // Thornwick Lane (north-west)
  { id: 'house_a',   wx: 3,  wy: 3,  w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },
  { id: 'house_b',   wx: 6,  wy: 2,  w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },
  { id: 'house_c',   wx: 9,  wy: 4,  w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },
  { id: 'free_shop', wx: 12, wy: 6,  w: 3, h: 2, floors: 1, theme: 'container',   unlock: 0 },
  { id: 'house_d',   wx: 5,  wy: 9,  w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },
  { id: 'house_e',   wx: 8,  wy: 12, w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },

  // Brackenfield Common (north-east)
  { id: 'flats',     wx: 25, wy: 3,  w: 4, h: 3, floors: 4, theme: 'flats',       unlock: 0 },
  { id: 'shop',      wx: 30, wy: 6,  w: 3, h: 2, floors: 1, theme: 'shop',        unlock: 0 },
  { id: 'house_f',   wx: 27, wy: 10, w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },
  { id: 'house_g',   wx: 31, wy: 12, w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },

  // Lower Puddleby (south-west)
  { id: 'house_h',   wx: 3,  wy: 25, w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },
  { id: 'house_i',   wx: 7,  wy: 28, w: 2, h: 2, floors: 2, theme: 'residential', unlock: 0 },
  { id: 'repair_cafe',wx: 10, wy: 25, w: 3, h: 2, floors: 1, theme: 'community',  unlock: 150 },
  { id: 'zero_waste', wx: 4,  wy: 31, w: 3, h: 2, floors: 1, theme: 'eco',        unlock: 300 },
  { id: 'charity_shop',wx: 8, wy: 33, w: 3, h: 2, floors: 1, theme: 'charity',    unlock: 500 },
];

const THEME_COLOURS = {
  residential: { top: 0x2e7040, right: 0x254030, left: 0x1c3020 },
  flats:       { top: 0x2a4a65, right: 0x223850, left: 0x1a2a38 },
  shop:        { top: 0x4a3a1a, right: 0x3a2a12, left: 0x28200e },
  container:   { top: 0x2a4a3a, right: 0x203a2a, left: 0x162818 },
  community:   { top: 0x3a5a2a, right: 0x2a4a1a, left: 0x1e3414 },
  eco:         { top: 0x2a5a2a, right: 0x1e4a1e, left: 0x143414 },
  charity:     { top: 0x5a2a4a, right: 0x4a1a3a, left: 0x34102a },
};

const TILE_W = 64;
const TILE_H = 32;

/**
 * Draw an isometric box as a PIXI.Graphics.
 * @param {number} w  Width in tiles
 * @param {number} h  Depth in tiles
 * @param {number} floors  Height (each floor = 1 unit)
 * @param {string} theme
 */
export function drawBuilding(w, h, floors, theme) {
  const c = THEME_COLOURS[theme] ?? THEME_COLOURS.residential;
  const g = new PIXI.Graphics();
  const tw = TILE_W / 2, th = TILE_H / 2;
  const height = floors * TILE_H;

  // Pixel extents from world size
  const ex = w * tw, ey = w * th;  // right-face edge vector
  const fx = -h * tw, fy = h * th; // left-face edge vector

  // Top face (diamond)
  g.poly([0, -height, ex, ey - height, ex + fx, ey + fy - height, fx, fy - height]).fill(c.top);

  // Right face
  g.poly([ex, ey - height, ex, ey, ex + fx, ey + fy, ex + fx, ey + fy - height]).fill(c.right);

  // Left face
  g.poly([0, -height, 0, 0, fx, fy, fx, fy - height]).fill(c.left);

  // Window grid on right face (simple dots)
  for (let f = 0; f < floors; f++) {
    const wy = ey - (f + 0.7) * TILE_H;
    for (let wi = 0; wi < w; wi++) {
      const wdx = ex * (wi + 0.5) / w + fx * 0;
      const wdy = ey * (wi + 0.5) / w - (f + 0.7) * TILE_H;
      g.rect(ex * (wi + 0.3) / w - 2, wdy, 4, 4).fill(0xffe066, 0.4 + Math.random() * 0.3);
    }
  }

  return g;
}
```

- [ ] **Step 2: Update `src/surface/surface-stage.js` to place buildings**

Add to the constructor after `#buildGrid()`:

```js
// At top of surface-stage.js, add import:
import { BUILDING_DEFS, drawBuilding } from './buildings.js';
import { worldToScreen, depthKey } from '../isometric.js';

// In SurfaceStage constructor, after this.#buildGrid():
this.#placedBuildings = new Map();
this.#placeBuildings(0); // place all buildings with unlock=0
```

Add new method to `SurfaceStage`:

```js
#placedBuildings = new Map();

#placeBuildings(currentScore) {
  for (const def of BUILDING_DEFS) {
    if (def.unlock > currentScore) continue;
    if (this.#placedBuildings.has(def.id)) continue;
    const sprite = drawBuilding(def.w, def.h, def.floors, def.theme);
    const { x: sx, y: sy } = worldToScreen(def.wx, def.wy, 0, TILE_W, TILE_H);
    sprite.x = sx;
    sprite.y = sy;
    sprite._depthKey = depthKey(def.wx, def.wy) + 0.1;
    this.container.addChild(sprite);
    this.#placedBuildings.set(def.id, sprite);
  }
}

/** Called by greenness system when a threshold unlocks new buildings. */
unlockBuildings(score) {
  this.#placeBuildings(score);
}
```

- [ ] **Step 3: Verify visually**

Reload. Check:
- Buildings appear as 3D isometric boxes on the tile grid ✓
- Three neighbourhoods have different building types/colours ✓
- Depth ordering correct (near buildings occlude far ones) ✓
- zoom in to 2× — window dots visible on building faces ✓

- [ ] **Step 4: Commit**

```bash
git add src/surface/buildings.js src/surface/surface-stage.js
git commit -m "feat: isometric buildings with 3D box rendering and neighbourhood zones"
```

---

## Task 8b: Free Shop delivery animation

**Files:**
- Create: `src/surface/free-shop.js`
- Modify: `src/surface/surface-stage.js`
- Modify: `src/main.js`

On first page load the Free Shop doesn't exist yet — a flatbed lorry drives in, drops the container, and activity begins.

- [ ] **Step 1: Create `src/surface/free-shop.js`**

```js
// src/surface/free-shop.js
// Delivery sequence: lorry arrives → lowers container → lorry leaves → door opens → activity.

import { worldToScreen } from '../isometric.js';
import { SpeechBubble } from './speech-bubble.js';

const TILE_W = 64, TILE_H = 32;

export class FreeShop {
  container;       // The PixiJS container added to world
  #world;
  #delivered = false;
  #box;            // The shipping container sprite
  #door;           // Door graphic (swings open after delivery)

  constructor(world) {
    this.#world = world;
    this.container = new PIXI.Container();
    const { x, y } = worldToScreen(12, 6, 0, TILE_W, TILE_H);
    this.container.x = x;
    this.container.y = y;
  }

  /** Call once to run the delivery sequence. Returns a Promise. */
  async deliver() {
    if (this.#delivered) return;
    this.#delivered = true;

    // --- 1. Flatbed lorry drives in from left ---
    const lorry = this.#makeLorry();
    const { x: targetX, y: targetY } = worldToScreen(12, 6, 0, TILE_W, TILE_H);
    lorry.x = targetX - 300;  // start off-screen left
    lorry.y = targetY - 10;
    this.container.parent.addChild(lorry);

    await this.#tween(lorry, { x: targetX - 10 }, 2000, 'easeOut');

    // --- 2. Container sits on lorry bed, then lowers onto ground ---
    const box = this.#makeContainer();
    box.x = targetX;
    box.y = targetY - 80;   // start high (on lorry)
    this.container.parent.addChild(box);
    this.#box = box;

    await this.#tween(box, { y: targetY }, 1200, 'easeIn');
    // Thud! — brief scale pulse
    await this.#tween(box.scale, { x: 1.05, y: 0.95 }, 80);
    await this.#tween(box.scale, { x: 1, y: 1 }, 120);

    // --- 3. Lorry drives away right ---
    this.#tween(lorry, { x: targetX + 400 }, 1800, 'easeIn').then(() => {
      this.container.parent.removeChild(lorry);
    });

    await this.#wait(600);

    // --- 4. Door swings open ---
    const door = this.#makeDoor();
    door.x = targetX + 24;
    door.y = targetY - 14;
    this.container.parent.addChild(door);
    door.scale.x = 1;
    await this.#tween(door.scale, { x: 0.1 }, 400, 'linear'); // door swings (scale-X squash)
    this.container.parent.removeChild(door);

    // --- 5. Speech bubble ---
    const pos = this.#world.worldToDom(targetX, targetY - 40);
    SpeechBubble.show('🛍️ Free Shop is open! Drop off anything. Take anything.', pos.x, pos.y);
  }

  #makeLorry() {
    const g = new PIXI.Graphics();
    g.rect(0, -18, 70, 18).fill(0x1e3a1e);      // flatbed
    g.rect(0, -18, 20, 18).fill(0x253e25);       // cab
    g.rect(2, -16, 14, 10).fill(0x0a1a0a);       // cab window
    g.circle(12, 2, 5).fill(0x0a0a0a).stroke({ color: 0x3a4a3a, width: 1 });
    g.circle(58, 2, 5).fill(0x0a0a0a).stroke({ color: 0x3a4a3a, width: 1 });
    return g;
  }

  #makeContainer() {
    const g = new PIXI.Graphics();
    // Left face (dark)
    g.poly([0, 0, -20, 10, -20, 46, 0, 36]).fill(0x1a3828);
    // Right face (mid)
    g.poly([0, 0, 40, 10, 40, 46, 0, 36]).fill(0x2a5040);
    // Top face (light)
    g.poly([0, 0, 40, 10, 20, 20, -20, 10]).fill(0x3a6050);
    // Freegle logo text (stencilled)
    const logo = new PIXI.Text({ text: 'freegle',
      style: { fontSize: 7, fill: 0x88ffaa, fontFamily: 'monospace', fontWeight: 'bold' }});
    logo.x = 4; logo.y = 14;
    // Corrugation lines on right face
    for (let i = 0; i < 4; i++) {
      g.moveTo(2 + i * 9, 12).lineTo(2 + i * 9, 44);
    }
    g.stroke({ color: 0x1a3828, width: 0.5, alpha: 0.4 });
    const c = new PIXI.Container();
    c.addChild(g, logo);
    return c;
  }

  #makeDoor() {
    const g = new PIXI.Graphics();
    g.rect(0, 0, 16, 28).fill(0x2a5040).stroke({ color: 0x3a7058, width: 1 });
    return g;
  }

  /** Minimal promise-based tween using PixiJS ticker. */
  #tween(target, props, duration, ease = 'linear') {
    return new Promise(resolve => {
      const start = {};
      const end = {};
      for (const k of Object.keys(props)) {
        start[k] = target[k];
        end[k] = props[k];
      }
      let elapsed = 0;
      const tick = (ticker) => {
        elapsed += ticker.deltaMS;
        const t = Math.min(elapsed / duration, 1);
        const e = ease === 'easeOut' ? 1 - (1 - t) ** 2
                : ease === 'easeIn'  ? t * t
                : t; // linear
        for (const k of Object.keys(props)) {
          target[k] = start[k] + (end[k] - start[k]) * e;
        }
        if (t >= 1) {
          PIXI.Ticker.shared.remove(tick);
          resolve();
        }
      };
      PIXI.Ticker.shared.add(tick);
    });
  }

  #wait(ms) { return new Promise(r => setTimeout(r, ms)); }
}
```

- [ ] **Step 2: Trigger delivery from `main.js` on first load**

```js
import { FreeShop } from './surface/free-shop.js';
// After surface + world setup:
const freeShop = new FreeShop(world);
// Deliver on first load, after a short pause so city is visible first
setTimeout(() => freeShop.deliver(), 2500);
```

- [ ] **Step 3: Verify visually**

Reload. After 2.5s: flatbed lorry drives in from left, container lowers onto plot, lorry exits right, door swings open, speech bubble appears. Container has "freegle" text on side.

- [ ] **Step 4: Commit**

```bash
git add src/surface/free-shop.js src/main.js
git commit -m "feat: Free Shop delivery sequence — lorry arrives, drops container, door opens"
```

---

## Task 9: Walking figures

**Files:**
- Create: `src/surface/figure.js`
- Modify: `src/surface/surface-stage.js`

Figures walk along waypoint paths. The same class is reused for surface pedestrians and underground department workers.

- [ ] **Step 1: Create `src/surface/figure.js`**

```js
// src/surface/figure.js
// A walking figure: follows a waypoint queue, plays walk/idle animation.
// Uses coloured circle + direction indicator as placeholder (replace with Kenney sprites).

const WALK_SPEED = 40; // pixels per second (screen coords)

export class Figure {
  sprite;          // PIXI.Container
  #waypoints = []; // [{x, y}] in screen coords
  #onArrive = null;
  #idle = true;
  #carried = null; // Capsule sprite attached while carrying

  constructor(colour = 0xf9aabb) {
    this.sprite = new PIXI.Container();
    // Body
    const body = new PIXI.Graphics().circle(0, 0, 6).fill(colour);
    // Direction dot
    this.#dot = new PIXI.Graphics().circle(4, -2, 2).fill(0xffffff, 0.7);
    this.sprite.addChild(body);
    this.sprite.addChild(this.#dot);
  }

  #dot;

  /** Queue a screen-space destination. Optional callback on arrival. */
  walkTo(x, y, onArrive = null) {
    this.#waypoints.push({ x, y, onArrive });
    this.#idle = false;
  }

  /** Attach a capsule sprite to this figure (shows it's carrying something). */
  carry(capsuleSprite) {
    this.#carried = capsuleSprite;
    capsuleSprite.x = 0;
    capsuleSprite.y = -10;
    this.sprite.addChild(capsuleSprite);
  }

  /** Detach carried capsule and return it. */
  release() {
    if (!this.#carried) return null;
    const c = this.#carried;
    this.sprite.removeChild(c);
    this.#carried = null;
    return c;
  }

  get isIdle() { return this.#idle; }

  /** Call every tick with delta time in seconds. */
  update(dt) {
    if (this.#waypoints.length === 0) {
      this.#idle = true;
      return;
    }
    const target = this.#waypoints[0];
    const dx = target.x - this.sprite.x;
    const dy = target.y - this.sprite.y;
    const dist = Math.hypot(dx, dy);

    if (dist < 2) {
      this.sprite.x = target.x;
      this.sprite.y = target.y;
      const cb = target.onArrive;
      this.#waypoints.shift();
      if (cb) cb(this);
    } else {
      const step = Math.min(WALK_SPEED * dt, dist);
      this.sprite.x += (dx / dist) * step;
      this.sprite.y += (dy / dist) * step;
      // Point direction dot towards movement
      this.#dot.x = (dx / dist) * 4;
      this.#dot.y = (dy / dist) * 4 - 2;
    }
  }
}
```

- [ ] **Step 2: Add a pedestrian pool to `surface-stage.js`**

Add import and pedestrian spawning:

```js
// Add to imports in surface-stage.js:
import { Figure } from './figure.js';

// ROAD_PATHS: array of waypoint loops for pedestrians to walk.
// Screen coords are computed from world tile positions.
const PEDESTRIAN_ROUTES = [
  // Route 1: loop along Thornwick Lane road
  [{ wx: 17, wy: 5 }, { wx: 17, wy: 14 }, { wx: 17, wy: 5 }],
  // Route 2: cross-road walk
  [{ wx: 5, wy: 17 }, { wx: 14, wy: 17 }, { wx: 5, wy: 17 }],
  // Route 3: longer loop
  [{ wx: 25, wy: 5 }, { wx: 17, wy: 5 }, { wx: 17, wy: 17 }, { wx: 25, wy: 17 }, { wx: 25, wy: 5 }],
  // Route 4: south loop
  [{ wx: 5, wy: 25 }, { wx: 14, wy: 25 }, { wx: 14, wy: 33 }, { wx: 5, wy: 33 }, { wx: 5, wy: 25 }],
  // Route 5: east road
  [{ wx: 22, wy: 10 }, { wx: 33, wy: 10 }, { wx: 22, wy: 10 }],
  // Route 6: Free Shop approach
  [{ wx: 10, wy: 6 }, { wx: 12, wy: 6 }, { wx: 10, wy: 6 }],
  // Route 7: meander
  [{ wx: 28, wy: 22 }, { wx: 22, wy: 28 }, { wx: 28, wy: 22 }],
  // Route 8
  [{ wx: 8, wy: 20 }, { wx: 14, wy: 20 }, { wx: 8, wy: 20 }],
];
```

Add to `SurfaceStage` constructor:

```js
this.#figures = [];
this.#spawnPedestrians();
```

Add methods:

```js
#figures = [];

#spawnPedestrians() {
  const colours = [0xf9aabb, 0xb9f0aa, 0xaabbf9, 0xf0e0aa, 0xc0c0f0];
  PEDESTRIAN_ROUTES.forEach((route, i) => {
    const fig = new Figure(colours[i % colours.length]);
    const start = worldToScreen(route[0].wx, route[0].wy, 0, TILE_W, TILE_H);
    fig.sprite.x = start.x;
    fig.sprite.y = start.y;
    fig.sprite._depthKey = 999;
    this.container.addChild(fig.sprite);
    this.#figures.push({ fig, route, routeIdx: 0 });
    this.#advanceRoute(i);
  });
}

#advanceRoute(i) {
  const entry = this.#figures[i];
  const { fig, route } = entry;
  entry.routeIdx = (entry.routeIdx + 1) % route.length;
  const wp = route[entry.routeIdx];
  const { x, y } = worldToScreen(wp.wx, wp.wy, 0, TILE_W, TILE_H);
  fig.walkTo(x, y, () => this.#advanceRoute(i));
}

update(dt) {
  this.#figures.forEach(({ fig }) => fig.update(dt));
}
```

- [ ] **Step 3: Update `main.js` to call `surface.update(dt)`**

```js
app.ticker.add((ticker) => {
  const dt = ticker.deltaTime / 60; // seconds
  surface.update(dt);
  world.update();
});
```

- [ ] **Step 4: Verify visually**

Reload. Check:
- 8 coloured figures walking loops around the city streets ✓
- Figures loop continuously without stopping ✓
- No console errors ✓

- [ ] **Step 5: Commit**

```bash
git add src/surface/figure.js src/surface/surface-stage.js src/main.js
git commit -m "feat: walking figures with waypoint routing on surface"
```

---

## Task 10: Mime gesture system

**Files:**
- Create: `src/surface/mime-gesture.js`
- Modify: `src/main.js` (smoke-test then remove)

No speech bubbles anywhere. Figures express via pantomime — PixiJS tween sequences only.
A `MimeGesture` helper plays short animations on a Figure's container.

Item events float a tiny coloured shape sprite above the figure (rises 20px, fades over 1.5s).

**Gesture library** (each plays on a Figure container, duration ~600–900ms):

| Key | Description |
|---|---|
| `jumpForJoy` | container bounces up −20px then returns, quick |
| `shrug` | container rocks left/right ±4px |
| `throwHandsUp` | container hops up −10px, tilts +10deg, returns |
| `wave` | container oscillates x ±3px, 3 cycles |
| `holdItemUp` | small coloured shape sprite appears above, rises, fades |
| `pointExcitedly` | container leans forward (+5px x), returns |
| `dustHands` | container bobs up −5px twice |
| `nodApproval` | container bobs down +4px twice |
| `shakeHead` | container oscillates x ±5px, 2 cycles |
| `stampFoot` | container drops +8px then returns sharply |
| `peersAtThing` | container leans forward and tilts −5deg, holds, returns |

- [ ] **Step 1: Create `src/surface/mime-gesture.js`**

```js
// src/surface/mime-gesture.js

// Item shape colours — stand-in for real item sprites
const ITEM_COLOURS = {
  armchair:    { w: 28, h: 14, colour: 0x8B5E3C },
  sofa:        { w: 36, h: 12, colour: 0x6B8E5A },
  bike:        { w: 20, h: 20, colour: 0x4a7fc1, round: true },
  default:     { w: 22, h: 14, colour: 0xb0a060 },
};

function itemShape(itemName) {
  const def = ITEM_COLOURS[itemName] ?? ITEM_COLOURS.default;
  const g = new PIXI.Graphics();
  if (def.round) {
    g.circle(0, 0, def.w / 2).fill({ color: def.colour });
  } else {
    g.rect(-def.w / 2, -def.h / 2, def.w, def.h).fill({ color: def.colour });
  }
  return g;
}

/**
 * Tween helper — animates a property on target over durationMs.
 * Returns a Promise that resolves when done.
 */
function tween(target, prop, from, to, durationMs) {
  return new Promise(resolve => {
    const ticker = PIXI.Ticker.shared;
    let elapsed = 0;
    const fn = (dt) => {
      elapsed += ticker.elapsedMS;
      const t = Math.min(elapsed / durationMs, 1);
      target[prop] = from + (to - from) * t;
      if (t >= 1) { ticker.remove(fn); resolve(); }
    };
    target[prop] = from;
    ticker.add(fn);
  });
}

async function seq(...steps) { for (const s of steps) await s; }

export class MimeGesture {
  /**
   * Play a named gesture on a PIXI Container (figure.container).
   * @param {PIXI.Container} container
   * @param {string} gesture  Key from gesture library
   * @param {string} [itemName]  For holdItemUp gesture
   */
  static async play(container, gesture, itemName) {
    const ox = container.x, oy = container.y;
    switch (gesture) {
      case 'jumpForJoy':
        await seq(
          tween(container, 'y', oy, oy - 20, 180),
          tween(container, 'y', oy - 20, oy, 180),
          tween(container, 'y', oy, oy - 10, 120),
          tween(container, 'y', oy - 10, oy, 120),
        );
        break;
      case 'shrug':
        await seq(
          tween(container, 'x', ox, ox - 4, 120),
          tween(container, 'x', ox - 4, ox + 4, 200),
          tween(container, 'x', ox + 4, ox, 120),
        );
        break;
      case 'throwHandsUp':
        await seq(
          tween(container, 'y', oy, oy - 10, 150),
          tween(container, 'rotation', 0, 0.18, 150),
          new Promise(r => setTimeout(r, 200)),
          tween(container, 'y', oy - 10, oy, 150),
          tween(container, 'rotation', 0.18, 0, 150),
        );
        break;
      case 'wave':
        for (let i = 0; i < 3; i++) {
          await tween(container, 'x', ox, ox + 3, 80);
          await tween(container, 'x', ox + 3, ox - 3, 80);
          await tween(container, 'x', ox - 3, ox, 80);
        }
        break;
      case 'holdItemUp': {
        const shape = itemShape(itemName ?? 'default');
        shape.x = 0; shape.y = -32; shape.alpha = 1;
        container.addChild(shape);
        await tween(shape, 'y', -32, -52, 400);
        await tween(shape, 'alpha', 1, 0, 600);
        container.removeChild(shape);
        shape.destroy();
        break;
      }
      case 'pointExcitedly':
        await seq(
          tween(container, 'x', ox, ox + 5, 150),
          new Promise(r => setTimeout(r, 300)),
          tween(container, 'x', ox + 5, ox, 150),
        );
        break;
      case 'dustHands':
        for (let i = 0; i < 2; i++) {
          await tween(container, 'y', oy, oy - 5, 100);
          await tween(container, 'y', oy - 5, oy, 100);
        }
        break;
      case 'nodApproval':
        for (let i = 0; i < 2; i++) {
          await tween(container, 'y', oy, oy + 4, 100);
          await tween(container, 'y', oy + 4, oy, 100);
        }
        break;
      case 'shakeHead':
        for (let i = 0; i < 2; i++) {
          await tween(container, 'x', ox, ox - 5, 100);
          await tween(container, 'x', ox - 5, ox + 5, 120);
          await tween(container, 'x', ox + 5, ox, 100);
        }
        break;
      case 'stampFoot':
        await seq(
          tween(container, 'y', oy, oy + 8, 80),
          tween(container, 'y', oy + 8, oy, 60),
        );
        break;
      case 'peersAtThing':
        await seq(
          tween(container, 'x', ox, ox + 5, 200),
          tween(container, 'rotation', 0, -0.09, 200),
          new Promise(r => setTimeout(r, 500)),
          tween(container, 'x', ox + 5, ox, 200),
          tween(container, 'rotation', -0.09, 0, 200),
        );
        break;
    }
    // Ensure position is restored
    container.x = ox;
    container.y = oy;
    container.rotation = 0;
  }
}
```

- [ ] **Step 2: Smoke-test in `main.js`**

Add temporarily after stage setup:

```js
import { MimeGesture } from './surface/mime-gesture.js';
// Create a test square to mime on
setTimeout(() => {
  const testFig = new PIXI.Container();
  const body = new PIXI.Graphics().rect(-8, -16, 16, 24).fill({ color: 0x4de86a });
  testFig.addChild(body);
  testFig.x = app.screen.width / 2;
  testFig.y = app.screen.height / 2;
  app.stage.addChild(testFig);
  // Cycle through gestures
  const gestures = ['jumpForJoy','shrug','throwHandsUp','wave','holdItemUp','dustHands','nodApproval','shakeHead','stampFoot','peersAtThing'];
  let i = 0;
  const next = () => {
    if (i >= gestures.length) return;
    MimeGesture.play(testFig, gestures[i], 'armchair').then(() => {
      i++;
      setTimeout(next, 400);
    });
  };
  next();
}, 500);
```

- [ ] **Step 3: Open in Chrome, verify each gesture plays correctly**

All 10 gestures should play in sequence. The `holdItemUp` gesture should show a brown rectangle rising and fading.

- [ ] **Step 4: Remove smoke-test from main.js**

- [ ] **Step 5: Also remove `#bubble-layer` div from `index.html` and the `.speech-bubble` CSS** (no longer needed)

- [ ] **Step 6: Commit**

```bash
git add src/surface/mime-gesture.js src/main.js index.html
git commit -m "feat: mime gesture system replaces speech bubbles"
```

---

## Task 11: Ground band + tube entry holes

**Files:**
- Create: `src/surface/ground-band.js`
- Modify: `src/main.js`

The visual separator between surface and underground. Three glowing tube holes at fixed positions.

- [ ] **Step 1: Create `src/surface/ground-band.js`**

```js
// src/surface/ground-band.js
import { worldToScreen } from '../isometric.js';

const TILE_W = 64;
const TILE_H = 32;

// Three tube entry points (world coords)
export const TUBE_ENTRIES = [
  { wx: 8,  wy: 19, label: 'west' },
  { wx: 20, wy: 20, label: 'central' },
  { wx: 32, wy: 19, label: 'east' },
];

export class GroundBand {
  container;
  #holes = [];
  #time = 0;

  constructor() {
    this.container = new PIXI.Container();
    this.#draw();
  }

  #draw() {
    // Draw a thick ground-coloured band
    const band = new PIXI.Graphics();
    band.rect(-2000, 0, 4000, 28).fill(0x2a4020);
    band.rect(-2000, 20, 4000, 8).fill(0x1a2a14);
    // Position the band at world y=18 (just below the main road)
    const { x, y } = worldToScreen(20, 18, 0, TILE_W, TILE_H);
    band.x = x;
    band.y = y + 10;
    this.container.addChild(band);

    // Tube entry holes
    for (const entry of TUBE_ENTRIES) {
      const pos = worldToScreen(entry.wx, entry.wy, 0, TILE_W, TILE_H);
      const hole = new PIXI.Graphics();
      hole.ellipse(0, 0, 14, 7).fill(0x0a140a).stroke({ color: 0x3a7a3a, width: 1.5 });
      const glow = new PIXI.Graphics().ellipse(0, 0, 18, 9).fill({ color: 0x3a7a3a, alpha: 0.15 });
      const label = new PIXI.Text({ text: '▼', style: { fontSize: 8, fill: 0x4a8a4a } });
      label.anchor.set(0.5, 0.5);
      const g = new PIXI.Container();
      g.addChild(glow, hole, label);
      g.x = pos.x;
      g.y = pos.y + 20;
      this.container.addChild(g);
      this.#holes.push({ g, glow, base: pos.y + 20 });
    }
  }

  // Mark one tube hole as active (capsule about to drop in)
  flash(label) {
    const idx = TUBE_ENTRIES.findIndex(e => e.label === label);
    if (idx < 0) return;
    const { glow } = this.#holes[idx];
    glow.alpha = 0.7;
    setTimeout(() => { glow.alpha = 0.15; }, 400);
  }

  update(dt) {
    this.#time += dt;
    // Gentle pulsing glow on all holes
    this.#holes.forEach(({ glow }, i) => {
      glow.alpha = 0.1 + 0.08 * Math.sin(this.#time * 2 + i);
    });
  }
}
```

- [ ] **Step 2: Update `main.js`** to add GroundBand

```js
import { GroundBand } from './surface/ground-band.js';
// After surface:
const groundBand = new GroundBand();
world.container.addChild(groundBand.container);
// In ticker:
groundBand.update(dt);
```

- [ ] **Step 3: Verify visually**

Reload. Check:
- A dark ground band spans the city ✓
- Three glowing elliptical tube holes pulse gently ✓
- Arrow labels visible in holes ✓

- [ ] **Step 4: Commit**

```bash
git add src/surface/ground-band.js src/main.js
git commit -m "feat: ground band with pulsing tube entry holes"
```

---

## Task 12: Underground stage + tube network

**Files:**
- Create: `src/underground/underground-stage.js`
- Create: `src/underground/tube-network.js`
- Create: `src/underground/capsule.js`
- Modify: `src/main.js`

Underground is a flat 2D cross-section rendered below the ground band. No isometric maths — straight screen coordinates.

- [ ] **Step 1: Create `src/underground/capsule.js`**

```js
// src/underground/capsule.js

const COLOURS = {
  yellow: 0xffe066,  // offer/wanted (inbound)
  blue:   0x66aaff,  // approved/notification (return)
  red:    0xff4422,  // spam (rejected)
  green:  0x44dd88,  // successful giveaway
};

export class Capsule {
  sprite;
  colour;

  constructor(colour = 'yellow') {
    this.colour = colour;
    const g = new PIXI.Graphics();
    // Rounded rectangle (capsule shape, horizontal)
    g.roundRect(-14, -5, 28, 10, 5).fill(COLOURS[colour] ?? COLOURS.yellow);
    // Highlight stripe
    g.roundRect(-10, -3, 8, 3, 2).fill(0xffffff, 0.3);
    this.sprite = g;
  }
}
```

- [ ] **Step 2: Create `src/underground/tube-network.js`**

```js
// src/underground/tube-network.js
import { Capsule } from './capsule.js';

// Tube segment: {x1, y1, x2, y2}
// Underground coordinate space: origin (0,0) is top-left of underground area.
// The underground is 700px wide, 250px tall (below ground band).

export const TUBE_SEGMENTS = {
  // Main horizontal trunk (top)
  trunkTop: { x1: 30, y1: 50, x2: 670, y2: 50 },
  // Main horizontal trunk (bottom — return)
  trunkBottom: { x1: 30, y1: 90, x2: 670, y2: 90 },
  // Vertical drops from surface to intake
  dropWest:    { x1: 90,  y1: 0,   x2: 90,  y2: 50 },
  dropCentral: { x1: 350, y1: 0,   x2: 350, y2: 50 },
  dropEast:    { x1: 610, y1: 0,   x2: 610, y2: 50 },
  // Branches to departments
  branchSort:  { x1: 140, y1: 50,  x2: 140, y2: 110 },
  branchSpam:  { x1: 140, y1: 190, x2: 140, y2: 240 },
  branchComm:  { x1: 280, y1: 50,  x2: 280, y2: 110 },
  branchCafe:  { x1: 280, y1: 190, x2: 280, y2: 240 },
  branchTube:  { x1: 420, y1: 50,  x2: 420, y2: 110 },
  branchGaggle:{ x1: 560, y1: 90,  x2: 560, y2: 160 },
  branchBoiler:{ x1: 620, y1: 90,  x2: 620, y2: 160 },
};

export class TubeNetwork {
  container;
  #activeCapsules = [];

  constructor() {
    this.container = new PIXI.Container();
    this.#drawTubes();
  }

  #drawTubes() {
    const g = new PIXI.Graphics();
    for (const seg of Object.values(TUBE_SEGMENTS)) {
      const isH = seg.y1 === seg.y2;
      const w = isH ? Math.abs(seg.x2 - seg.x1) : 10;
      const h = isH ? 10 : Math.abs(seg.y2 - seg.y1);
      const x = Math.min(seg.x1, seg.x2) - (isH ? 0 : 0);
      const y = Math.min(seg.y1, seg.y2);
      // Outer shell
      g.rect(x - (isH ? 0 : 1), y, (isH ? w : 12), h).fill(0x1a2e1a);
      // Inner channel
      g.rect(x + (isH ? 0 : 2), y + (isH ? 2 : 0), (isH ? w : 8), (isH ? 6 : h)).fill(0x0a1a0a);
      // Highlight
      g.rect(x + (isH ? 0 : 3), y + (isH ? 2 : 0), (isH ? w : 2), (isH ? 2 : h)).fill(0x2a5a2a, 0.3);
    }
    this.container.addChild(g);
  }

  /**
   * Animate a capsule from point A to B to C along tube waypoints.
   * @param {string} colour   Capsule colour key
   * @param {{x,y}[]} path    Array of {x,y} screen positions along tube
   * @param {Function} onDone Called when capsule reaches destination
   */
  sendCapsule(colour, path, onDone) {
    const capsule = new Capsule(colour);
    capsule.sprite.x = path[0].x;
    capsule.sprite.y = path[0].y;
    this.container.addChild(capsule.sprite);

    let step = 0;
    const SPEED = 180; // px/s

    const entry = { capsule, path, step, onDone };
    this.#activeCapsules.push(entry);
  }

  update(dt) {
    this.#activeCapsules = this.#activeCapsules.filter(entry => {
      const { capsule, path, onDone } = entry;
      if (entry.step >= path.length - 1) return false;

      const target = path[entry.step + 1];
      const dx = target.x - capsule.sprite.x;
      const dy = target.y - capsule.sprite.y;
      const dist = Math.hypot(dx, dy);
      const step = 180 * dt;

      if (dist <= step) {
        capsule.sprite.x = target.x;
        capsule.sprite.y = target.y;
        entry.step++;
        if (entry.step >= path.length - 1) {
          this.container.removeChild(capsule.sprite);
          if (onDone) onDone();
          return false;
        }
      } else {
        capsule.sprite.x += (dx / dist) * step;
        capsule.sprite.y += (dy / dist) * step;
      }
      return true;
    });
  }
}
```

- [ ] **Step 3: Create `src/underground/underground-stage.js`**

```js
// src/underground/underground-stage.js
import { TubeNetwork } from './tube-network.js';

// Underground sits 30px below the ground band.
export const UNDERGROUND_Y_OFFSET = 210; // screen pixels below world container origin

export class UndergroundStage {
  container;
  tubeNetwork;
  #depts = [];
  #visible = false;

  constructor() {
    this.container = new PIXI.Container();
    this.container.y = UNDERGROUND_Y_OFFSET;

    // Background
    const bg = new PIXI.Graphics().rect(-500, 0, 2000, 350).fill(0x060e06);
    this.container.addChild(bg);

    this.tubeNetwork = new TubeNetwork();
    this.container.addChild(this.tubeNetwork.container);
  }

  addDepartment(dept) {
    this.#depts.push(dept);
    this.container.addChild(dept.container);
  }

  /** Show/hide based on zoom level. */
  setVisible(visible) {
    this.#visible = visible;
    this.container.visible = visible;
  }

  update(dt) {
    if (!this.#visible) return;
    this.tubeNetwork.update(dt);
    this.#depts.forEach(d => d.update(dt));
  }
}
```

- [ ] **Step 4: Update `main.js`** with underground

```js
import { UndergroundStage } from './underground/underground-stage.js';
// After groundBand:
const underground = new UndergroundStage();
world.container.addChild(underground.container);
// Show underground when zoom >= 0.7
app.ticker.add(() => {
  underground.setVisible(world.zoom >= 0.65);
});
```

- [ ] **Step 5: Verify visually**

Reload. Zoom out to ~0.65 and below — underground tube network appears. Zoom in past 0.65 — it hides. Tube lines visible as dark channels with highlights.

- [ ] **Step 6: Commit**

```bash
git add src/underground/ src/main.js
git commit -m "feat: underground stage with tube network and capsule animation"
```

---

## Task 13: Base department + all seven departments

**Files:**
- Create: `src/underground/base-department.js`
- Create: `src/underground/sorting-office.js`
- Create: `src/underground/spam-dungeon.js`
- Create: `src/underground/committee.js`
- Create: `src/underground/chitchat-cafe.js`
- Create: `src/underground/the-tube-dept.js`
- Create: `src/underground/the-gaggle.js`
- Create: `src/underground/boiler-room.js`

- [ ] **Step 1: Create `src/underground/base-department.js`**

```js
// src/underground/base-department.js
import { Figure } from '../surface/figure.js';

export class BaseDepartment {
  container;
  #figures = [];
  #label;

  /**
   * @param {object} opts
   * @param {number} opts.x      Left edge of room (underground stage coords)
   * @param {number} opts.y      Top edge
   * @param {number} opts.w      Width
   * @param {number} opts.h      Height
   * @param {string} opts.label  Department name (shown on sign)
   * @param {string} opts.colour Hex for border/sign colour
   * @param {string} opts.bg     Hex for background fill
   */
  constructor({ x, y, w, h, label, colour = 0x3a6a3a, bg = 0x0e1a0e }) {
    this.x = x; this.y = y; this.w = w; this.h = h;
    this.#label = label;
    this.container = new PIXI.Container();
    this.container.x = x;
    this.container.y = y;
    this.#drawRoom(w, h, colour, bg, label);
  }

  #drawRoom(w, h, colour, bg, label) {
    const g = new PIXI.Graphics();
    g.rect(0, 0, w, h).fill(bg);
    g.rect(0, 0, w, h).stroke({ color: colour, width: 1.5 });
    this.container.addChild(g);

    const sign = new PIXI.Text({
      text: label,
      style: { fontSize: 9, fill: colour, fontFamily: 'monospace', fontWeight: 'bold' },
    });
    sign.x = 6;
    sign.y = 6;
    this.container.addChild(sign);
  }

  /** Add a figure at local coords (relative to room). */
  addFigure(lx, ly, colour) {
    const fig = new Figure(colour);
    fig.sprite.x = lx;
    fig.sprite.y = ly;
    this.container.addChild(fig.sprite);
    this.#figures.push(fig);
    return fig;
  }

  update(dt) {
    this.#figures.forEach(f => f.update(dt));
    this.onUpdate(dt);
  }

  /** Override in subclasses for department-specific behaviour. */
  onUpdate(dt) {}
}
```

- [ ] **Step 2: Create all seven department files**

**`src/underground/sorting-office.js`:**

```js
// src/underground/sorting-office.js
import { BaseDepartment } from './base-department.js';
import { SpeechBubble } from '../surface/speech-bubble.js';

const INSPECT_LINES = [
  'Looks legit. Carry on. ✅',
  'Nothing suspicious here.',
  'Hmm, smells fishy… 🐟',
  'Clean as a whistle!',
];

export class SortingOffice extends BaseDepartment {
  #inspector;
  #fig2;
  #busy = false;
  #time = 0;

  constructor(underground, tubeNetwork) {
    super({ x: 50, y: 110, w: 150, h: 80,
            label: '🔍 Sorting Office', colour: 0x3a6a2a, bg: 0x0e1a0c });
    this.#inspector = this.addFigure(20, 50, 0xaef0b0);
    this.#fig2 = this.addFigure(110, 50, 0xaef0b0);
    this.#underground = underground;
    this.#tubeNetwork = tubeNetwork;
  }

  #underground; #tubeNetwork;

  trigger(eventData, world) {
    if (this.#busy) return;
    this.#busy = true;

    // Inspector walks to inlet
    this.#inspector.walkTo(10, 40, () => {
      // Short pause — 'inspecting'
      setTimeout(() => {
        const line = INSPECT_LINES[Math.floor(Math.random() * INSPECT_LINES.length)];
        // Show bubble in underground
        const domPos = world.worldToDom(this.container.x + 75, this.underground_y + this.container.y + 20);
        SpeechBubble.show(line, domPos.x, domPos.y, 'underground');

        // Walk back to desk
        this.#inspector.walkTo(20, 50, () => { this.#busy = false; });
      }, 600);
    });
  }

  onUpdate(dt) {
    this.#time += dt;
    // Idle: figure 2 slowly paces
    if (this.#fig2.isIdle) {
      const tx = 80 + 40 * Math.sign(Math.sin(this.#time * 0.5));
      this.#fig2.walkTo(tx, 50);
    }
  }
}
```

**`src/underground/spam-dungeon.js`:**

```js
// src/underground/spam-dungeon.js
import { BaseDepartment } from './base-department.js';
import { SpeechBubble } from '../surface/speech-bubble.js';

export class SpamDungeon extends BaseDepartment {
  #worker;
  #pile;
  #pileCount = 0;

  constructor() {
    super({ x: 50, y: 230, w: 120, h: 70,
            label: '🚫 Spam Dungeon', colour: 0x6a2a0a, bg: 0x140a04 });
    this.#worker = this.addFigure(20, 40, 0xf0aa66);
    this.#pile = new PIXI.Container();
    this.#pile.x = 70;
    this.#pile.y = 55;
    this.container.addChild(this.#pile);
  }

  trigger(world) {
    // Worker walks to hatch, drops capsule in, pile grows
    this.#worker.walkTo(90, 40, () => {
      this.#addToPile();
      this.#worker.walkTo(20, 40);
    });
  }

  #addToPile() {
    const g = new PIXI.Graphics()
      .roundRect(-8, -3, 16, 6, 2)
      .fill(0xcc4400);
    g.x = (this.#pileCount % 3) * 10 - 10;
    g.y = -Math.floor(this.#pileCount / 3) * 5;
    this.#pile.addChild(g);
    this.#pileCount++;
  }

  onUpdate(dt) {
    if (this.#worker.isIdle) this.#worker.walkTo(20, 40);
  }
}
```

**`src/underground/committee.js`:**

```js
// src/underground/committee.js
import { BaseDepartment } from './base-department.js';
import { SpeechBubble } from '../surface/speech-bubble.js';

const DECISIONS = ['Quorum achieved. ✅', 'On the board it goes!', 'Approved — unanimously!'];

export class Committee extends BaseDepartment {
  #fig1; #fig2;
  #busy = false;

  constructor() {
    super({ x: 220, y: 110, w: 140, h: 80,
            label: '✅ Committee for\nDeciding Things', colour: 0x2a5a4a, bg: 0x0a1614 });
    this.#fig1 = this.addFigure(20, 50, 0x4dc8a0);
    this.#fig2 = this.addFigure(100, 50, 0x4dc8a0);
  }

  trigger(world) {
    if (this.#busy) return;
    this.#busy = true;
    this.#fig1.walkTo(60, 50, () => {
      const line = DECISIONS[Math.floor(Math.random() * DECISIONS.length)];
      const pos = world.worldToDom(this.x + 70, UNDERGROUND_Y + this.y + 20);
      SpeechBubble.show(line, pos.x, pos.y, 'underground');
      this.#fig1.walkTo(20, 50, () => { this.#busy = false; });
    });
  }

  onUpdate(dt) {
    if (this.#fig2.isIdle) this.#fig2.walkTo(80 + Math.random() * 40, 50);
  }
}
```

**`src/underground/chitchat-cafe.js`:**

```js
// src/underground/chitchat-cafe.js
import { BaseDepartment } from './base-department.js';
import { SpeechBubble } from '../surface/speech-bubble.js';

const CAFE_LINES = [
  '☕ Tea?', 'Anyone else think this biscuit is stale?',
  'Nice weather… wherever that is.', 'Shall I put the kettle on?',
];

export class ChitchatCafe extends BaseDepartment {
  #sitter1; #sitter2; #time = 0;

  constructor() {
    super({ x: 220, y: 230, w: 120, h: 70,
            label: '☕ ChitChat Café', colour: 0x5a5a2a, bg: 0x0e0e08 });
    this.#sitter1 = this.addFigure(25, 45, 0xddddaa);
    this.#sitter2 = this.addFigure(80, 45, 0xddddaa);
  }

  trigger(world) {
    const line = CAFE_LINES[Math.floor(Math.random() * CAFE_LINES.length)];
    const pos = world.worldToDom(this.x + 60, UNDERGROUND_Y + this.y + 15);
    SpeechBubble.show(line, pos.x, pos.y, 'underground');
  }

  onUpdate(dt) {
    this.#time += dt;
    // Figures occasionally swap seats
    if (this.#time > 8 && this.#sitter1.isIdle && this.#sitter2.isIdle) {
      this.#time = 0;
      this.#sitter1.walkTo(80, 45);
      this.#sitter2.walkTo(25, 45);
    }
  }
}
```

**`src/underground/the-tube-dept.js`:**

```js
// src/underground/the-tube-dept.js
import { BaseDepartment } from './base-department.js';
import { SpeechBubble } from '../surface/speech-bubble.js';

export class TheTubeDept extends BaseDepartment {
  #operator; #busy = false;

  constructor() {
    super({ x: 370, y: 110, w: 110, h: 75,
            label: '📮 The Tube', colour: 0x2a5a7a, bg: 0x0a1010 });
    this.#operator = this.addFigure(25, 45, 0x88ccff);
    // Draw lever
    const lever = new PIXI.Graphics()
      .rect(80, 25, 4, 30).fill(0x2a5a6a)
      .circle(82, 25, 6).fill(0x4a8aaa);
    this.container.addChild(lever);
  }

  trigger(world) {
    if (this.#busy) return;
    this.#busy = true;
    this.#operator.walkTo(78, 38, () => {
      SpeechBubble.show("Someone's interested! 💌", ...Object.values(world.worldToDom(this.x + 55, UNDERGROUND_Y + this.y + 10)), 'underground');
      this.#operator.walkTo(25, 45, () => { this.#busy = false; });
    });
  }
  onUpdate() {}
}
```

**`src/underground/the-gaggle.js`:**

```js
// src/underground/the-gaggle.js
import { BaseDepartment } from './base-department.js';
import { SpeechBubble } from '../surface/speech-bubble.js';

const WELCOME_LINES = ['Welcome! Have a seat. 👋', "You'll get the hang of it.", 'Any questions, just ask!'];

export class TheGaggle extends BaseDepartment {
  #senior; #fig2; #fig3; #time = 0;

  constructor() {
    super({ x: 500, y: 160, w: 120, h: 75,
            label: '🎓 The Gaggle', colour: 0x7a9a3a, bg: 0x0e160a });
    this.#senior = this.addFigure(20, 45, 0xaacc66);
    this.#fig2 = this.addFigure(60, 45, 0xaacc66);
    this.#fig3 = this.addFigure(95, 45, 0xaacc66);
  }

  trigger(world) {
    const line = WELCOME_LINES[Math.floor(Math.random() * WELCOME_LINES.length)];
    SpeechBubble.show(line, ...Object.values(world.worldToDom(this.x + 60, UNDERGROUND_Y + this.y + 10)), 'underground');
    // Senior waves (walks to centre and back)
    this.#senior.walkTo(50, 40, () => this.#senior.walkTo(20, 45));
  }

  onUpdate(dt) {
    this.#time += dt;
    if (this.#time > 6 && this.#fig2.isIdle) {
      this.#time = 0;
      this.#fig2.walkTo(80, 45);
      this.#fig3.walkTo(50, 45);
    }
  }
}
```

**`src/underground/boiler-room.js`:**

```js
// src/underground/boiler-room.js
import { BaseDepartment } from './base-department.js';
import { SpeechBubble } from '../surface/speech-bubble.js';

const SNARKY = [
  'We could do that if we had some funding.',
  'I could fix this if I had a team.',
  'Have you tried turning it off and on again?',
  "It's not a bug. It's a feature.",
  'This would take 5 minutes… to explain why it takes 3 months.',
  'Have you considered: not breaking it?',
];

export class BoilerRoom extends BaseDepartment {
  #geek; #circuit = 0; #time = 0; #bubbleTimer = 0;
  #WAYPOINTS = [
    { x: 20, y: 45 }, { x: 80, y: 45 }, { x: 80, y: 25 }, { x: 40, y: 25 }, { x: 20, y: 45 },
  ];

  constructor() {
    super({ x: 630, y: 160, w: 110, h: 75,
            label: '⚙️ Boiler Room', colour: 0x7a5aaa, bg: 0x0e0e18 });
    this.#geek = this.addFigure(20, 45, 0xcc99ff);
    // Decorative pipes
    const pipes = new PIXI.Graphics()
      .rect(90, 5, 8, 50).fill(0x2a1a3a)
      .rect(95, 30, 15, 6).fill(0x3a2a4a)
      .circle(92, 35, 5).fill(0x4a3a6a);
    this.container.addChild(pipes);
  }

  onUpdate(dt) {
    this.#time += dt;
    this.#bubbleTimer += dt;

    // Walk circuit
    if (this.#geek.isIdle) {
      const wp = this.#WAYPOINTS[this.#circuit % this.#WAYPOINTS.length];
      this.#geek.walkTo(wp.x, wp.y);
      this.#circuit++;
    }

    // Snarky bubble every 20–40s
    if (this.#bubbleTimer > 20 + Math.random() * 20) {
      this.#bubbleTimer = 0;
      // Bubble is positioned when this fires — use stored world reference
      if (this.#worldRef) {
        const line = SNARKY[Math.floor(Math.random() * SNARKY.length)];
        SpeechBubble.show(line, ...Object.values(this.#worldRef.worldToDom(this.x + 55, UNDERGROUND_Y + this.y + 15)), 'underground');
      }
    }
  }

  #worldRef = null;
  setWorldRef(world) { this.#worldRef = world; }
}
```

Note: `UNDERGROUND_Y` constant (the Y offset of the underground stage in world coordinates) needs to be imported in department files. Add to the top of each department file:

```js
import { UNDERGROUND_Y_OFFSET as UNDERGROUND_Y } from './underground-stage.js';
```

- [ ] **Step 3: Register all departments in `main.js`**

```js
import { SortingOffice } from './underground/sorting-office.js';
import { SpamDungeon } from './underground/spam-dungeon.js';
import { Committee } from './underground/committee.js';
import { ChitchatCafe } from './underground/chitchat-cafe.js';
import { TheTubeDept } from './underground/the-tube-dept.js';
import { TheGaggle } from './underground/the-gaggle.js';
import { BoilerRoom } from './underground/boiler-room.js';

const sortingOffice = new SortingOffice();
const spamDungeon = new SpamDungeon();
const committee = new Committee();
const chitchat = new ChitchatCafe();
const tubeDept = new TheTubeDept();
const gaggle = new TheGaggle();
const boiler = new BoilerRoom();
boiler.setWorldRef(world);

[sortingOffice, spamDungeon, committee, chitchat, tubeDept, gaggle, boiler]
  .forEach(d => underground.addDepartment(d));
```

- [ ] **Step 4: Verify visually**

Zoom out to show underground. Check all 7 department rooms visible with labels. Figures visible in each room. Boiler Room figure walking its circuit.

- [ ] **Step 5: Commit**

```bash
git add src/underground/*.js
git commit -m "feat: all seven underground departments with moving figures"
```

---

## Task 14: Wire simulator to city

**Files:**
- Modify: `src/main.js`

Connect `CitySimulator` events to surface speech bubbles, underground department triggers, and bin lorry spawning.

- [ ] **Step 1: Load `data.json` and start simulator**

```js
// In main(), after all stages are set up:
const dataResp = await fetch('./data/data.json');
const cityData = await dataResp.json();

import { CitySimulator } from './city-simulator.js';
import { GreenScore } from './greenness.js';
import { SpeechBubble } from './surface/speech-bubble.js';
import { worldToScreen } from './isometric.js';

const greenScore = new GreenScore();
const sim = new CitySimulator(cityData, bus);

// Update score badge
const badge = document.getElementById('score-badge');
const updateBadge = () => { badge.textContent = `🌱 ${greenScore.score} items saved from landfill`; };

// Surface speech bubbles from sim events
const HOUSE_POSITIONS = [
  { wx: 4, wy: 4 }, { wx: 7, wy: 3 }, { wx: 10, wy: 5 },
  { wx: 6, wy: 10 }, { wx: 26, wy: 4 }, { wx: 28, wy: 11 },
  { wx: 4, wy: 26 }, { wx: 8, wy: 29 },
];

function randomHousePos() {
  const h = HOUSE_POSITIONS[Math.floor(Math.random() * HOUSE_POSITIONS.length)];
  const s = worldToScreen(h.wx, h.wy, 2, 64, 32); // z=2 = above roof
  return world.worldToDom(s.x, s.y);
}

bus.on('offer_posted', ({ item }) => {
  const pos = randomHousePos();
  SpeechBubble.show(`📦 "${item} — free!"<br><small>just now</small>`, pos.x, pos.y);
});

bus.on('wanted_posted', ({ item }) => {
  const pos = randomHousePos();
  SpeechBubble.show(`🔍 "Anyone have a ${item}?"<br><small>just now</small>`, pos.x, pos.y);
});

bus.on('item_given_away', ({ item }) => {
  greenScore.increment();
  updateBadge();
  const pos = randomHousePos();
  SpeechBubble.show(`✅ "${item} found a new home! 🎉"`, pos.x, pos.y);
});

bus.on('chat_message', () => {
  const pos = randomHousePos();
  SpeechBubble.show('💬 "Is it still available?"', pos.x, pos.y);
});

bus.on('free_shop_drop', ({ item }) => {
  const s = worldToScreen(13, 7, 1, 64, 32);
  const pos = world.worldToDom(s.x, s.y);
  SpeechBubble.show(`🛍️ "Leaving this ${item} here for someone!"`, pos.x, pos.y);
});

bus.on('free_shop_pick', ({ item }) => {
  const s = worldToScreen(13, 7, 1, 64, 32);
  const pos = world.worldToDom(s.x, s.y);
  SpeechBubble.show(`😊 "Perfect, just what I needed!"`, pos.x, pos.y);
});

bus.on('new_member', () => {
  gaggle.trigger(world);
  const s = worldToScreen(0, 10, 1, 64, 32);
  const pos = world.worldToDom(s.x, s.y);
  SpeechBubble.show('👋 "Hello Thornwick Lane!"', pos.x, pos.y);
});

bus.on('offer_posted', () => sortingOffice.trigger({}, world));
bus.on('wanted_posted', () => sortingOffice.trigger({}, world));
bus.on('spam_detected', () => spamDungeon.trigger(world));
bus.on('item_given_away', () => { committee.trigger(world); tubeDept.trigger(world); });

// Simulator tick — use real clock hour
app.ticker.add(() => {
  const hour = new Date().getHours();
  sim.tick(hour);
});
```

- [ ] **Step 2: Verify visually**

Reload. Within seconds, speech bubbles appear on houses. Underground departments animate on events. Score badge increments on successes.

- [ ] **Step 3: Commit**

```bash
git add src/main.js
git commit -m "feat: wire simulator events to surface bubbles and underground departments"
```

---

## Task 15: Bin lorry + protesters

**Files:**
- Create: `src/surface/bin-lorry.js`
- Modify: `src/surface/surface-stage.js`

- [ ] **Step 1: Create `src/surface/bin-lorry.js`**

```js
// src/surface/bin-lorry.js
import { worldToScreen } from '../isometric.js';
import { SpeechBubble } from './speech-bubble.js';

const PLACARDS = ['BOO! 👎', 'CAREFUL NOW!', 'DOWN WITH THIS\nSORT OF THING'];
const TILE_W = 64, TILE_H = 32;

export class BinLorry {
  container;
  #done = false;

  constructor(world, startWY = 19) {
    this.container = new PIXI.Container();

    // Lorry body (isometric box — simple coloured rect for side-on view)
    const lorry = new PIXI.Graphics();
    lorry.rect(0, -22, 60, 22).fill(0x1a2e1a);       // body
    lorry.rect(0, -22, 18, 22).fill(0x243e24);        // cab
    lorry.rect(2, -20, 13, 10).fill(0x0a1a0a);        // cab window
    lorry.rect(20, -8, 38, 6).fill(0x1e3a1e);         // Council text band
    lorry.circle(10, 2, 5).fill(0x0a0a0a).stroke({ color: 0x3a4a3a, width: 1 });
    lorry.circle(48, 2, 5).fill(0x0a0a0a).stroke({ color: 0x3a4a3a, width: 1 });
    const label = new PIXI.Text({ text: '🗑️ COUNCIL\nWASTE SERVICES',
      style: { fontSize: 6, fill: 0x3a6a3a, fontFamily: 'monospace' }});
    label.x = 22; label.y = -7;
    this.container.addChild(lorry, label);

    // 3 protester figures with placards
    [-30, -55, -80].forEach((offset, i) => {
      const fig = new PIXI.Graphics().circle(0, 0, 5).fill(0xf9aabb);
      fig.x = offset;
      fig.y = -8;
      const stick = new PIXI.Graphics().rect(-1, -22, 2, 20).fill(0x888888);
      const sign = new PIXI.Graphics().rect(-16, -38, 32, 18).fill(0x1a1a0a)
        .rect(-16, -38, 32, 18).stroke({ color: 0xaaaa44, width: 1 });
      const txt = new PIXI.Text({ text: PLACARDS[i],
        style: { fontSize: 5.5, fill: 0xffee44, fontFamily: 'monospace', align: 'center' }});
      txt.anchor.set(0.5, 1); txt.x = 0; txt.y = -22;
      const group = new PIXI.Container();
      group.addChild(fig, stick, sign, txt);
      group.x = offset; group.y = -8;
      this.container.addChild(group);
    });

    // Start position: left edge, at road level
    const start = worldToScreen(-8, startWY, 0, TILE_W, TILE_H);
    this.container.x = start.x;
    this.container.y = start.y - 5;
  }

  get isDone() { return this.#done; }

  update(dt) {
    this.container.x += 60 * dt; // drive right
    if (this.container.x > 2500) this.#done = true;
  }
}
```

- [ ] **Step 2: Add bin lorry spawner to `surface-stage.js`**

```js
import { BinLorry } from './bin-lorry.js';

// In SurfaceStage:
#lorries = [];
#lorryTimer = 90 + Math.random() * 90; // seconds until first spawn
#lorryInterval = 90;

updateWithGreen(dt, greenScore) {
  // Existing figure update
  this.#figures.forEach(({ fig }) => fig.update(dt));

  // Lorry spawning
  this.#lorryTimer -= dt;
  if (this.#lorryTimer <= 0) {
    const interval = greenScore > 300 ? 300 + Math.random() * 180 : 90 + Math.random() * 90;
    this.#lorryTimer = interval;
    const lorry = new BinLorry(null, 19);
    this.container.addChild(lorry.container);
    this.#lorries.push(lorry);
  }

  this.#lorries = this.#lorries.filter(l => {
    l.update(dt);
    if (l.isDone) { this.container.removeChild(l.container); return false; }
    return true;
  });
}
```

- [ ] **Step 3: Update `main.js` call from `surface.update(dt)` to `surface.updateWithGreen(dt, greenScore.score)`**

- [ ] **Step 4: Verify visually**

Reload. Within ~2 minutes a bin lorry trundles through with placards. Disappears off right edge.

- [ ] **Step 5: Commit**

```bash
git add src/surface/bin-lorry.js src/surface/surface-stage.js src/main.js
git commit -m "feat: bin lorry with protester figures and placards"
```

---

## Task 16: Greenness unlocks + smog overlay

**Files:**
- Modify: `src/main.js`
- Modify: `src/surface/surface-stage.js`

- [ ] **Step 1: Add smog overlay to `surface-stage.js`**

```js
// In SurfaceStage constructor, after grid:
this.#smog = new PIXI.Graphics().rect(-2000, -2000, 6000, 6000).fill({ color: 0x223322, alpha: 0.3 });
this.container.addChild(this.#smog);

#smog;
setSmogAlpha(alpha) { this.#smog.alpha = alpha; }
```

- [ ] **Step 2: Register threshold callbacks in `main.js`**

```js
greenScore.onThreshold(50, () => {
  surface.unlockBuildings(50);
  surface.setSmogAlpha(Math.max(0, 0.3 - 50/300 * 0.3));
  SpeechBubble.show('🌳 Trees planted! Air getting cleaner…', window.innerWidth/2, 80);
});
greenScore.onThreshold(150, () => {
  surface.unlockBuildings(150);
  SpeechBubble.show('🔧 Repair Café now open! Bring your broken things.', window.innerWidth/2, 80);
});
greenScore.onThreshold(300, () => {
  surface.unlockBuildings(300);
  SpeechBubble.show('♻️ Zero Waste Shop opens its doors!', window.innerWidth/2, 80);
});
greenScore.onThreshold(500, () => {
  surface.unlockBuildings(500);
  SpeechBubble.show('🛍️ Charity Shop is open! The city is thriving! 🌿', window.innerWidth/2, 80);
});

// Update smog every frame from score
app.ticker.add(() => { surface.setSmogAlpha(greenScore.smogAlpha); });
```

- [ ] **Step 3: Verify visually**

With a fresh `localStorage`, run city. Score increments on success events. At 50, smog lightens and tree buildings appear. (To test quickly: open DevTools console and run `localStorage.setItem('freegleCity_greenScore', '49')` then reload, watch the 50th success event fire the threshold callback.)

- [ ] **Step 4: Commit**

```bash
git add src/main.js src/surface/surface-stage.js
git commit -m "feat: greenness unlocks, smog overlay fades with score"
```

---

## Task 17: Minimap

**Files:**
- Create: `src/minimap.js`
- Modify: `src/main.js`

- [ ] **Step 1: Create `src/minimap.js`**

```js
// src/minimap.js
// Renders a tiny overview of the full world onto a separate <canvas>.
// Draws coloured zone rectangles to represent neighbourhoods.
// Draws a dim rectangle representing the current viewport.

const ZONE_MAP = [
  // {x, y, w, h, colour} in minimap pixel coords (150×100 → world 40×40)
  { x: 0,   y: 0,  w: 42, h: 42, colour: '#1a3020' }, // Thornwick
  { x: 57,  y: 0,  w: 43, h: 42, colour: '#1e3820' }, // Brackenfield
  { x: 0,   y: 57, w: 42, h: 43, colour: '#182e1a' }, // Puddleby
  { x: 57,  y: 57, w: 43, h: 43, colour: '#1e4022' }, // Park
  { x: 43,  y: 0,  w: 14, h: 100, colour: '#141e10' }, // V-road
  { x: 0,   y: 43, w: 150, h: 14, colour: '#141e10' }, // H-road
];

export class Minimap {
  #canvas;
  #ctx;
  #world;
  #app;

  constructor(world, app) {
    this.#canvas = document.getElementById('minimap-canvas');
    this.#ctx = this.#canvas.getContext('2d');
    this.#world = world;
    this.#app = app;

    this.#canvas.addEventListener('click', (e) => {
      const rect = this.#canvas.getBoundingClientRect();
      const mx = (e.clientX - rect.left) / 150;
      const my = (e.clientY - rect.top) / 100;
      // Map minimap click to world centre
      world.container.x = app.screen.width / 2 - mx * 2560 * world.zoom;
      world.container.y = app.screen.height / 2 - my * 1280 * world.zoom;
    });
  }

  update() {
    const ctx = this.#ctx;
    ctx.clearRect(0, 0, 150, 100);

    // Draw zone blocks
    for (const z of ZONE_MAP) {
      ctx.fillStyle = z.colour;
      ctx.fillRect(z.x, z.y, z.w, z.h);
    }

    // Draw viewport rect
    const w = this.#world;
    const scaleX = 150 / 2560;
    const scaleY = 100 / 1280;
    const vpX = (-w.container.x / w.zoom) * scaleX;
    const vpY = (-w.container.y / w.zoom) * scaleY;
    const vpW = (this.#app.screen.width / w.zoom) * scaleX;
    const vpH = (this.#app.screen.height / w.zoom) * scaleY;
    ctx.strokeStyle = 'rgba(100,200,100,0.7)';
    ctx.lineWidth = 1;
    ctx.strokeRect(vpX, vpY, vpW, vpH);
  }
}
```

- [ ] **Step 2: Add to `main.js`**

```js
import { Minimap } from './minimap.js';
const minimap = new Minimap(world, app);
app.ticker.add(() => minimap.update());
```

- [ ] **Step 3: Verify visually**

Reload. Minimap in top-right shows zone layout and a green viewport rectangle. Panning/zooming moves the viewport rect. Clicking minimap jumps the view.

- [ ] **Step 4: Commit**

```bash
git add src/minimap.js src/main.js
git commit -m "feat: minimap with clickable viewport navigation"
```

---

## Task 18: Data export script

**Files:**
- Create: `scripts/export-data.js`

Runs in Node.js, connects to the Freegle DB via the existing read-only SQL tunnel, writes `data/data.json`.

- [ ] **Step 1: Create `scripts/export-data.js`**

```js
// scripts/export-data.js
// Run: node scripts/export-data.js
// Requires: DB tunnel on localhost:3306 (or set DB_HOST/DB_PORT/DB_USER/DB_PASS/DB_NAME env vars)

import mysql from 'mysql2/promise';
import { writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dir = dirname(fileURLToPath(import.meta.url));

const conn = await mysql.createConnection({
  host:     process.env.DB_HOST || '127.0.0.1',
  port:     Number(process.env.DB_PORT || 3306),
  user:     process.env.DB_USER || 'readonly',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'iznik',
});

// Top 200 item names from successful offers in last 90 days
const [itemRows] = await conn.execute(`
  SELECT m.subject
  FROM messages m
  WHERE m.type = 'Offer'
    AND m.arrival > DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND (m.outcomes IS NULL OR m.outcomes NOT LIKE '%Withdrawn%')
  ORDER BY m.arrival DESC
  LIMIT 2000
`);

// Clean and deduplicate item names
const rawItems = itemRows.map(r => r.subject.trim()).filter(Boolean);
const items = [...new Set(rawItems)].slice(0, 200);

// Activity ratios from last 30 days
const [typeRows] = await conn.execute(`
  SELECT
    SUM(type = 'Offer') AS offers,
    SUM(type = 'Wanted') AS wanteds,
    COUNT(*) AS total
  FROM messages
  WHERE arrival > DATE_SUB(NOW(), INTERVAL 30 DAY)
`);
const { offers, wanteds, total } = typeRows[0];
const offerRatio  = offers / total;
const wantedRatio = wanteds / total;

// Time-of-day activity curve (24 buckets)
const [hourRows] = await conn.execute(`
  SELECT HOUR(arrival) AS h, COUNT(*) AS cnt
  FROM messages
  WHERE arrival > DATE_SUB(NOW(), INTERVAL 30 DAY)
  GROUP BY h
  ORDER BY h
`);
const rawCurve = Array(24).fill(0);
hourRows.forEach(r => { rawCurve[r.h] = Number(r.cnt); });
const maxHour = Math.max(...rawCurve);
const timeCurve = rawCurve.map(v => Math.round((v / maxHour) * 100) / 100);

// Typical throughput
const [tpRows] = await conn.execute(`
  SELECT COUNT(*) / 720 AS per_hour
  FROM messages
  WHERE arrival > DATE_SUB(NOW(), INTERVAL 30 DAY)
`);
const throughputPerHour = Math.round(tpRows[0].per_hour);

await conn.end();

const data = {
  items,
  ratios: {
    offer:          Math.round(offerRatio * 100) / 100,
    wanted:         Math.round(wantedRatio * 100) / 100,
    chat:           0.13,
    spam:           0.06,
    success:        0.15,
    new_member:     0.02,
    free_shop_drop: 0.08,
    free_shop_pick: 0.08,
  },
  timeCurve,
  throughputPerHour,
};

const outPath = join(__dir, '../data/data.json');
writeFileSync(outPath, JSON.stringify(data, null, 2));
console.log(`✅ Written ${items.length} items, throughput ${throughputPerHour}/hr → ${outPath}`);
```

- [ ] **Step 2: Add `package.json` for the script's dependency**

```bash
cat > package.json << 'EOF'
{
  "name": "freegle-city",
  "type": "module",
  "scripts": {
    "export-data": "node scripts/export-data.js",
    "serve": "python3 -m http.server 8080"
  },
  "dependencies": {
    "mysql2": "^3.6.0"
  }
}
EOF
npm install
echo "node_modules/" >> .gitignore
```

- [ ] **Step 3: Test with example data (no real DB needed)**

```bash
# Verify the script at least parses and runs with fake env vars
# (will fail on connection, but should not throw JS errors)
DB_HOST=127.0.0.1 DB_PORT=9999 node scripts/export-data.js 2>&1 | grep -v "ECONNREFUSED" || true
```

Expected: Connection refused error (no real DB) — no JS syntax errors.

- [ ] **Step 4: Commit**

```bash
git add scripts/export-data.js package.json .gitignore
git commit -m "feat: data export script — DB → data.json via mysql2"
```

---

## Task 19: README + GitHub Pages setup

**Files:**
- Create: `README.md`
- Create: `.github/workflows/pages.yml`

- [ ] **Step 1: Write `README.md`**

```markdown
# Freegle City

An isometric city visualisation of Freegle activity. A fictional British city
(Thornwick-on-Freegle) runs above ground; a pneumatic tube network operates below.
Powered by a procedural simulation engine seeded from real Freegle data.

## Running locally

```bash
npm run serve   # serves on http://localhost:8080
```

## Refreshing data.json

Requires a read-only SQL tunnel to the Freegle DB on localhost:3306.

```bash
npm install
DB_USER=readonly DB_PASS=xxx DB_NAME=iznik npm run export-data
git add data/data.json && git commit -m "chore: refresh city data"
```

## Sprites

Uses [Kenney.nl Isometric City Kit](https://kenney.nl/assets/isometric-city-kit) and
[Kenney Isometric Characters](https://kenney.nl/assets/isometric-characters) (CC0 licence).
Download and extract to `sprites/kenney-isometric-city/` and `sprites/kenney-characters/`.

## Licence

MIT. Sprite assets: CC0 (Kenney.nl).
```

- [ ] **Step 2: Create GitHub Pages workflow**

```bash
mkdir -p .github/workflows
cat > .github/workflows/pages.yml << 'EOF'
name: Deploy to GitHub Pages
on:
  push:
    branches: [main]
permissions:
  contents: read
  pages: write
  id-token: write
jobs:
  deploy:
    runs-on: ubuntu-latest
    environment:
      name: github-pages
      url: ${{ steps.deployment.outputs.page_url }}
    steps:
      - uses: actions/checkout@v4
      - uses: actions/configure-pages@v4
      - uses: actions/upload-pages-artifact@v3
        with:
          path: '.'
      - uses: actions/deploy-pages@v4
        id: deployment
EOF
```

- [ ] **Step 3: Commit and push**

```bash
git add README.md .github/
git commit -m "chore: README and GitHub Pages deployment workflow"
```

When the Freegle org repo is created (`gh repo create Freegle/freegle-city --public`) and this branch pushed to it, GitHub Pages will auto-deploy.

---

## Self-Review

**Spec coverage check:**
- ✅ Standalone repo (`Freegle/freegle-city`) at `/home/edward/freegle-city/` — Task 1
- ✅ PixiJS v8, no build step, CDN — Task 1
- ✅ `data.json` seeded from DB, no runtime API calls — Tasks 1, 18
- ✅ Isometric coordinate system — Task 2
- ✅ Zoom + pan WorldContainer — Task 6
- ✅ Isometric tile grid, three neighbourhoods — Task 7
- ✅ Buildings with 3D box rendering, unlock thresholds — Task 8
- ✅ The Free Shop (container building) — Task 8 (container theme)
- ✅ Walking figures with waypoint system — Task 9
- ✅ Speech bubbles (HTML overlays) — Task 10
- ✅ Ground band + tube entry holes — Task 11
- ✅ Underground stage, cross-section — Task 12
- ✅ Tube network with capsule animation — Task 12
- ✅ Capsule colours: yellow/blue/red/green — Task 12
- ✅ All 7 departments with moving figures — Tasks 13
- ✅ Sorting Office, Spam Dungeon, Committee, ChitChat Café, The Tube, The Gaggle, Boiler Room — Task 13
- ✅ Boiler Room snarky speech bubbles on independent timer — Task 13
- ✅ CitySimulator with time-of-day curve — Task 4
- ✅ GreenScore with localStorage and threshold callbacks — Task 5
- ✅ Greenness unlocks buildings and lifts smog — Task 16
- ✅ Bin lorry with protesters + placards — Task 15
- ✅ Score badge: "N items saved from landfill" — Task 14
- ✅ Minimap with viewport rect and click-to-navigate — Task 17
- ✅ GitHub Pages auto-deploy — Task 19
- ✅ DB export script — Task 18

**Gaps found and addressed:**
- Free Shop delivery animation: a flatbed lorry drives in, lowers the container (Freegle logo stencilled on side), drives away, then door swings open and activity begins. This is implemented in `src/surface/free-shop.js` (listed in file map) as a one-shot intro sequence triggered on first load. Task 8 defines the building position; `free-shop.js` owns the delivery animation. **Add Task 8b below to implement this.**
- Repair Café / Zero Waste Shop / Charity Shop: appear via `unlockBuildings(score)` at thresholds 150/300/500 respectively — covered in Tasks 8 and 16.
- `UNDERGROUND_Y` constant: used in department files but defined in `underground-stage.js` — each department file imports it. Consistent across all dept files.
