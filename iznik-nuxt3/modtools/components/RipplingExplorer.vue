<template>
  <div
    id="rippling-root"
    :class="{ 'rpl-minimal': minimal }"
    style="position: relative; width: 100%; height: 100%"
  >
    <div id="rippling-map" style="position: absolute; inset: 0"></div>

    <div id="rippling-panel">
      <div id="rippling-panel-header">
        <span>🗺</span>
        Rippling Out Explorer
      </div>
      <div id="rippling-panel-body">
        <div
          id="rippling-view-mode"
          class="rpl-mode-row"
          style="margin-bottom: 8px"
        >
          <button class="rpl-mode-btn rpl-active" data-view="outbound">
            <span class="rpl-icon">📡</span
            ><span class="rpl-mode-label">Who could see my post</span>
          </button>
          <button class="rpl-mode-btn" data-view="inbound">
            <span class="rpl-icon">📥</span
            ><span class="rpl-mode-label">Digest preview</span>
          </button>
          <button class="rpl-mode-btn" data-view="catchment">
            <span class="rpl-icon">🎯</span
            ><span class="rpl-mode-label">Group catchment</span>
          </button>
        </div>

        <div id="rippling-search-wrap">
          <input
            id="rippling-search-box"
            type="text"
            placeholder="Search UK location…"
            autocomplete="off"
          />
          <ul id="rippling-search-results"></ul>
        </div>

        <div id="rippling-intro-outbound" class="rpl-intro">
          Drop a marker. The map shows how a post made there would ripple out:
          who is eligible to see this post, in what order, and how fast the wave
          spreads.
        </div>
        <div
          id="rippling-intro-inbound"
          class="rpl-intro"
          style="display: none"
        >
          Drop a marker. The map shows what would appear in a digest sent to a
          member at that spot — every post within their reach (the radius below)
          over the last 24 hours, in the order set by the sliders further down.
        </div>
        <div
          id="rippling-intro-catchment"
          class="rpl-intro"
          style="display: none"
        >
          Pick a group. The map shows the group's own area (blue) and — outside it
          — the catchment (green): the area from which posts could in theory ripple
          IN to that group. Toggle connectivity to see how the transport-connectivity
          model reshapes that catchment.
        </div>
        <div
          id="rippling-catchment-panel"
          class="rpl-sim-group"
          style="display: none"
        >
          <div class="rpl-sim-group-title">Group catchment</div>
          <label
            class="rpl-slider-label"
            style="display: block; margin-bottom: 8px"
          >
            <span>Group</span>
            <input
              id="rippling-catchment-group"
              list="rippling-catchment-grouplist"
              type="text"
              placeholder="Type a group name…"
              autocomplete="off"
              style="width: 100%; margin-top: 2px"
            />
            <datalist id="rippling-catchment-grouplist"></datalist>
          </label>
        </div>

        <!-- Inbound: "What's in the digest" group — controls which posts -->
        <div
          id="rippling-sim-contents"
          class="rpl-sim-group"
          style="display: none"
        >
          <div class="rpl-sim-group-title">What's in the digest</div>
          <div class="rpl-sim-group-sub">
            How far we look for posts. Bigger reach = more posts in the digest.
          </div>
        </div>

        <div id="rippling-time-row" class="rpl-slider-row">
          <div class="rpl-slider-label">
            <span>Maximum reach</span>
          </div>
          <input
            id="rippling-time-slider"
            type="range"
            min="1"
            max="60"
            step="1"
            value="30"
          />
          <div
            style="
              display: flex;
              justify-content: space-between;
              font-size: 10px;
              color: #aaa;
              margin-top: 2px;
            "
          >
            <span>Short</span><span>Long</span>
          </div>
        </div>

        <!-- Inbound: pie chart + counts (the result of "what's in") -->
        <div id="rippling-sim-pie-wrap" style="display: none">
          <div
            style="display: flex; gap: 8px; align-items: center; margin: 6px 0"
          >
            <svg
              id="rippling-pie"
              width="56"
              height="56"
              viewBox="-1 -1 2 2"
              style="flex-shrink: 0; transform: rotate(-90deg)"
            >
              <circle r="1" fill="#eee" />
            </svg>
            <div
              id="rippling-home-summary"
              style="font-size: 11px; color: #555; line-height: 1.4; flex: 1"
            ></div>
          </div>
        </div>

        <!-- Inbound: "What order is it in?" group title — sits OUTSIDE the rail like the other heading, for visual consistency. -->
        <div
          id="rippling-sim-sort-title"
          class="rpl-sim-group"
          style="display: none"
        >
          <div class="rpl-sim-group-title">What order is it in?</div>
          <div class="rpl-sim-group-sub">
            How we rank the posts inside the digest. These don't change what's
            <em>in</em> it, just the order.
          </div>
        </div>

        <div id="rippling-inbound-row" style="display: none">
          <div class="rpl-sim-knob">
            <label>Closeness <span id="rippling-w-close-val">1.0</span></label>
            <input
              id="rippling-w-close"
              type="range"
              min="0"
              max="2"
              step="0.1"
              value="1.0"
            />
            <div class="rpl-sim-help">
              Higher = closer posts go higher in the digest.
            </div>
          </div>
          <div class="rpl-sim-knob">
            <label
              >Eyeballs budget
              <span id="rippling-w-budget-val">1.0</span></label
            >
            <input
              id="rippling-w-budget"
              type="range"
              min="0"
              max="2"
              step="0.1"
              value="1.0"
            />
            <div class="rpl-sim-help">
              Higher = posts few people have viewed yet go higher (spreads
              attention to undersubscribed posts).
            </div>
          </div>
          <div class="rpl-sim-knob">
            <label
              >Home-group anchor
              <span id="rippling-w-anchor-val">0.0</span></label
            >
            <input
              id="rippling-w-anchor"
              type="range"
              min="0"
              max="2"
              step="0.1"
              value="0"
            />
            <div class="rpl-sim-help">
              Higher = posts in the member's home group (the default group for
              their postcode) go higher in the digest.
            </div>
          </div>
          <button id="rippling-show-digest" class="rpl-digest-btn">
            📄 Show digest mock-up
          </button>

          <div
            id="rippling-sim-summary"
            style="
              font-size: 11px;
              color: #555;
              margin-top: 6px;
              line-height: 1.5;
              padding-top: 6px;
              border-top: 1px solid #f0f0f0;
            "
          ></div>
        </div>

        <div class="rpl-slider-row">
          <div class="rpl-slider-label">
            <span>Fairness adjustment</span>
            <span style="display: flex; align-items: center; gap: 6px">
              <button
                id="rippling-balance-btn"
                style="
                  font-size: 10px;
                  padding: 2px 7px;
                  border: 1px solid #61ae24;
                  border-radius: 4px;
                  background: #f2f9e6;
                  color: #4d8b1d;
                  cursor: pointer;
                  white-space: nowrap;
                "
              >
                ⊕ Proportionate
              </button>
              <span class="rpl-val"
                ><span id="rippling-fairness-val">50</span>%</span
              >
            </span>
          </div>
          <input
            id="rippling-fairness-slider"
            type="range"
            min="0"
            max="100"
            step="5"
            value="50"
          />
          <div
            style="
              display: flex;
              justify-content: space-between;
              font-size: 10px;
              color: #aaa;
              margin-top: 2px;
            "
          >
            <span>Distance only</span><span>Strongly favour deprived</span>
          </div>
        </div>

        <div id="rippling-stats"></div>

        <div class="rpl-ripple-row">
          <button id="rippling-btn">▶ Animate ripple</button>
          <span id="rippling-info" class="rpl-ripple-info"
            >by drive · 1–30 min</span
          >
        </div>
        <div class="rpl-slider-row" style="margin-top: 6px; margin-bottom: 4px">
          <div class="rpl-slider-label">
            <span style="font-size: 11px">Animation speed</span>
          </div>
          <div
            style="
              display: flex;
              justify-content: space-between;
              font-size: 10px;
              color: #aaa;
              margin-bottom: 2px;
            "
          >
            <span>Slow</span><span>Fast</span>
          </div>
          <input
            id="rippling-speed-slider"
            type="range"
            min="1"
            max="10"
            step="1"
            value="5"
          />
        </div>
        <div
          id="rippling-freegler-bar"
          style="display: none; font-size: 12px; color: #555; margin: 6px 0 4px"
        >
          &nbsp;
        </div>

        <div
          style="
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 4px;
          "
        >
          <span
            style="
              font-size: 11px;
              font-weight: 600;
              color: #555;
              white-space: nowrap;
            "
            >Show:</span
          >
          <div class="rpl-layer-toggles" style="margin-top: 0">
            <label class="rpl-layer-toggle"
              ><input id="rippling-tog-quintiles" type="checkbox" checked />
              Deprivation</label
            >
            <label class="rpl-layer-toggle"
              ><input id="rippling-tog-freeglers" type="checkbox" checked />
              Freeglers</label
            >
            <label class="rpl-layer-toggle"
              ><input id="rippling-tog-groups" type="checkbox" checked />
              Groups</label
            >
          </div>
        </div>

        <div
          id="rippling-groups-section"
          style="
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #f0f0f0;
            display: none;
          "
        >
          <div
            style="
              font-size: 11px;
              font-weight: 600;
              color: #555;
              margin-bottom: 5px;
            "
          >
            Freegle groups
          </div>
          <div
            id="rippling-groups-list"
            style="font-size: 11px; color: #666; line-height: 1.7"
          ></div>
        </div>
      </div>
    </div>

    <RipplingLegend :mode="legendMode" :minimal="minimal" />

    <!-- Minimal mode: the groups the current reach frame hits (bottom-left, above legend). -->
    <div v-if="minimal" id="rippling-reach-groups" class="rpl-reach-groups" />

    <div id="rippling-status" style="display: none">Loading…</div>

    <div id="rippling-timeline" style="display: none">
      <div id="rippling-tl-elapsed">Just posted</div>
      <div id="rippling-tl-hint">← drag the slider to move through time →</div>
      <div id="rippling-tl-scrub-wrap">
        <input
          id="rippling-tl-slider"
          type="range"
          min="0"
          max="29"
          step="1"
          value="0"
        />
        <div id="rippling-tl-tick-layer"></div>
      </div>
    </div>

    <RipplingDigestModal ref="digestModal" />
  </div>
</template>

<script setup>
import RipplingDigestModal from './RipplingDigestModal.vue'
import RipplingLegend from './RipplingLegend.vue'
import { onMounted, onUnmounted, ref } from '#imports'
import { setupRipplingExplorer } from '~/composables/rippling/setupRipplingExplorer.js'
import './RipplingExplorer.css'

const digestModal = ref(null)
const legendMode = ref('outbound')

const props = defineProps({
  spatialUrl: { type: String, default: 'http://localhost:8196' },
  jwt: { type: String, default: '' },
  // Minimal mode (e.g. embedded in the per-post reach modal): hide the controls
  // panel and legend, leaving just the map, the ripple point and the time scrubber.
  minimal: { type: Boolean, default: false },
  // Seed the ripple at a fixed point and run it straight away, instead of waiting
  // for a map click / search (used by the per-post reach modal).
  initialLat: { type: Number, default: null },
  initialLng: { type: Number, default: null },
  initialView: { type: String, default: null },
  // How long the post has already been live (hours). The seeded reach opens at the
  // matching point on the scrubber (static, no animation): the EXPECTED point ("up to").
  initialElapsedHours: { type: Number, default: null },
  // The ACTUAL reach point (elapsed-hours equivalent), shown as a "now" marker ONLY when
  // the engine is behind the expected point. Null = up to date -> show just "up to".
  actualElapsedHours: { type: Number, default: null },
})

let cleanup = null
onMounted(async () => {
  cleanup = await setupRipplingExplorer({ props, digestModal, legendMode })
})
onUnmounted(() => {
  if (cleanup) cleanup()
})
</script>

<style>
/* Minimal mode (per-post reach modal): keep the controls in the DOM (the explorer
   wires them up by id) but hide them, leaving just the map, the ripple point and the
   time scrubber. */
.rpl-minimal #rippling-panel,
.rpl-minimal #rippling-status {
  display: none !important;
}

/* Minimal mode: "groups reached" box, bottom-left, above the legend. */
.rpl-reach-groups {
  position: absolute;
  bottom: 10px;
  right: 10px;
  max-height: 32vh;
  overflow-y: auto;
  min-width: 130px;
  max-width: 230px;
  background: rgba(255, 255, 255, 0.92);
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 11px;
  z-index: 1000;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.rpl-reach-groups .rpl-rg-title {
  font-weight: 600;
  color: #555;
  margin-bottom: 4px;
}
.rpl-reach-groups .rpl-rg-item {
  color: #222;
  padding: 1px 0;
}
.rpl-reach-groups .rpl-rg-empty {
  color: #999;
}
</style>
