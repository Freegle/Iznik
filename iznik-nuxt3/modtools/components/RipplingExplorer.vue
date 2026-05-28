<template>
  <div id="rippling-root" style="position: relative; width: 100%; height: 100%">
    <div id="rippling-map" style="position: absolute; inset: 0"></div>

    <div id="rippling-panel">
      <div id="rippling-panel-header">
        <span>🗺</span>
        Rippling Out Explorer
      </div>
      <div id="rippling-panel-body">
        <div class="rpl-mode-row" id="rippling-view-mode" style="margin-bottom:8px">
          <button class="rpl-mode-btn rpl-active" data-view="outbound">
            <span class="rpl-icon">📡</span>Who could see my post
          </button>
          <button class="rpl-mode-btn" data-view="inbound">
            <span class="rpl-icon">📥</span>Digest preview
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
          Drop a marker.  The map shows how a post made there would
          ripple out: who is eligible to see this post, in what order,
          and how fast the wave spreads.
        </div>
        <div id="rippling-intro-inbound" class="rpl-intro" style="display:none">
          Drop a marker.  The map shows what would appear in a digest
          sent to a member at that spot — every post within their reach
          (the radius below) over the last 24 hours, in the order set by
          the sliders further down.
        </div>

        <!-- Inbound: "What's in the digest" group — controls which posts -->
        <div id="rippling-sim-contents" class="rpl-sim-group" style="display:none">
          <div class="rpl-sim-group-title">What's in the digest</div>
          <div class="rpl-sim-group-sub">
            How far we look for posts.  Bigger reach = more posts in the
            digest.
          </div>
        </div>

        <div class="rpl-slider-row" id="rippling-time-row">
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
          <div style="display:flex;justify-content:space-between;font-size:10px;color:#aaa;margin-top:2px">
            <span>Short</span><span>Long</span>
          </div>
        </div>

        <!-- Inbound: pie chart + counts (the result of "what's in") -->
        <div id="rippling-sim-pie-wrap" style="display:none">
          <div style="display:flex;gap:8px;align-items:center;margin:6px 0">
            <svg id="rippling-pie" width="56" height="56" viewBox="-1 -1 2 2" style="flex-shrink:0;transform:rotate(-90deg)">
              <circle r="1" fill="#eee" />
            </svg>
            <div id="rippling-home-summary" style="font-size:11px;color:#555;line-height:1.4;flex:1"></div>
          </div>
        </div>

        <!-- Inbound: "What order is it in?" group title — sits OUTSIDE the rail like the other heading, for visual consistency. -->
        <div id="rippling-sim-sort-title" class="rpl-sim-group" style="display:none">
          <div class="rpl-sim-group-title">What order is it in?</div>
          <div class="rpl-sim-group-sub">
            How we rank the posts inside the digest.  These don't change
            what's <em>in</em> it, just the order.
          </div>
        </div>

        <div id="rippling-inbound-row" style="display:none">
          <div class="rpl-sim-knob">
            <label>Closeness <span id="rippling-w-close-val">1.0</span></label>
            <input id="rippling-w-close" type="range" min="0" max="2" step="0.1" value="1.0" />
            <div class="rpl-sim-help">Higher = closer posts go higher in the digest.</div>
          </div>
          <div class="rpl-sim-knob">
            <label>Eyeballs budget <span id="rippling-w-budget-val">1.0</span></label>
            <input id="rippling-w-budget" type="range" min="0" max="2" step="0.1" value="1.0" />
            <div class="rpl-sim-help">Higher = posts few people have viewed yet go higher (spreads attention to undersubscribed posts).</div>
          </div>
          <div class="rpl-sim-knob">
            <label>Home-group anchor <span id="rippling-w-anchor-val">0.0</span></label>
            <input id="rippling-w-anchor" type="range" min="0" max="2" step="0.1" value="0" />
            <div class="rpl-sim-help">Higher = posts in the member's home group (the default group for their postcode) go higher in the digest.</div>
          </div>
          <button id="rippling-show-digest" class="rpl-digest-btn">
            📄 Show digest mock-up
          </button>

          <div id="rippling-sim-summary" style="font-size:11px;color:#555;margin-top:6px;line-height:1.5;padding-top:6px;border-top:1px solid #f0f0f0"></div>
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

        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:4px">
          <span style="font-size:11px;font-weight:600;color:#555;white-space:nowrap">Show:</span>
          <div class="rpl-layer-toggles" style="margin-top:0">
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

    <div id="rippling-legend-inbound" style="display:none">
      <h4>Legend</h4>
      <div class="rpl-leg-item">
        <div style="width:14px;height:14px;border-radius:50%;background:#cc0000;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.4);flex-shrink:0"></div>
        Your location
      </div>
      <div class="rpl-leg-item">
        <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
          <span style="width:9px;height:9px;border-radius:50%;background:#27ae60;border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4)"></span>
          <span>1</span>
        </span>
        Active home-group · number = digest position
      </div>
      <div class="rpl-leg-item">
        <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
          <span style="width:9px;height:9px;border-radius:50%;background:#1f77b4;border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4)"></span>
          <span>2</span>
        </span>
        Active rippled in · from a neighbouring group
      </div>
      <div class="rpl-leg-item">
        <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
          <span style="width:9px;height:9px;border-radius:50%;background:#27ae60;border:1.5px solid #fff;opacity:0.45"></span>
          <span style="opacity:0.6">3</span>
        </span>
        Faded = already taken / promised
      </div>
      <div class="rpl-leg-item">
        <div class="rpl-leg-swatch" style="background:rgba(125,60,152,0.07);border:1.5px dashed #7d3c98"></div>
        Home-group area
      </div>
      <div class="rpl-leg-item">
        <div class="rpl-leg-swatch" style="background:none;border:2.5px solid #cc0000"></div>
        Maximum reach
      </div>
      <div class="rpl-leg-item">
        <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
          <span style="width:9px;height:9px;border-radius:50%;background:#f39c12;border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4)"></span>
        </span>
        Promised — still in flight
      </div>
      <div class="rpl-leg-item">
        <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
          <span style="width:9px;height:9px;border-radius:50%;background:#888;border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4)"></span>
        </span>
        Completed since last digest
      </div>
    </div>

    <div id="rippling-legend">
      <h4>Legend</h4>
      <div class="rpl-leg-item">
        <div
          class="rpl-leg-swatch"
          style="background: none; border: 2.5px solid #cc0000"
        ></div>
        Travel time boundary
      </div>
      <div style="font-size: 10px; color: #888; margin: 3px 0 2px">
        Deprivation (outside boundary):
      </div>
      <div class="rpl-leg-item">
        <div
          class="rpl-leg-swatch"
          style="background: #d73027; opacity: 0.75"
        ></div>
        Q1 — most deprived
      </div>
      <div class="rpl-leg-item">
        <div
          class="rpl-leg-swatch"
          style="background: #fc8d59; opacity: 0.75"
        ></div>
        Q2
      </div>
      <div class="rpl-leg-item">
        <div
          class="rpl-leg-swatch"
          style="background: #fee08b; opacity: 0.75; border: 1px solid #ccc"
        ></div>
        Q3
      </div>
      <div class="rpl-leg-item">
        <div
          class="rpl-leg-swatch"
          style="background: #91cf60; opacity: 0.75"
        ></div>
        Q4
      </div>
      <div class="rpl-leg-item">
        <div
          class="rpl-leg-swatch"
          style="background: #1a9850; opacity: 0.75"
        ></div>
        Q5 — least deprived
      </div>
      <div
        class="rpl-leg-item"
        style="margin-top: 5px; padding-top: 5px; border-top: 1px solid #eee"
      >
        <div
          style="
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #e8380d;
            flex-shrink: 0;
          "
        ></div>
        Active Freegler
      </div>
      <div
        class="rpl-leg-item"
        style="margin-top: 5px; padding-top: 5px; border-top: 1px solid #eee"
      >
        <div
          class="rpl-leg-swatch"
          style="background: none; border: 2px solid #27ae60"
        ></div>
        Freegle group
      </div>
      <div class="rpl-leg-item">
        <span style="color: #e07000; font-size: 13px; margin-right: 2px"
          >⚡</span
        >
        Cross-posting begins
      </div>
    </div>

    <div id="rippling-status" style="display: none">Loading…</div>

    <div id="rippling-timeline" style="display: none">
      <div id="rippling-tl-elapsed">Just posted</div>
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
import { onMounted, onUnmounted, ref } from '#imports'
import {
  chaikinSmooth,
  geoToLeaflet,
  pointInRing,
  segmentsIntersect,
} from '~/composables/rippling/geometry.js'
import { renderPie as renderPieSvg } from '~/composables/rippling/pie.js'
import RipplingDigestModal from './RipplingDigestModal.vue'

const digestModal = ref(null)

const props = defineProps({
  spatialUrl: { type: String, default: 'http://localhost:8196' },
  jwt: { type: String, default: '' },
})

let map = null
const cleanupFns = []

function apiUrl(path) {
  const sep = path.includes('?') ? '&' : '?'
  return `${props.spatialUrl}${path}${sep}jwt=${encodeURIComponent(props.jwt)}`
}

onMounted(async () => {
  await import('leaflet/dist/leaflet.css')
  const L = (await import('leaflet')).default

  const QCOLORS = ['', '#d73027', '#fc8d59', '#fee08b', '#91cf60', '#1a9850']
  const QNAMES = [
    '',
    'Q1 (most deprived)',
    'Q2',
    'Q3',
    'Q4',
    'Q5 (least deprived)',
  ]

  map = L.map('rippling-map', { zoomControl: true }).setView([52.5, -1.8], 7)
  L.tileLayer(
    'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
    {
      attribution: '© OpenStreetMap © CartoDB',
      subdomains: 'abcd',
      maxZoom: 19,
    }
  ).addTo(map)

  let currentLat = null
  let currentLng = null
  let currentMode = 'drive'
  let marker = null
  let layers = {}
  let debounceTimer = null
  let isochroneGeneration = 0
  let fitViewOnNextIsochrone = false
  // Local deprivation baseline: fraction of Q1–Q3 freeglers within the 30-min
  // drive standard isochrone (fairness=0) for the current location.
  // Starts at 60 (national approximate); updated by fetchLocalBaseline().
  let localBaseline = 60

  const timeSlider = document.getElementById('rippling-time-slider')
  const fairnessSlider = document.getElementById('rippling-fairness-slider')

  document.querySelectorAll('.rpl-mode-btn[data-mode]').forEach((btn) => {
    btn.addEventListener('click', () => {
      document
        .querySelectorAll('.rpl-mode-btn[data-mode]')
        .forEach((b) => b.classList.remove('rpl-active'))
      btn.classList.add('rpl-active')
      currentMode = btn.dataset.mode
      if (ripplePlaying || rippleFrames.length > 0) stopRipple()
      if (currentLat !== null) scheduleUpdate()
    })
  })

  // ── Inbound / outbound view-mode toggle ────────────────────────────
  // Outbound (default): "who'd see my post" — the rippling-out animation.
  // Inbound:            "what would I see" — dots for posts I'd be eligible
  //                     to see in my digest for a given day.
  let viewMode = 'outbound'
  let inboxLayer = null
  let inboxIsoLayer = null
  let lastRanked = [] // last digest-simulator response, used by the mock-up modal
  let lastHomeGroups = []
  let lastActiveCount = 0
  let lastPromisedCount = 0
  let lastTakenCount = 0
  const inboundRow = document.getElementById('rippling-inbound-row')

  document.querySelectorAll('.rpl-mode-btn[data-view]').forEach((btn) => {
    btn.addEventListener('click', () => {
      document
        .querySelectorAll('.rpl-mode-btn[data-view]')
        .forEach((b) => b.classList.remove('rpl-active'))
      btn.classList.add('rpl-active')
      viewMode = btn.dataset.view
      applyViewMode()
      syncUrl()
    })
  })

  // Keep the browser URL in sync with the current view + location so the
  // page is bookmarkable / refreshable without losing state.
  function syncUrl() {
    const params = new URLSearchParams(window.location.search)
    params.set('view', viewMode)
    if (currentLat !== null && currentLng !== null) {
      params.set('lat', currentLat.toFixed(6))
      params.set('lng', currentLng.toFixed(6))
      params.delete('postcode')
      params.delete('q')
    }
    const newUrl =
      window.location.pathname + (params.toString() ? '?' + params : '')
    if (newUrl !== window.location.pathname + window.location.search) {
      window.history.replaceState({}, '', newUrl)
    }
  }

  function applyViewMode() {
    const inbound = viewMode === 'inbound'
    // Hide outbound-only controls in inbound mode — except the time
    // slider, which both views need to control the maximum reach.
    document.querySelectorAll('#rippling-panel-body > .rpl-slider-row, .rpl-ripple-row, #rippling-freegler-bar').forEach((el) => {
      if (el.id === 'rippling-inbound-row') return
      if (el.id === 'rippling-time-row') {
        el.style.display = ''  // always shown
        return
      }
      el.style.display = inbound ? 'none' : ''
    })
    // Hide the deprivation/freeglers/groups toggles in inbound mode — they
    // describe outbound layers.
    const layerToggles = document.querySelector('#rippling-panel-body > div[style*="flex-wrap"]')
    if (layerToggles) layerToggles.style.display = inbound ? 'none' : ''
    // Also the walk/cycle/drive travel-mode row is outbound-only.
    const travelModeRow = document.querySelector('#rippling-panel-body > .rpl-mode-row:not(#rippling-view-mode)')
    if (travelModeRow) travelModeRow.style.display = inbound ? 'none' : ''
    inboundRow.style.display = inbound ? '' : 'none'
    // Swap the legend.
    const outboundLegend = document.getElementById('rippling-legend')
    const inboundLegend = document.getElementById('rippling-legend-inbound')
    if (outboundLegend) outboundLegend.style.display = inbound ? 'none' : ''
    if (inboundLegend) inboundLegend.style.display = inbound ? '' : 'none'
    // The swingometer / fairness stats panel and the groups sidebar list
    // are outbound-only — hide them when switching to inbound.
    const statsEl = document.getElementById('rippling-stats')
    if (statsEl) statsEl.style.display = inbound ? 'none' : ''
    const groupsSection = document.getElementById('rippling-groups-section')
    if (groupsSection && inbound) groupsSection.style.display = 'none'
    // Swap the intro text.
    const introOutbound = document.getElementById('rippling-intro-outbound')
    const introInbound = document.getElementById('rippling-intro-inbound')
    if (introOutbound) introOutbound.style.display = inbound ? 'none' : ''
    if (introInbound) introInbound.style.display = inbound ? '' : 'none'
    // Show the "What's in the digest" / "Sort order" group wrappers in
    // inbound mode only.
    const contentsBox = document.getElementById('rippling-sim-contents')
    const pieWrap = document.getElementById('rippling-sim-pie-wrap')
    const sortTitle = document.getElementById('rippling-sim-sort-title')
    if (contentsBox) contentsBox.style.display = inbound ? '' : 'none'
    if (pieWrap) pieWrap.style.display = inbound ? '' : 'none'
    if (sortTitle) sortTitle.style.display = inbound ? '' : 'none'

    if (inbound) {
      clearOutboundLayers()
      if (ripplePlaying || rippleFrames.length > 0) stopRipple()
      if (currentLat !== null) fetchInbox()
    } else {
      clearInboundLayers()
      if (currentLat !== null) scheduleUpdate()
    }
  }

  function clearOutboundLayers() {
    Object.values(layers).forEach((l) => map.removeLayer(l))
    layers = {}
    // freeglersMarkers is declared later in the script (temporal dead zone),
    // so we can't reference it by name from here.  Instead, fire a custom
    // event that the freeglers-clearing block listens for.
    document.dispatchEvent(new CustomEvent('rippling-clear-freeglers'))
  }

  function clearInboundLayers() {
    if (inboxLayer) {
      map.removeLayer(inboxLayer)
      inboxLayer = null
    }
    if (inboxIsoLayer) {
      map.removeLayer(inboxIsoLayer)
      inboxIsoLayer = null
    }
  }

  function renderPie(slices) {
    renderPieSvg(document.getElementById('rippling-pie'), slices)
  }

  // ── Digest-simulator sliders ──────────────────────────────────────
  const knobs = {
    close:  { input: document.getElementById('rippling-w-close'),  val: document.getElementById('rippling-w-close-val') },
    budget: { input: document.getElementById('rippling-w-budget'), val: document.getElementById('rippling-w-budget-val') },
    anchor: { input: document.getElementById('rippling-w-anchor'), val: document.getElementById('rippling-w-anchor-val') },
  }
  const showDigestBtn = document.getElementById('rippling-show-digest')
  const simSummaryEl = document.getElementById('rippling-sim-summary')

  let inboundDebounce = null
  function scheduleInboundUpdate() {
    if (viewMode !== 'inbound' || currentLat === null) return
    clearTimeout(inboundDebounce)
    inboundDebounce = setTimeout(fetchInbox, 200)
  }

  Object.entries(knobs).forEach(([k, ref]) => {
    ref.input.addEventListener('input', () => {
      ref.val.textContent = parseFloat(ref.input.value).toFixed(1)
      scheduleInboundUpdate()
    })
  })

  // Digest mock-up modal — opens a side panel listing the selection
  // in digest order.  All rendering lives in <RipplingDigestModal>; this
  // file just calls it with the data and the member's current location.
  showDigestBtn.addEventListener('click', () =>
    digestModal.value?.openDigest(lastRanked, currentLat, currentLng)
  )
  function openPostDetail(p, rank) {
    digestModal.value?.openPost(p, rank, currentLat, currentLng)
  }
  function openClusterDetail(posts) {
    digestModal.value?.openCluster(posts, currentLat, currentLng)
  }

  async function fetchInbox() {
    if (currentLat === null) return
    clearInboundLayers()
    // Belt-and-braces: also wipe any outbound layers that may still be on
    // the map (freeglers, isochrone polygons, group outlines) — these don't
    // belong in the inbound view.
    clearOutboundLayers()
    // messages_spatial is updated in place; only "now" is meaningful.
    const qs = new URLSearchParams({
      lat: currentLat.toFixed(6),
      lng: currentLng.toFixed(6),
      // Maximum reach driven by the shared time slider at the top.
      max_minutes: timeSlider.value,
      w_closeness: knobs.close.input.value,
      w_freshness: '0', // freshness disabled — time-of-arrival within a daily-digest window doesn't carry useful signal
      w_budget: knobs.budget.input.value,
      w_anchor: knobs.anchor.input.value,
      // No cap: the sort order is what matters, not a hard cut.  Pass the
      // backend ceiling (1000) so the full reachable pool comes back.
      cap: '1000',
      group_by_poster: 'false',
    })
    const url = apiUrl(`/v1/digest-simulator?${qs.toString()}`)
    try {
      const r = await fetch(url)
      if (!r.ok) return
      const data = await r.json()
      drawInbox(data)
    } catch (e) {
      // ignore
    }
  }

  function drawInbox(data) {
    if (!data) return
    const homeSummary = document.getElementById('rippling-home-summary')

    const clusterNote = data.poster_groups && data.poster_groups.length
      ? ` · ${data.poster_groups.length} same-poster cluster${data.poster_groups.length === 1 ? '' : 's'}`
      : ''
    // Total now appears as the headline number inside the pie area; keep
    // the lower summary for cluster info only, when there are clusters.
    simSummaryEl.innerHTML = clusterNote
      ? `<strong>${data.poster_groups.length}</strong> same-poster cluster${data.poster_groups.length === 1 ? '' : 's'}.`
      : ''

    // Home-group polygons — draw before the isochrone so they sit underneath.
    ;(data.home_groups || []).forEach((g) => {
      if (!g.polygon) return
      const layer = L.geoJSON(
        { type: 'Feature', geometry: g.polygon },
        {
          style: {
            color: '#7d3c98',
            weight: 1.5,
            fill: true,
            fillColor: '#7d3c98',
            fillOpacity: 0.05,
            dashArray: '2,3',
          },
        }
      )
        .bindTooltip(`Home group: ${g.name}`, { sticky: true })
        .addTo(map)
      // Track on the same layer-set as the isochrone so it gets cleared on
      // next refresh.
      if (!inboxLayer) inboxLayer = L.featureGroup().addTo(map)
      inboxLayer.addLayer(layer)
    })

    // Outline the isochrone — apply Chaikin smoothing client-side so the
    // boundary reads as a curve rather than a staircase.
    if (data.isochrone && data.isochrone.geometry) {
      const ring = data.isochrone.geometry.coordinates[0]
      if (ring && ring.length > 3) {
        const smoothed = chaikinSmooth(ring).map(([lng, lat]) => [lat, lng])
        inboxIsoLayer = L.polygon(smoothed, {
          color: '#cc0000',
          weight: 2.5,
          fill: false,
        }).addTo(map)
      }
    }

    const group = inboxLayer || L.featureGroup().addTo(map)
    inboxLayer = group
    // Flatten selected + deferred into one ranked list (backend returns them
    // score-sorted), then render each as a numbered marker showing its
    // position in the digest.  Numbers > 99 read as "99+" to keep the badge
    // visually tidy.
    // Match the V1 production digest model: still-active posts at the top
    // (sorted by score), then a "promised — still in flight" section, then
    // a "completed since last digest" tail rendered grey, with an
    // encouragement to switch to immediate mode for missed posts.  Within
    // each section we keep the backend's score ordering.
    const rawList = [].concat(data.selected || [], data.deferred || [])
    const active = rawList.filter((p) => !p.successful && !p.promised)
    const activeHome = active.filter((p) => p.home_group)
    const activeCross = active.filter((p) => !p.home_group)
    const promised = rawList.filter((p) => p.promised && !p.successful)
    const taken = rawList.filter((p) => p.successful)
    const ranked = active.concat(promised, taken)
    // Stamp each post with its global rank (1-based) so the marker labels
    // and modal can both reference the same position.
    ranked.forEach((p, i) => (p._rank = i + 1))
    // Keep this list around for the digest mock-up modal.
    lastRanked = ranked
    lastActiveCount = active.length
    lastPromisedCount = promised.length
    lastTakenCount = taken.length
    lastHomeGroups = data.home_groups || []

    const total = data.pool_size || 0
    const homeHead = data.home_groups && data.home_groups.length
      ? `<strong>Home:</strong> ${data.home_groups.map((g) => g.name).join(', ')}`
      : `<strong>No home group at this point.</strong>`
    homeSummary.innerHTML =
      `<div style="font-size:13px;font-weight:700;color:#333;margin-bottom:2px">${total} post${total === 1 ? '' : 's'} in digest</div>` +
      `${homeHead}<br>` +
      `<span style="color:#27ae60">●</span> ${activeHome.length} active home-group · ` +
      `<span style="color:#1f77b4">●</span> ${activeCross.length} rippled in<br>` +
      `<span style="color:#f39c12">●</span> ${promised.length} promised · ` +
      `<span style="color:#888">●</span> ${taken.length} completed`
    renderPie([
      { count: activeHome.length, color: '#27ae60' },
      { count: activeCross.length, color: '#1f77b4' },
      { count: promised.length, color: '#f39c12' },
      { count: taken.length, color: '#888' },
    ])
    // Group posts that share the same lat/lng (typical for TrashNothing
    // cross-posts using a group centroid) so they collapse into a single
    // marker with a comma-separated list of digest positions.
    const buckets = new Map()
    ranked.forEach((p) => {
      const key = p.lat.toFixed(5) + ',' + p.lng.toFixed(5)
      if (!buckets.has(key)) buckets.set(key, [])
      buckets.get(key).push(p)
    })
    const totalRanked = ranked.length
    const colorFor = (p) => {
      if (p.successful) return '#888'
      if (p.promised) return '#f39c12'
      return p.home_group ? '#27ae60' : '#1f77b4'
    }
    buckets.forEach((bucketPosts) => {
      // Sort by rank within the cluster (already sorted globally).
      const minRank = bucketPosts[0]._rank
      // Pick the dominant colour by the first (highest-ranked) member.
      const color = colorFor(bucketPosts[0])
      const t = totalRanked > 1 ? (minRank - 1) / Math.max(totalRanked - 1, 1) : 0
      const baseOpacity = 0.95 - 0.45 * t
      const dotOpacity =
        bucketPosts[0].successful || bucketPosts[0].promised ? 0.85 : baseOpacity
      // Truncate the label list at 6 ranks to avoid overflow.
      const ranks = bucketPosts.map((p) => p._rank)
      let label = ranks.slice(0, 6).join(',')
      if (ranks.length > 6) label += ',+' + (ranks.length - 6)
      const icon = L.divIcon({
        className: 'rpl-digest-marker',
        html:
          `<div style="display:flex;align-items:center;gap:2px;` +
          `text-shadow:0 0 2px #fff,0 0 2px #fff;font-size:10px;` +
          `font-weight:700;color:#222;line-height:1;white-space:nowrap">` +
          `<div style="width:9px;height:9px;border-radius:50%;background:${color};` +
          `border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4);` +
          `opacity:${dotOpacity};flex-shrink:0"></div>` +
          `<span>${label}</span></div>`,
        iconSize: null,
        iconAnchor: [4, 4],
      })
      const tip =
        bucketPosts.length === 1
          ? 'Click for details'
          : `Click for ${bucketPosts.length} posts at this location`
      L.marker([bucketPosts[0].lat, bucketPosts[0].lng], { icon })
        .bindTooltip(tip, { sticky: true, direction: 'top' })
        .on('click', () => {
          if (bucketPosts.length === 1)
            openPostDetail(bucketPosts[0], bucketPosts[0]._rank - 1)
          else openClusterDetail(bucketPosts)
        })
        .addTo(group)
    })

    // Same-poster clusters: ring around the top post with the count.
    ;(data.poster_groups || []).forEach((cl) => {
      L.circleMarker([cl.top_lat, cl.top_lng], {
        radius: 12,
        color: '#9b59b6',
        weight: 2,
        fill: false,
        dashArray: '3,3',
      })
        .bindTooltip(`Same poster: ${cl.count} posts (top + ${cl.count - 1} others)`, { sticky: true })
        .addTo(group)
    })
  }

  timeSlider.addEventListener('input', () => {
    if (currentLat === null) return
    if (viewMode === 'inbound') scheduleInboundUpdate()
    else scheduleUpdate()
  })
  fairnessSlider.addEventListener('input', () => {
    document.getElementById('rippling-fairness-val').textContent =
      fairnessSlider.value
    if (currentLat !== null) scheduleUpdate()
  })

  let showQuintiles = true
  let showFreeglers = true
  let showGroups = true

  document
    .getElementById('rippling-tog-quintiles')
    .addEventListener('change', function () {
      showQuintiles = this.checked
      Object.entries(layers).forEach(([k, lyr]) => {
        if (k !== 'standard') {
          if (showQuintiles) {
            if (!map.hasLayer(lyr)) lyr.addTo(map)
          } else if (map.hasLayer(lyr)) map.removeLayer(lyr)
        }
      })
      if (showQuintiles) requestAnimationFrame(updateFairnessClip)
    })

  document
    .getElementById('rippling-tog-freeglers')
    .addEventListener('change', function () {
      showFreeglers = this.checked
      if (showFreeglers) drawFreeglersLayer()
      else {
        freeglersMarkers.forEach((m) => map.removeLayer(m))
        freeglersMarkers = []
      }
    })

  document
    .getElementById('rippling-tog-groups')
    .addEventListener('change', function () {
      showGroups = this.checked
      if (showGroups) drawGroupsOverlay()
      else {
        Object.values(groupLayerMap).forEach((l) => map.removeLayer(l))
        groupLayerMap = {}
        document.getElementById('rippling-groups-section').style.display =
          'none'
      }
    })

  const searchBox = document.getElementById('rippling-search-box')
  const searchResults = document.getElementById('rippling-search-results')
  let searchTimer = null

  searchBox.addEventListener('input', () => {
    clearTimeout(searchTimer)
    const q = searchBox.value.trim()
    if (q.length < 2) {
      searchResults.innerHTML = ''
      return
    }
    searchTimer = setTimeout(() => nominatimSearch(q), 400)
  })

  document.addEventListener('click', clickOutside)
  cleanupFns.push(() => document.removeEventListener('click', clickOutside))
  function clickOutside(e) {
    if (!e.target.closest('#rippling-search-wrap')) searchResults.innerHTML = ''
  }

  function nominatimSearch(q) {
    fetch(
      `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(
        q
      )}&format=json&limit=6&countrycodes=gb&addressdetails=0`
    )
      .then((r) => r.json())
      .then((results) => {
        searchResults.innerHTML = ''
        results.forEach((r) => {
          const li = document.createElement('li')
          const parts = r.display_name.split(', ')
          li.textContent = parts.slice(0, 4).join(', ')
          li.title = r.display_name
          li.addEventListener('click', () => {
            searchResults.innerHTML = ''
            searchBox.value = parts[0]
            setLocation(parseFloat(r.lat), parseFloat(r.lon), true)
          })
          searchResults.appendChild(li)
        })
      })
      .catch(() => {
        searchResults.innerHTML =
          '<li style="color:#aaa">Search unavailable</li>'
      })
  }

  map.on('click', (e) => setLocation(e.latlng.lat, e.latlng.lng, false))

  // ── URL parameter handling ───────────────────────────────────────
  //   ?view=inbound|outbound   — preselect a mode on load
  //   ?lat=51.5&lng=-0.12      — drop the location marker straight away
  //   ?postcode=OX1+1AB        — geocode via Nominatim and drop marker
  // These let you bookmark "Trafalgar Square digest preview" etc.
  //
  // The actual apply is deferred to a microtask because setLocation
  // references variables (ripplePlaying, freeglersMarkers) that are declared
  // further down this onMounted body and live in the temporal dead zone
  // until we reach them.
  const urlParams = new URLSearchParams(window.location.search)
  const pendingView = urlParams.get('view')
  const pendingLat = parseFloat(urlParams.get('lat'))
  const pendingLng = parseFloat(urlParams.get('lng'))
  const pendingPostcode = urlParams.get('postcode') || urlParams.get('q')

  async function applyUrlInit() {
    if (pendingView === 'inbound' || pendingView === 'outbound') {
      const btn = document.querySelector(`.rpl-mode-btn[data-view="${pendingView}"]`)
      if (btn) btn.click()
    }
    if (!isNaN(pendingLat) && !isNaN(pendingLng)) {
      setLocation(pendingLat, pendingLng, true)
      return true
    }
    if (pendingPostcode) {
      try {
        const r = await fetch(
          `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(
            pendingPostcode
          )}&format=json&limit=1&countrycodes=gb`
        )
        const arr = await r.json()
        if (arr && arr[0]) {
          setLocation(parseFloat(arr[0].lat), parseFloat(arr[0].lon), true)
          return true
        }
      } catch (e) {
        // fall through to geolocation
      }
    }
    return false
  }

  // Defer until after onMounted's synchronous setup completes (so all the
  // let-bindings further down are reached) — setTimeout(0) is enough.
  setTimeout(async () => {
    const urlSetLocation = await applyUrlInit()
    if (!urlSetLocation && navigator.geolocation && currentLat === null) {
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          if (currentLat === null)
            setLocation(pos.coords.latitude, pos.coords.longitude, true)
        },
        () => {}
      )
    }
  }, 0)

  function setLocation(lat, lng, fly) {
    if (ripplePlaying || rippleFrames.length > 0) stopRipple()
    currentLat = lat
    currentLng = lng
    syncUrl()
    if (marker) map.removeLayer(marker)
    const inbound = viewMode === 'inbound'
    if (inbound) {
      // Inbound mode: use a real divIcon marker (not a circleMarker) so
      // we can put it in a dedicated topmost pane.  Otherwise digest
      // post markers, which are L.markers in the default marker pane,
      // can sit on top of the SVG circle and hide the red dot.
      if (!map.getPane('locationPane')) {
        const p = map.createPane('locationPane')
        p.style.zIndex = 700  // above markerPane (600) and overlayPane (400)
      }
      marker = L.marker([lat, lng], {
        pane: 'locationPane',
        interactive: false,
        icon: L.divIcon({
          className: 'rpl-location-marker',
          html: '<div style="width:16px;height:16px;border-radius:50%;background:#cc0000;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.5)"></div>',
          iconSize: [16, 16],
          iconAnchor: [8, 8],
        }),
      }).addTo(map)
    } else {
      marker = L.circleMarker([lat, lng], {
        radius: 8,
        color: '#e8380d',
        weight: 3,
        fillColor: '#fff',
        fillOpacity: 1,
      }).addTo(map)
    }
    if (fly) map.flyTo([lat, lng], Math.max(map.getZoom(), 13))
    if (inbound) {
      // Inbound mode: don't recompute outbound isochrones; just refresh
      // the posts-for-member dots.  Also wipe any freegler dots that may
      // still be on the map from the outbound view.
      clearOutboundLayers()
      fetchInbox()
      return
    }
    fitViewOnNextIsochrone = true
    fetchLocalBaseline(lat, lng)
    fetchAndDrawGroups(lat, lng)
    updateIsochrone()
  }

  // Fetch the natural deprivation fraction for this location at the
  // *maximum* reach the slider allows.  This is the regional baseline:
  // "if we showed this post to every freegler the algorithm could
  // possibly reach from here, what share of them would be in deprived
  // quintiles?"  The swingometer then measures how the *current*
  // (slider-set) reach deviates from that regional mix, so the needle
  // should sit dead-centre when the slider is cranked to the top.
  async function fetchLocalBaseline(lat, lng) {
    const maxReach = Number(timeSlider.max) || 60
    try {
      const url = apiUrl(
        `/v1/fairness?lat=${lat.toFixed(6)}&lng=${lng.toFixed(6)}&minutes=${maxReach}&mode=drive&fairness=0`
      )
      const r = await fetch(url)
      if (!r.ok) return
      const data = await r.json()
      if (data.fairness_score !== undefined && data.fairness_score >= 0) {
        localBaseline = Math.round(data.fairness_score * 100)
      }
    } catch (e) {
      // Keep previous baseline on error
    }
  }

  function scheduleUpdate() {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(updateIsochrone, 350)
  }

  function clearLayers() {
    Object.values(layers).forEach((l) => {
      if (map.hasLayer(l)) map.removeLayer(l)
    })
    layers = {}
  }

  const statusEl = document.getElementById('rippling-status')
  function showStatus(msg, loading) {
    statusEl.innerHTML = loading
      ? `<span class="rpl-spinner"></span> ${msg}`
      : msg
    statusEl.style.display = ''
    if (!loading)
      setTimeout(() => {
        statusEl.style.display = 'none'
      }, 2000)
  }

  function updateIsochrone() {
    if (currentLat === null) return
    const gen = ++isochroneGeneration
    const minutes = parseInt(timeSlider.value)
    const fairness = parseInt(fairnessSlider.value) / 100
    const url = apiUrl(
      `/v1/fairness?lat=${currentLat.toFixed(6)}&lng=${currentLng.toFixed(
        6
      )}&minutes=${minutes}&mode=${currentMode}&fairness=${fairness}`
    )

    showStatus('Computing isochrone…', true)

    fetch(url)
      .then((r) => {
        if (!r.ok) throw new Error(`Server error ${r.status}`)
        return r.json()
      })
      .then(async (data) => {
        if (gen !== isochroneGeneration) return
        drawPolygons(data, 0)
        updateStats(data)
        if (data.snap_lat && data.snap_lng && marker) {
          marker.setLatLng([data.snap_lat, data.snap_lng])
        }
        if (fitViewOnNextIsochrone) {
          fitViewOnNextIsochrone = false
          const allRings = allIsoRings(data)
          if (allRings.length > 0) {
            const allCoords = allRings.flat()
            const bounds = L.latLngBounds(allCoords.map(([lng, lat]) => [lat, lng]))
            if (bounds.isValid()) map.fitBounds(bounds, { padding: [60, 60], maxZoom: 13, animate: false })
          }
        }
        await fetchFreeglers()
        drawFreeglersLayer()
        updateFreeglersInside(data)
        drawGroupsOverlay()
        showStatus('Done', false)
      })
      .catch((err) => {
        showStatus('Error: ' + err.message, false)
        document.getElementById(
          'rippling-stats'
        ).innerHTML = `<div class="rpl-tip" style="color:#c00">${err.message}</div>`
      })
  }

  let lastIsoData = null

  function drawPolygons(data, transitionMs, skipStandard = false) {
    lastIsoData = data
    const dur = transitionMs || 0
    const outgoing = Object.assign({}, layers)
    const newLayers = {}

    function applyTransition(el, durationMs) {
      if (!el) return
      el.style.transformBox = 'fill-box'
      el.style.transformOrigin = 'center'
      el.style.transform = 'scale(0.88)'
      el.style.transition = `transform ${durationMs}ms ease-out, fill-opacity ${durationMs}ms ease-out, opacity ${durationMs}ms ease-out`
    }

    function addPoly(key, coords, opts, targetFill, targetOpacity, tooltip) {
      const existing = layers[key]
      if (existing && map.hasLayer(existing)) {
        // Set the SVG `d` transition to 70% of the step delay so the shape
        // morph always finishes before the next frame fires.  Without this the
        // CSS fixed 450ms would overflow shorter delays, causing stuttering.
        const el = existing.getElement()
        if (el) {
          const dDur = dur > 0 ? Math.round(dur * 0.7) : 0
          el.style.transition = dDur > 0 ? `d ${dDur}ms ease-out` : 'none'
        }
        existing.setLatLngs(coords)
        existing.setStyle({ ...opts, fillOpacity: targetFill, opacity: targetOpacity })
        existing.setTooltipContent(tooltip)
        newLayers[key] = existing
        delete outgoing[key]
        return existing
      }
      const lyr = L.polygon(coords, { ...opts, fillOpacity: 0, opacity: 0 })
        .addTo(map)
        .bindTooltip(tooltip)
      newLayers[key] = lyr
      if (dur > 0) {
        const el = lyr.getElement()
        applyTransition(el, dur)
        requestAnimationFrame(() => {
          if (el) el.style.transform = 'scale(1)'
          lyr.setStyle({ fillOpacity: targetFill, opacity: targetOpacity })
        })
      } else {
        lyr.setStyle({ fillOpacity: targetFill, opacity: targetOpacity })
      }
      return lyr
    }

    if (showQuintiles)
      [5, 4, 3, 2, 1].forEach((q) => {
        const qr = data.quintiles && data.quintiles[q]
        if (!qr) return
        if (hasRing(qr.polygon)) {
          addPoly(
            `q${q}`,
            geoToLeaflet(qr.polygon.geometry.coordinates[0]),
            { color: '#005bb5', weight: 1, fillColor: QCOLORS[q] },
            0.3,
            1,
            `${QNAMES[q]} (standard reach) · ${qr.time_budget_min.toFixed(
              1
            )} min`
          )
        }
        ;(qr.islands || []).forEach((island, i) => {
          if (!hasRing(island)) return
          addPoly(
            `q${q}_island_${i}`,
            geoToLeaflet(island.geometry.coordinates[0]),
            {
              color: '#005bb5',
              weight: 2,
              dashArray: '5 4',
              fillColor: QCOLORS[q],
            },
            0.4,
            1,
            `${QNAMES[q]} — fairness bonus area`
          )
        })
      })

    if (!skipStandard && hasRing(data.standard)) {
      addPoly(
        'standard',
        geoToLeaflet(data.standard.geometry.coordinates[0]),
        { color: '#cc0000', weight: 2.5, fillColor: 'none' },
        0,
        1,
        'Standard reach boundary (no fairness adjustment)'
      )
    }

    if (dur > 0) {
      Object.values(outgoing).forEach((lyr) => {
        const el = lyr.getElement()
        if (el)
          el.style.transition = `fill-opacity ${dur}ms ease-out, opacity ${dur}ms ease-out`
        lyr.setStyle({ fillOpacity: 0, opacity: 0 })
        setTimeout(() => {
          if (map.hasLayer(lyr)) map.removeLayer(lyr)
        }, dur + 50)
      })
    } else {
      Object.values(outgoing).forEach((lyr) => {
        if (map.hasLayer(lyr)) map.removeLayer(lyr)
      })
    }

    layers = newLayers
    requestAnimationFrame(() => requestAnimationFrame(updateFairnessClip))
    map.once('moveend', updateFairnessClip)
  }

  function hasRing(poly) {
    return (
      poly &&
      poly.geometry &&
      poly.geometry.coordinates &&
      poly.geometry.coordinates[0] &&
      poly.geometry.coordinates[0].length >= 4
    )
  }

  function updateFairnessClip() {
    const svgEl = map.getPane('overlayPane').querySelector('svg')
    if (!svgEl) return

    let defs = svgEl.querySelector('defs')
    if (!defs) {
      defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs')
      svgEl.insertBefore(defs, svgEl.firstChild)
    }
    const existing = svgEl.querySelector('#rpl-fairness-clip')
    if (existing) existing.remove()

    // Rebuild the clip path from geographic coordinates via latLngToLayerPoint
    // rather than reading the SVG <path d="…"> attribute directly.
    // Reading 'd' is unreliable: Leaflet may have a <g transform> inside the
    // SVG that offsets element-local coordinates from the SVG root's user
    // space, causing the clip to drift during zoom/pan and produce straight-
    // line rendering artifacts at the SVG viewport boundary.
    if (!lastIsoData || !hasRing(lastIsoData.standard)) {
      Object.entries(layers).forEach(([k, lyr]) => {
        if (k !== 'standard') {
          const el = lyr.getElement && lyr.getElement()
          if (el) el.removeAttribute('clip-path')
        }
      })
      return
    }

    const geoRing = lastIsoData.standard.geometry.coordinates[0]
    const smoothed = chaikinSmooth(geoRing)
    const pts = smoothed
      .map(([lng, lat]) => {
        const p = map.latLngToLayerPoint([lat, lng])
        return `${p.x.toFixed(1)},${p.y.toFixed(1)}`
      })
      .join(' L ')
    const stdD = `M ${pts} Z`

    const clipPath = document.createElementNS(
      'http://www.w3.org/2000/svg',
      'clipPath'
    )
    clipPath.id = 'rpl-fairness-clip'
    const combinedPath = document.createElementNS(
      'http://www.w3.org/2000/svg',
      'path'
    )
    const B = 1e6
    combinedPath.setAttribute(
      'd',
      `M -${B} -${B} L ${B} -${B} L ${B} ${B} L -${B} ${B} Z ${stdD}`
    )
    combinedPath.setAttribute('clip-rule', 'evenodd')
    clipPath.appendChild(combinedPath)
    defs.appendChild(clipPath)

    Object.entries(layers).forEach(([k, lyr]) => {
      if (k !== 'standard') {
        const el = lyr.getElement && lyr.getElement()
        if (el) el.setAttribute('clip-path', 'url(#rpl-fairness-clip)')
      }
    })
  }

  function swingometerAngleXY(pct) {
    // Centre the needle on localBaseline (not a fixed 60%), so the gauge
    // measures deviation from the area's natural demographics.
    const angle = Math.max(-90, Math.min(90, ((pct - localBaseline) / 40) * 90))
    const rad = (angle * Math.PI) / 180
    const R = 42
    return {
      x: (R * Math.sin(rad)).toFixed(1),
      y: (-R * Math.cos(rad)).toFixed(1),
    }
  }

  function setSwingometer(pct) {
    const needle = document.getElementById('rpl-swing-needle')
    const labelEl = document.getElementById('rpl-swing-label')
    const pctEl = document.getElementById('rpl-swing-pct')
    if (!needle || !labelEl || !pctEl) return
    const { x, y } = swingometerAngleXY(pct)
    needle.setAttribute('x2', x)
    needle.setAttribute('y2', y)
    // ±8% band around local baseline is "Balanced"
    const lo = localBaseline - 8
    const hi = localBaseline + 8
    const swingLabel =
      pct < lo ? 'Affluent bias' : pct > hi ? 'Deprived bias' : 'Balanced'
    const swingColor = pct < lo ? '#4477aa' : pct > hi ? '#d73027' : '#1a9850'
    const aboveBaseline = pct >= localBaseline
    const diff = Math.abs(pct - localBaseline)
    labelEl.textContent = swingLabel
    labelEl.style.color = swingColor
    pctEl.innerHTML = `${pct}% of Freeglers within reach are in deprived areas<br>
      <span style="color:${
        aboveBaseline ? '#1a9850' : '#d73027'
      };font-weight:600">${aboveBaseline ? '▲' : '▼'} ${diff}% ${
      aboveBaseline ? 'above' : 'below'
    } proportionate for this area (${localBaseline}%)</span>`
  }

  function updateStats(data) {
    const statsEl = document.getElementById('rippling-stats')
    const hasFairness =
      data.fairness_score !== undefined && data.fairness_score >= 0

    if (!hasFairness) {
      const noRoads = !hasRing(data.standard)
      statsEl.innerHTML = noRoads
        ? `<div class="rpl-tip">No road data near this location.</div>`
        : `<div class="rpl-tip">No deprivation index for this area — only the standard isochrone is shown.</div>`
      return
    }

    const roadPct = Math.round(data.fairness_score * 100)
    const { x: nx, y: ny } = swingometerAngleXY(roadPct)

    statsEl.innerHTML = `
      <div style="text-align:center;margin-top:6px">
        <svg viewBox="-65 -58 130 72" width="160" style="display:block;margin:auto;overflow:visible">
          <path d="M -52 0 A 52 52 0 0 1 0 -52" fill="none" stroke="#4477aa" stroke-width="14" opacity="0.30" stroke-linecap="butt"/>
          <path d="M 0 -52 A 52 52 0 0 1 52 0" fill="none" stroke="#d73027" stroke-width="14" opacity="0.30" stroke-linecap="butt"/>
          <line x1="0" y1="-44" x2="0" y2="-60" stroke="#555" stroke-width="1.5"/>
          <line id="rpl-swing-needle" x1="0" y1="0" x2="${nx}" y2="${ny}" stroke="#333" stroke-width="3" stroke-linecap="round"/>
          <circle cx="0" cy="0" r="5" fill="#333"/>
          <text x="-60" y="16" text-anchor="middle" font-size="9" fill="#4477aa" font-weight="600">Affluent</text>
          <text x="60" y="16" text-anchor="middle" font-size="9" fill="#d73027" font-weight="600">Deprived</text>
          <text x="0" y="-62" text-anchor="middle" font-size="9" fill="#555">Balanced</text>
        </svg>
        <div id="rpl-swing-label" style="font-size:14px;font-weight:700;color:#888;margin:2px 0 4px">Loading Freegler data…</div>
        <div id="rpl-swing-pct" style="font-size:11px;color:#888;line-height:1.5">Waiting for Freegler data…</div>
      </div>`
  }

  document
    .getElementById('rippling-balance-btn')
    .addEventListener('click', async () => {
      if (currentLat === null) {
        showStatus('Click a location first', false)
        return
      }
      const btn = document.getElementById('rippling-balance-btn')
      btn.disabled = true
      btn.textContent = '⏳ Searching…'

      const minutes = parseInt(timeSlider.value)
      let lo = 0
      let hi = 1.0
      let best = 0.5
      for (let iter = 0; iter < 8; iter++) {
        const mid = (lo + hi) / 2
        try {
          const url = apiUrl(
            `/v1/fairness?lat=${currentLat.toFixed(6)}&lng=${currentLng.toFixed(
              6
            )}&minutes=${minutes}&mode=${currentMode}&fairness=${mid.toFixed(
              4
            )}`
          )
          const data = await fetch(url).then((r) => r.json())
          if (data.fairness_score === undefined) break
          best = mid
          // Search for the fairness value that achieves the LOCAL baseline
          // fraction, not the hardcoded national average.
          if (data.fairness_score < localBaseline / 100) lo = mid
          else hi = mid
        } catch (e) {
          break
        }
      }

      const sliderVal = Math.round((best * 100) / 5) * 5
      fairnessSlider.value = sliderVal
      document.getElementById('rippling-fairness-val').textContent = sliderVal
      btn.disabled = false
      btn.textContent = '⊕ Proportionate'
      updateIsochrone()
    })

  // ── Freegler dots ─────────────────────────────────────────────────────────
  let allFreeglers = []
  let freeglersMarkers = []
  let freeglersMapTimer = null

  // clearOutboundLayers fires this so it can wipe freegler dots without
  // referencing the freeglersMarkers binding before initialisation.
  document.addEventListener('rippling-clear-freeglers', () => {
    freeglersMarkers.forEach((m) => map.removeLayer(m))
    freeglersMarkers = []
  })
  // Total located freeglers in the area BEFORE the 2000-point display cap.
  // allFreeglers.length may be capped; use this for estimates.
  let totalLocatedFromServer = 0

  // minutesOverride lets callers (e.g. the ripple animation) fetch at a
  // specific time budget rather than the current slider value.
  async function fetchFreeglers(minutesOverride) {
    if (currentLat === null) return
    const minutes =
      minutesOverride !== undefined ? minutesOverride : parseInt(timeSlider.value)
    const url = apiUrl(
      `/v1/nearby-freeglers?lat=${currentLat.toFixed(6)}&lng=${currentLng.toFixed(6)}&minutes=${minutes}&mode=${currentMode}`
    )
    try {
      const r = await fetch(url)
      const data = await r.json()
      allFreeglers = (data.freeglers || []).filter((e) => e && e.lat && e.lng)
      // Use server-reported total (before any sampling cap) for estimates.
      totalLocatedFromServer =
        typeof data.total_located === 'number'
          ? data.total_located
          : allFreeglers.length
    } catch (e) {
      allFreeglers = []
      totalLocatedFromServer = 0
    }
  }

  fetchFreeglers().then(drawFreeglersLayer)

  map.on('zoomend moveend', () => updateFairnessClip())

  map.on('moveend zoomend', () => {
    drawGroupsOverlay()
    drawFreeglersLayer()  // always re-apply zoom gate (clears dots when zoomed out)
    if (ripplePlaying) return  // skip expensive re-fetch during animation
    clearTimeout(freeglersMapTimer)
    freeglersMapTimer = setTimeout(async () => {
      await fetchFreeglers()
      drawFreeglersLayer()
      if (lastIsoData) updateFreeglersInside(lastIsoData)
    }, 400)
  })

  let freeglersGrid = []

  function buildFreeglersGrid() {
    const m = new Map()
    allFreeglers.forEach((f) => {
      const key = `${f.lat.toFixed(4)},${f.lng.toFixed(4)}`
      if (!m.has(key)) m.set(key, { lat: f.lat, lng: f.lng, count: 0 })
      m.get(key).count++
    })
    freeglersGrid = [...m.values()]
  }

  const FREEGLER_DOT_MIN_ZOOM = 11

  function drawFreeglersLayer() {
    freeglersMarkers.forEach((m) => map.removeLayer(m))
    freeglersMarkers = []
    // Always build the grid so updateFreeglersInside can compute the count and
    // deprivation percentage even when dots are hidden (toggle off or zoom < 11).
    buildFreeglersGrid()
    if (!showFreeglers) return
    if (viewMode === 'inbound') return  // freegler dots are outbound-only
    if (map.getZoom() < FREEGLER_DOT_MIN_ZOOM) return
    freeglersGrid.forEach((g) => {
      const m = L.circleMarker([g.lat, g.lng], {
        radius: 1,
        color: '#e8380d',
        weight: 1,
        fillColor: '#e8380d',
        fillOpacity: 0.6,
      })
        .bindTooltip(`${g.count} freeglers here`, { sticky: true })
        .addTo(map)
      freeglersMarkers.push(m)
    })
  }

  function quintileOfFreegler(fLng, fLat, data) {
    for (let q = 1; q <= 5; q++) {
      const qr = (data.quintiles || {})[q]
      if (!qr) continue
      if (
        hasRing(qr.polygon) &&
        pointInRing(fLng, fLat, qr.polygon.geometry.coordinates[0])
      )
        return q
      for (const isl of qr.islands || []) {
        if (
          hasRing(isl) &&
          pointInRing(fLng, fLat, isl.geometry.coordinates[0])
        )
          return q
      }
    }
    // Inside the standard isochrone but not in any quintile polygon: this node
    // has no LSOA deprivation data (motorway, industrial area, untagged road,
    // etc.).  Returning 3 here was wrong — it inflated deprived counts and
    // pinned the swingometer hard right.  Return -1 so callers can still count
    // this freegler as "inside" for notification numbers without skewing the
    // deprivation percentage.
    const std = data.standard
    if (hasRing(std) && pointInRing(fLng, fLat, std.geometry.coordinates[0]))
      return -1
    return 0
  }

  const UNLOCATED_FRACTION = 0.35

  function updateFreeglersInside(data) {
    // Two ways to know how many freeglers are inside the current boundary:
    //
    //   (a) Schedule-provided cumulative count.  The ripple-schedule endpoint
    //       already computed exactly this server-side, so when it's present
    //       we trust it — that's the actual notification count the cron will
    //       deliver, no sampling/extrapolation needed.
    //   (b) Polygon containment of the local freegler grid.  Used in static
    //       view (no schedule) and as a fallback.
    //
    // Either way we ALSO walk the grid to highlight individual dots inside
    // vs outside the boundary — that's purely visual.
    let insideCount = 0
    let quintileTaggedCount = 0
    let deprivedCount = 0
    freeglersGrid.forEach((g, i) => {
      const q = quintileOfFreegler(g.lng, g.lat, data)
      if (q !== 0) {
        insideCount += g.count
        if (q >= 1) {
          quintileTaggedCount += g.count
          if (q <= 3) deprivedCount += g.count
        }
        if (freeglersMarkers[i])
          freeglersMarkers[i].setStyle({ fillOpacity: 1, opacity: 1 })
      } else if (freeglersMarkers[i])
        freeglersMarkers[i].setStyle({ fillOpacity: 0.12, opacity: 0.2 })
    })

    const totalLocated = totalLocatedFromServer || allFreeglers.length
    const sampleSize = allFreeglers.length
    const isRipple = ripplePlaying || rippleFrames.length > 0
    // Prefer the schedule's authoritative cumulative_users when present.
    let estimatedInsideLocated
    if (data && data.cumulative_users !== undefined && data.cumulative_users !== null) {
      estimatedInsideLocated = data.cumulative_users
    } else if (isRipple && sampleSize > 0) {
      estimatedInsideLocated = Math.round(insideCount * (totalLocated / sampleSize))
    } else {
      estimatedInsideLocated = totalLocated
    }

    const bar = document.getElementById('rippling-freegler-bar')
    // Only show the count bar when there is at least 1 freegler inside the
    // isochrone.  Showing "~0 would be notified" at the start of the ripple
    // (when the tiny 0.5-min polygon contains no freeglers) is misleading.
    if (estimatedInsideLocated > 0 && totalLocated > 0) {
      const totalEstimate = Math.round(estimatedInsideLocated / (1 - UNLOCATED_FRACTION))
      const unlocatedShare = totalEstimate - estimatedInsideLocated
      bar.innerHTML =
        `<div style="font-size:13px;font-weight:600;color:#333;line-height:1.4">~${totalEstimate.toLocaleString()} would be notified</div>` +
        `<div style="font-size:10px;color:#666;margin-top:1px">${estimatedInsideLocated.toLocaleString()} with known location` +
        (unlocatedShare > 0
          ? ` + ~${unlocatedShare.toLocaleString()} estimated unlocated`
          : '') +
        `</div><div style="font-size:10px;color:#aaa;font-style:italic;margin-top:3px">TrashNothing members use a separate algorithm</div>`
      bar.style.display = ''
    } else {
      bar.style.display = 'none'
    }

    // The quintile polygon approach for classifying freeglers is unreliable:
    // a 19-point concave hull for 6,000 scattered Q1 nodes smears over Q4/Q5
    // areas, producing a deprived-biased reading.  Instead, use the routing
    // server's own fairness_score (Q1-3 nodes / all tagged nodes) which is
    // correct.  updateStats(data) already rendered the needle at this value;
    // we just need to track the peak bias for the animation summary.
    if (data.fairness_score !== undefined && data.fairness_score >= 0) {
      const pct = Math.round(data.fairness_score * 100)

      if (ripplePlaying) {
        const imbalance = Math.abs(pct - localBaseline)
        if (rippleMaxImbalance === null || imbalance > Math.abs(rippleMaxImbalance.pct - localBaseline)) {
          // rippleStep is 0-based frame index; actual drive time = (step+1)*RIPPLE_STEP_MINS.
          rippleMaxImbalance = { pct, minute: (rippleStep + 1) * RIPPLE_STEP_MINS }
        }
      }
    }
  }

  function groupCentroid(f) {
    const coords =
      f.geometry && f.geometry.coordinates && f.geometry.coordinates[0]
    if (!coords || !coords.length) return [0, 0]
    let sumLng = 0
    let sumLat = 0
    coords.forEach(([lng, lat]) => {
      sumLng += lng
      sumLat += lat
    })
    return [sumLng / coords.length, sumLat / coords.length]
  }

  function distSq(lat1, lng1, lat2, lng2) {
    const dlat = lat1 - lat2
    const dlng = (lng1 - lng2) * Math.cos((lat1 * Math.PI) / 180)
    return dlat * dlat + dlng * dlng
  }

  let groupLayerMap = {}
  let groupFeatures = []
  let homeGroupIds = new Set()

  async function fetchAndDrawGroups(lat, lng) {
    Object.values(groupLayerMap).forEach((l) => map.removeLayer(l))
    groupLayerMap = {}
    groupFeatures = []
    homeGroupIds = new Set()
    try {
      const url = apiUrl(
        `/v1/groups/nearby?lat=${lat.toFixed(6)}&lng=${lng.toFixed(6)}`
      )
      const r = await fetch(url)
      if (!r.ok) return
      const data = await r.json()
      groupFeatures = data.features || []
      groupFeatures.forEach((f) => {
        if (f.properties.contains) homeGroupIds.add(f.properties.id)
      })
      drawGroupsOverlay()
    } catch (e) {
      /* no group data — silently skip */
    }
  }

  function ringsOverlap(ring1, ring2) {
    let r1minX = Infinity
    let r1maxX = -Infinity
    let r1minY = Infinity
    let r1maxY = -Infinity
    let r2minX = Infinity
    let r2maxX = -Infinity
    let r2minY = Infinity
    let r2maxY = -Infinity
    for (const [x, y] of ring1) {
      if (x < r1minX) r1minX = x
      if (x > r1maxX) r1maxX = x
      if (y < r1minY) r1minY = y
      if (y > r1maxY) r1maxY = y
    }
    for (const [x, y] of ring2) {
      if (x < r2minX) r2minX = x
      if (x > r2maxX) r2maxX = x
      if (y < r2minY) r2minY = y
      if (y > r2maxY) r2maxY = y
    }
    if (
      r1maxX < r2minX ||
      r2maxX < r1minX ||
      r1maxY < r2minY ||
      r2maxY < r1minY
    )
      return false
    for (const [lng, lat] of ring1) {
      if (pointInRing(lng, lat, ring2)) return true
    }
    for (const [lng, lat] of ring2) {
      if (pointInRing(lng, lat, ring1)) return true
    }
    for (let i = 0; i < ring1.length - 1; i++) {
      const [ax, ay] = ring1[i]
      const [bx, by] = ring1[i + 1]
      for (let j = 0; j < ring2.length - 1; j++) {
        const [cx, cy] = ring2[j]
        const [dx, dy] = ring2[j + 1]
        if (segmentsIntersect(ax, ay, bx, by, cx, cy, dx, dy)) return true
      }
    }
    return false
  }

  function allIsoRings(isoData) {
    const rings = []
    if (!isoData) return rings
    if (hasRing(isoData.standard))
      rings.push(isoData.standard.geometry.coordinates[0])
    for (let q = 1; q <= 5; q++) {
      const qr = (isoData.quintiles || {})[q]
      if (!qr) continue
      if (hasRing(qr.polygon)) rings.push(qr.polygon.geometry.coordinates[0])
      for (const isl of qr.islands || []) {
        if (hasRing(isl)) rings.push(isl.geometry.coordinates[0])
      }
    }
    return rings
  }

  function reachedGroupIds(isoData) {
    const reached = new Set()
    // Use only the standard (un-adjusted) travel boundary to decide which groups
    // have been reached.  Fairness-adjustment islands can extend to distant
    // deprived towns and would otherwise falsely mark those groups as reached.
    if (!isoData || !hasRing(isoData.standard)) return reached
    const isoRing = isoData.standard.geometry.coordinates[0]
    for (const f of groupFeatures) {
      const gRing =
        f.geometry && f.geometry.coordinates && f.geometry.coordinates[0]
      if (!gRing || gRing.length < 4) continue
      if (ringsOverlap(isoRing, gRing)) reached.add(f.properties.id)
    }
    return reached
  }

  function drawGroupsOverlay() {
    const listEl = document.getElementById('rippling-groups-list')
    const sectionEl = document.getElementById('rippling-groups-section')

    if (!showGroups) {
      sectionEl.style.display = 'none'
      return
    }
    if (groupFeatures.length === 0) {
      sectionEl.style.display = 'none'
      return
    }
    sectionEl.style.display = ''

    const reached = reachedGroupIds(lastIsoData)

    // Find the nearest group by centroid distance first, so we can include it
    // in both the polygon display and the sidebar list as a fallback home group.
    let nearestGroupId = null
    let nearestDist = Infinity
    if (currentLat !== null) {
      groupFeatures.forEach((f) => {
        const [cLng, cLat] = groupCentroid(f)
        const d = distSq(currentLat, currentLng, cLat, cLng)
        if (d < nearestDist) {
          nearestDist = d
          nearestGroupId = f.properties.id
        }
      })
    }

    // Show: home (contains=true), reached cross-posting groups, and always
    // the nearest group (acts as home when ST_Contains misses due to boundaries).
    function groupIsRelevant(f) {
      return (
        f.properties.contains ||
        reached.has(f.properties.id) ||
        f.properties.id === nearestGroupId
      )
    }

    const sorted = [...groupFeatures].filter(groupIsRelevant).sort((a, b) => {
      if (a.properties.contains !== b.properties.contains)
        return a.properties.contains ? -1 : 1
      if (currentLat === null) return 0
      const cA = groupCentroid(a)
      const cB = groupCentroid(b)
      return (
        distSq(currentLat, currentLng, cA[1], cA[0]) -
        distSq(currentLat, currentLng, cB[1], cB[0])
      )
    })

    // Compute which IDs should have visible polygons (same criteria as the list).
    const visibleIds = new Set()
    groupFeatures.forEach((f) => {
      const coords =
        f.geometry && f.geometry.coordinates && f.geometry.coordinates[0]
      if (!coords || coords.length < 4) return
      if (groupIsRelevant(f)) {
        visibleIds.add(f.properties.id)
      }
    })

    // Remove layers for groups no longer visible
    Object.keys(groupLayerMap).forEach((id) => {
      if (!visibleIds.has(Number(id))) {
        map.removeLayer(groupLayerMap[id])
        delete groupLayerMap[id]
      }
    })

    // Add layers for newly visible groups
    groupFeatures.forEach((f) => {
      const coords =
        f.geometry && f.geometry.coordinates && f.geometry.coordinates[0]
      if (!coords || coords.length < 4) return
      const id = f.properties.id
      if (!visibleIds.has(id)) return
      if (groupLayerMap[id]) return
      const isHome = f.properties.contains || id === nearestGroupId
      const latlngs = coords.map(([lng, lat]) => [lat, lng])
      groupLayerMap[id] = L.polygon(latlngs, {
        color: '#27ae60',
        weight: isHome ? 3 : 2,
        fillColor: '#27ae60',
        fillOpacity: isHome ? 0.10 : 0.05,
        dashArray: null,
      })
        .addTo(map)
        .bindTooltip(
          (isHome ? '🏠 ' : '') + (f.properties.nameshort || 'Group'),
          { sticky: true }
        )
    })

    listEl.innerHTML = ''
    sorted.forEach((f) => {
      const isHome = f.properties.contains
      const postShows = isHome || reached.has(f.properties.id)
      const dotColor = isHome ? '#27ae60' : postShows ? '#27ae60' : '#e74c3c'
      const item = document.createElement('div')
      item.style.cssText =
        'display:flex;align-items:center;gap:5px;padding:1px 0'
      item.innerHTML =
        `<span style="width:10px;height:10px;border-radius:50%;background:${dotColor};flex-shrink:0;display:inline-block"></span>` +
        `<span>${isHome ? '<b>' : ''}${f.properties.nameshort || '(unnamed)'}${
          isHome ? '</b>' : ''
        }</span>` +
        (isHome ? ' <span style="color:#888;font-size:10px">(home)</span>' : '')
      listEl.appendChild(item)
    })

    if (sorted.length === 0) {
      listEl.innerHTML =
        '<span style="color:#aaa;font-style:italic">None visible in current view</span>'
    }
  }

  function checkCrossPosting(isoRing) {
    if (!isoRing || groupFeatures.length === 0) return null
    for (const f of groupFeatures) {
      if (f.properties.contains) continue
      const gRing =
        f.geometry && f.geometry.coordinates && f.geometry.coordinates[0]
      if (!gRing) continue
      for (const [lng, lat] of isoRing) {
        if (pointInRing(lng, lat, gRing)) return f
      }
      for (const [lng, lat] of gRing) {
        if (pointInRing(lng, lat, isoRing)) return f
      }
    }
    return null
  }

  function markCrossPosting(hours, groupName, hitFeature) {
    const pct = hoursToLogPct(hours)
    const layer = document.getElementById('rippling-tl-tick-layer')

    const mark = document.createElement('div')
    mark.className = 'rpl-tick-mark'
    mark.style.cssText = `left:${pct}%;background:#e07000;height:10px;top:-8px;width:2px`
    layer.appendChild(mark)

    const label = document.createElement('div')
    const xform =
      pct < 15
        ? 'translateX(0)'
        : pct > 80
        ? 'translateX(-100%)'
        : 'translateX(-50%)'
    label.style.cssText = `position:absolute;left:${pct}%;top:-22px;color:#e07000;font-size:10px;font-weight:700;white-space:nowrap;transform:${xform}`
    label.textContent = '⚡'
    layer.appendChild(label)

    document.getElementById('rippling-tl-elapsed').textContent =
      formatElapsed(hours) +
      ' — cross-posting begins (' +
      (groupName || 'adjacent group') +
      ')'

    if (hitFeature) {
      const coords =
        hitFeature.geometry &&
        hitFeature.geometry.coordinates &&
        hitFeature.geometry.coordinates[0]
      if (coords) {
        const flashLyr = L.polygon(
          coords.map(([lng, lat]) => [lat, lng]),
          {
            color: '#e07000',
            weight: 3,
            fillColor: '#e07000',
            fillOpacity: 0.18,
          }
        ).addTo(map)
        setTimeout(() => {
          if (map.hasLayer(flashLyr)) map.removeLayer(flashLyr)
          drawGroupsOverlay()
        }, 2500)
      }
    }

    // Pause the rAF loop for 2 s so the cross-posting flash is visible.
    // Snapshot the current position into the anchor so resume picks up
    // exactly where it paused (rather than rewinding to the old anchor).
    if (ripplePlaying && rippleRafId !== null) {
      const pausedAt = currentFramePosition(performance.now())
      cancelAnimationFrame(rippleRafId)
      rippleRafId = null
      setTimeout(() => {
        if (!ripplePlaying) return
        rippleAnchorFrame = pausedAt
        rippleAnchorTime = performance.now()
        rippleRafId = requestAnimationFrame(animateRipple)
      }, 2000)
    }
  }

  const EXPANSION_HOURS = [0, 1, 3, 6, 12, 24, 48, 72, 120, 168, 336, 720]
  const MAX_HOURS = 720
  let timelineBuilt = false

  function frameToHours(frameIdx, totalFrames) {
    return (frameIdx / (totalFrames - 1)) * MAX_HOURS
  }

  function hoursToLogPct(hours) {
    return (Math.log10(hours + 1) / Math.log10(MAX_HOURS + 1)) * 100
  }

  function formatElapsed(hours) {
    if (hours < 0.083) return 'Just posted'
    if (hours < 1) return Math.round(hours * 60) + 'm elapsed'
    const h = Math.floor(hours)
    if (h < 24) return h + 'h elapsed'
    const d = Math.floor(h / 24)
    const hr = h % 24
    return d + 'd' + (hr ? ' ' + hr + 'h' : '') + ' elapsed'
  }

  function buildTimeline(totalFrames) {
    if (timelineBuilt) return
    timelineBuilt = true
    const slider = document.getElementById('rippling-tl-slider')
    slider.max = totalFrames - 1
    const layer = document.getElementById('rippling-tl-tick-layer')
    layer.innerHTML = ''
    const n = EXPANSION_HOURS.length
    EXPANSION_HOURS.forEach((h, idx) => {
      const pct = hoursToLogPct(h)
      const isLast = idx === n - 1

      const mark = document.createElement('div')
      mark.className = 'rpl-tick-mark' + (h > 0 ? ' rpl-expansion' : '')
      mark.style.left = pct + '%'
      layer.appendChild(mark)

      if (h === 0) return

      const label = document.createElement('div')
      let cls = 'rpl-tick rpl-expansion'
      if (isLast) cls += ' rpl-edge-right'
      label.className = cls
      label.style.left = pct + '%'
      label.textContent =
        h < 24 ? h + 'h' : h % 24 === 0 ? h / 24 + 'd' : h + 'h'
      layer.appendChild(label)
    })
  }

  function updateTimeline(frameIdx, totalFrames) {
    const hours = frameToHours(frameIdx, totalFrames)
    const pct = hoursToLogPct(hours)
    const slider = document.getElementById('rippling-tl-slider')
    slider.value = frameIdx
    slider.style.setProperty('--tl-pct', pct.toFixed(2) + '%')
    document.getElementById('rippling-tl-elapsed').textContent =
      formatElapsed(hours)
  }

  function jumpToFrame(frameIdx) {
    if (!rippleFrames.length) return
    frameIdx = Math.max(0, Math.min(frameIdx, rippleFrames.length - 1))
    // Cancel any in-flight rAF; we're jumping to a discrete frame.
    if (rippleRafId !== null) {
      cancelAnimationFrame(rippleRafId)
      rippleRafId = null
    }
    rippleStep = frameIdx
    // Anchor playback time so Resume continues smoothly from this frame.
    rippleAnchorFrame = frameIdx
    rippleAnchorTime = 0
    const data = rippleFrames[frameIdx]
    updateTimeline(frameIdx, rippleFrames.length)
    if (data) {
      drawPolygons(data, 0)
      updateStats(data)
      updateFreeglersInside(data)
      drawGroupsOverlay()
    }
  }

  // ---------------------------------------------------------------------------
  // Ripple animation architecture
  // ---------------------------------------------------------------------------
  // The notification cron will step drive-time radius by 1 minute per N wall-
  // clock minutes (config in freegle.php).  We therefore fetch one keyframe per
  // drive-minute (30 frames covering 0..30 min) and morph smoothly between them
  // on the client using RADIAL INTERPOLATION:
  //
  //   1.  Each polygon ring is reparameterised as `radius(θ)` — for each of
  //       N_ANGLES equally-spaced angles around the message origin, ray-cast
  //       outwards and record the distance to the polygon boundary.
  //   2.  Interpolation between frames is then `r_t[i] = r_a[i] + t*(r_b[i]-r_a[i])`
  //       for each angle — constant cost, no vertex-correspondence problem.
  //   3.  At rAF time, the interpolated radius array is converted back to
  //       lat/lng and applied to a single Leaflet polygon.
  //
  // This is physically correct (each ray sweeps outward as drive time grows)
  // and works regardless of source-frame vertex counts.
  // ---------------------------------------------------------------------------
  const RIPPLE_FRAMES = 30          // 30 keyframes = 1 per drive-minute
  const RIPPLE_STEP_MINS = 1
  const N_ANGLES = 360              // resolution of the radial parameterisation

  let rippleFrames = []
  let rippleStep = 0
  let ripplePlaying = false
  let crossPostingDetected = false
  let rippleMaxImbalance = null  // {pct, minute} — worst affluence bias seen during animation

  // rAF playback state.  rippleAnchorFrame + rippleAnchorTime define the
  // reference point for "current playback position"; current frame fraction
  // at any rAF tick = anchorFrame + (now - anchorTime) / msPerFrame.
  let rippleRafId = null
  let rippleAnchorFrame = 0
  let rippleAnchorTime = 0
  let rippleLastStatsFrame = -1

  // ---------------------------------------------------------------------------
  // Radial polygon parameterisation
  // ---------------------------------------------------------------------------
  // Each closed polygon ring is converted into a Float32Array of length N_ANGLES
  // holding the distance (in lat/lng units, scaled by cos(lat) for longitude)
  // from the origin to the polygon boundary at each evenly-spaced angle.
  //
  // For each angle θ_i we ray-cast outwards from the origin and find every
  // intersection with a ring edge; we keep the maximum t-value so concave
  // shapes (motorway extensions, river fingers) trace to the OUTER boundary
  // rather than to a closer cavity edge.
  // ---------------------------------------------------------------------------

  /**
   * Compute distance from origin to polygon along ray at angle θ.
   * Uses ray-segment intersection across all polygon edges; returns the
   * farthest intersection so concave inward dents don't truncate the radius.
   */
  function radiusAtAngle(ring, originLng, originLat, cosLat, cosT, sinT) {
    // Ray direction in (lng-equivalent, lat) space: lng scaled by 1/cosLat so
    // that the ray is equiangular in metres-equivalent space.
    const dx = cosT / cosLat
    const dy = sinT
    let maxT = 0
    for (let i = 0; i < ring.length - 1; i++) {
      const ax = ring[i][0] - originLng
      const ay = ring[i][1] - originLat
      const bx = ring[i + 1][0] - originLng
      const by = ring[i + 1][1] - originLat
      const ex = bx - ax
      const ey = by - ay
      // Solve: (ax, ay) + s*(ex, ey) = t*(dx, dy), with s ∈ [0,1], t ≥ 0.
      const det = dx * ey - dy * ex
      if (Math.abs(det) < 1e-12) continue
      const t = (ax * ey - ay * ex) / det
      const s = (ax * dy - ay * dx) / det
      if (t >= 0 && s >= 0 && s <= 1 && t > maxT) maxT = t
    }
    return maxT
  }

  /**
   * Reparameterise a polygon ring as a radius-per-angle array.
   * Returns Float32Array(N_ANGLES) with radii in the same coordinate
   * convention as radiusAtAngle (lat units; longitude pre-scaled).
   */
  function ringToRadii(ring, originLng, originLat) {
    const cosLat = Math.cos((originLat * Math.PI) / 180)
    const radii = new Float32Array(N_ANGLES)
    if (!ring || ring.length < 3) return radii
    // Ensure ring is closed.
    const closed =
      ring[0][0] === ring[ring.length - 1][0] &&
      ring[0][1] === ring[ring.length - 1][1]
        ? ring
        : [...ring, ring[0]]
    for (let i = 0; i < N_ANGLES; i++) {
      const theta = (i / N_ANGLES) * 2 * Math.PI
      radii[i] = radiusAtAngle(
        closed,
        originLng,
        originLat,
        cosLat,
        Math.cos(theta),
        Math.sin(theta)
      )
    }
    return radii
  }

  /**
   * Convert a radii array back to a Leaflet-friendly [lat, lng] coordinate ring.
   */
  function radiiToLatLngRing(radii, originLng, originLat) {
    const cosLat = Math.cos((originLat * Math.PI) / 180)
    const out = new Array(N_ANGLES + 1)
    for (let i = 0; i < N_ANGLES; i++) {
      const theta = (i / N_ANGLES) * 2 * Math.PI
      const r = radii[i]
      const lng = originLng + (r * Math.cos(theta)) / cosLat
      const lat = originLat + r * Math.sin(theta)
      out[i] = [lat, lng]
    }
    out[N_ANGLES] = out[0]
    return out
  }

  /**
   * Linearly interpolate between two radii arrays.
   */
  function lerpRadii(a, b, t) {
    const out = new Float32Array(N_ANGLES)
    for (let i = 0; i < N_ANGLES; i++) {
      out[i] = a[i] + t * (b[i] - a[i])
    }
    return out
  }

  /**
   * Precompute radii for every keyframe's standard ring.  Called once after
   * frames are loaded so the rAF hot path is pure arithmetic.
   */
  function precomputeRadii(frames, originLng, originLat) {
    const zeroRadii = new Float32Array(N_ANGLES)
    frames.forEach((data) => {
      if (data && hasRing(data.standard)) {
        data._radii = ringToRadii(
          data.standard.geometry.coordinates[0],
          originLng,
          originLat
        )
      } else {
        data._radii = zeroRadii
      }
    })
  }

  document.getElementById('rippling-btn').addEventListener('click', () => {
    if (ripplePlaying) stopRipple()
    else startRipple()
  })

  document
    .getElementById('rippling-tl-slider')
    .addEventListener('input', function () {
      const frameIdx = parseInt(this.value)
      if (ripplePlaying) {
        if (rippleRafId !== null) {
          cancelAnimationFrame(rippleRafId)
          rippleRafId = null
        }
        ripplePlaying = false
        const btn = document.getElementById('rippling-btn')
        btn.textContent = '▶ Resume'
        btn.classList.remove('rpl-stop')
      }
      jumpToFrame(frameIdx)
    })

  async function startRipple() {
    if (currentLat === null) {
      showStatus('Click a location first', false)
      return
    }

    // Ripple always uses drive mode.
    if (currentMode !== 'drive') {
      currentMode = 'drive'
      document.querySelectorAll('.rpl-mode-btn').forEach((b) => {
        b.classList.toggle('rpl-active', b.dataset.mode === 'drive')
      })
      if (currentLat !== null) updateIsochrone()
    }

    const btn = document.getElementById('rippling-btn')
    btn.disabled = true
    btn.textContent = '⏳ Loading…'
    document.getElementById(
      'rippling-info'
    ).textContent = `Fetching ${RIPPLE_FRAMES} frames (drive)…`

    clearLayers()
    timelineBuilt = false
    document.getElementById('rippling-tl-tick-layer').innerHTML = ''
    document.getElementById('rippling-tl-slider').value = 0
    document
      .getElementById('rippling-tl-slider')
      .style.setProperty('--tl-pct', '0%')
    map.setView([currentLat, currentLng], 13, { animate: false })

    // Density-driven schedule.  One HTTP call returns the full ticks-tuple of
    // (drive-time, cumulative_users, polygon) — the server picks each tick's
    // drive-time so an equal-population batch is encapsulated at each step.
    // The "curve" parameter shapes the cumulative-fraction-vs-tick mapping.
    // Default to the data-driven recommended curve — see
    // plans/reference/ripple-curve-evaluation.md.  step-70 (70 % notified
    // at tick 1 then linear) hits 92 % urban / 86 % rural first-replier
    // reach-in-time on the 4,264-post historical sample with ~7 % waste.
    const curveShape = 'step-70'
    const scheduleURL = apiUrl(
      `/v1/ripple-schedule?lat=${currentLat.toFixed(6)}&lng=${currentLng.toFixed(
        6
      )}&mode=${currentMode}&ticks=${RIPPLE_FRAMES}&max_minutes=${RIPPLE_FRAMES * RIPPLE_STEP_MINS}&curve=${curveShape}`
    )
    let scheduleResp
    try {
      scheduleResp = await fetch(scheduleURL).then((r) => r.json())
    } catch (e) {
      scheduleResp = null
    }
    if (!scheduleResp || !scheduleResp.schedule || scheduleResp.schedule.length === 0) {
      document.getElementById('rippling-info').textContent =
        'No ripple data — try a different location'
      btn.disabled = false
      btn.textContent = '▶ Animate ripple'
      ripplePlaying = false
      return
    }
    // Wrap each schedule entry to look like the previous "frame" shape, so the
    // rest of the animation code is unchanged.  `standard` carries the polygon;
    // `drive_min` and `cumulative_users` are exposed for display/stats.
    rippleFrames = scheduleResp.schedule.map((entry) => ({
      standard: entry.polygon,
      drive_min: entry.drive_min,
      cumulative_users: entry.cumulative_users,
      tick: entry.tick,
    }))
    // Make the total reachable count available for the freegler bar display.
    totalLocatedFromServer = scheduleResp.total_freeglers || totalLocatedFromServer

    // Reparameterise every keyframe's standard ring as a radii array, once,
    // so the rAF hot path is pure arithmetic.
    precomputeRadii(rippleFrames, currentLng, currentLat)

    // Fetch all freeglers reachable at the maximum drive time so the dots
    // are loaded before the animation needs them.
    await fetchFreeglers(RIPPLE_FRAMES * RIPPLE_STEP_MINS)
    drawFreeglersLayer()

    // Create the single morphing polygon layer used by the rAF loop.
    ensureMorphLayer()

    rippleStep = 0
    rippleAnchorFrame = 0
    rippleAnchorTime = 0
    rippleLastStatsFrame = -1
    ripplePlaying = true
    crossPostingDetected = false
    rippleMaxImbalance = null
    btn.disabled = false
    btn.textContent = '⏹ Stop'
    btn.classList.add('rpl-stop')
    document.getElementById('rippling-freegler-bar').style.display = ''
    buildTimeline(rippleFrames.length)
    document.getElementById('rippling-timeline').style.display = ''

    rippleRafId = requestAnimationFrame(animateRipple)
  }

  function stopRipple() {
    ripplePlaying = false
    crossPostingDetected = false
    rippleFrames = []
    timelineBuilt = false
    if (rippleRafId !== null) {
      cancelAnimationFrame(rippleRafId)
      rippleRafId = null
    }
    removeMorphLayer()
    const btn = document.getElementById('rippling-btn')
    btn.textContent = '▶ Animate ripple'
    btn.classList.remove('rpl-stop')
    document.getElementById(
      'rippling-info'
    ).textContent = `by ${currentMode} · ${RIPPLE_STEP_MINS}–${RIPPLE_FRAMES} min`
    document.getElementById('rippling-freegler-bar').style.display = 'none'
    document.getElementById('rippling-timeline').style.display = 'none'
    if (currentLat !== null) fetchAndDrawGroups(currentLat, currentLng)
    else drawGroupsOverlay()
  }

  /**
   * The single morphing standard-ring polygon, rebuilt every rAF tick.
   * Kept separate from the quintile polygons (which are drawn at keyframe
   * boundaries by drawPolygons) so the hot path only updates one path.
   */
  let morphLayer = null
  function ensureMorphLayer() {
    if (morphLayer && map.hasLayer(morphLayer)) return
    morphLayer = L.polygon([], {
      color: '#cc0000',
      weight: 2.5,
      fillColor: 'none',
      fillOpacity: 0,
      opacity: 1,
    }).addTo(map)
    // Disable any path d-transition on the morph layer — we're driving the
    // shape ourselves at 60 fps, so a CSS transition would lag the rAF loop.
    const el = morphLayer.getElement()
    if (el) el.style.transition = 'none'
  }
  function removeMorphLayer() {
    if (morphLayer && map.hasLayer(morphLayer)) map.removeLayer(morphLayer)
    morphLayer = null
  }

  /**
   * Map the user's speed slider (1..10) to milliseconds per keyframe.
   * Default speed 3 → ~800 ms per drive-minute → 24 s for the full 30-min ripple.
   */
  function getMsPerFrame() {
    const spd =
      parseInt(document.getElementById('rippling-speed-slider').value) || 3
    return Math.max(50, Math.round(2400 / Math.pow(spd, 1.2)))
  }

  /**
   * Compute the fractional frame position at a given rAF timestamp.
   * Anchor + elapsed model so jumpToFrame and Pause/Resume work cleanly.
   */
  function currentFramePosition(now) {
    if (rippleAnchorTime === 0) rippleAnchorTime = now
    const elapsed = now - rippleAnchorTime
    return rippleAnchorFrame + elapsed / getMsPerFrame()
  }

  function animateRipple(now) {
    rippleRafId = null
    if (!ripplePlaying || !rippleFrames.length) return

    const position = currentFramePosition(now)
    const maxIdx = rippleFrames.length - 1

    // ------------------------------------------------------------------
    // Done?
    // ------------------------------------------------------------------
    if (position >= maxIdx) {
      // Pin to the last keyframe.  Remove the morph layer and let drawPolygons
      // own the standard ring in the static end state, matching pre-ripple UI.
      rippleStep = maxIdx
      const last = rippleFrames[maxIdx]
      removeMorphLayer()
      if (last) {
        drawPolygons(last, 0)
        updateStats(last)
        updateFreeglersInside(last)
      }
      ripplePlaying = false
      const btn = document.getElementById('rippling-btn')
      btn.textContent = '▶ Replay'
      btn.classList.remove('rpl-stop')
      let doneText = `${currentMode} · done`
      if (rippleMaxImbalance !== null) {
        const bias = rippleMaxImbalance.pct < localBaseline ? 'affluent' : 'deprived'
        const diff = Math.abs(rippleMaxImbalance.pct - localBaseline)
        doneText += ` · peak ${bias} bias: ${diff}% at ${rippleMaxImbalance.minute} min`
      }
      document.getElementById('rippling-info').textContent = doneText
      drawGroupsOverlay()
      return
    }

    // ------------------------------------------------------------------
    // Interpolate the standard ring between two keyframes (every rAF tick).
    // ------------------------------------------------------------------
    const frameA = Math.floor(position)
    const frameB = Math.min(frameA + 1, maxIdx)
    const t = position - frameA
    const radii = lerpRadii(rippleFrames[frameA]._radii, rippleFrames[frameB]._radii, t)
    drawMorphedRing(radii)

    // ------------------------------------------------------------------
    // Update per-keyframe state (stats, quintiles, timeline) only when we
    // cross into a new keyframe.  This keeps the rAF loop cheap.
    // ------------------------------------------------------------------
    if (frameA !== rippleLastStatsFrame) {
      rippleLastStatsFrame = frameA
      rippleStep = frameA
      const data = rippleFrames[frameA]
      // The density-driven schedule provides drive_min and cumulative_users
      // per tick, set by the server.  Drive-time is non-monotonic in spacing
      // (small in dense regions, jumps across voids) — the info text shows
      // the actual values so the moderator can see the algorithm working.
      const mDrive = data && data.drive_min !== undefined
        ? data.drive_min
        : (frameA + 1) * RIPPLE_STEP_MINS
      const minuteLabel = Number.isInteger(mDrive) ? String(mDrive) : mDrive.toFixed(2)
      const tickLabel = `tick ${frameA + 1}/${rippleFrames.length}`
      const usersLabel = data && data.cumulative_users !== undefined
        ? ` · ${data.cumulative_users.toLocaleString()} reached`
        : ''
      document.getElementById(
        'rippling-info'
      ).textContent = `${tickLabel} · ${minuteLabel} drive-min${usersLabel}`
      updateTimeline(frameA, rippleFrames.length)
      timeSlider.value = Math.min(30, Math.round(mDrive))

      if (data) {
        // The schedule polygons are pure drive-time (no fairness/quintile
        // info).  Suppress the quintile redraw — there's nothing to draw —
        // and let the morph layer own the standard ring.
        updateFreeglersInside(data)
        drawGroupsOverlay()
        drawFreeglersLayer()

        const hours = frameToHours(frameA, rippleFrames.length)
        if (
          !crossPostingDetected &&
          hours >= 24 &&
          groupFeatures.length > 0 &&
          data.standard &&
          hasRing(data.standard)
        ) {
          const isoRing = data.standard.geometry.coordinates[0]
          const hit = checkCrossPosting(isoRing)
          if (hit) {
            crossPostingDetected = true
            markCrossPosting(hours, hit.properties.nameshort, hit)
          }
        }

        // Re-fit the map periodically to keep the boundary in view.
        if (frameA % 3 === 0) {
          const ahead = rippleFrames[Math.min(frameA + 3, maxIdx)]
          const zoomData = ahead && hasRing(ahead.standard) ? ahead : data
          if (hasRing(zoomData.standard)) {
            try {
              const ring = zoomData.standard.geometry.coordinates[0]
              const bounds = L.latLngBounds(ring.map(([lng, lat]) => [lat, lng]))
              if (bounds.isValid())
                map.fitBounds(bounds, {
                  padding: [60, 60],
                  maxZoom: 13,
                  animate: true,
                  duration: 0.4,
                })
            } catch (e) {}
          }
        }
      }
    }

    rippleRafId = requestAnimationFrame(animateRipple)
  }

  /**
   * Apply an interpolated radii array to the morphing polygon layer.
   * Cheap: N_ANGLES vertex projections + one setLatLngs call.
   */
  function drawMorphedRing(radii) {
    if (!morphLayer || !map.hasLayer(morphLayer)) ensureMorphLayer()
    const latlngs = radiiToLatLngRing(radii, currentLng, currentLat)
    morphLayer.setLatLngs(latlngs)
  }
})

onUnmounted(() => {
  cleanupFns.forEach((fn) => fn())
  if (map) {
    map.remove()
    map = null
  }
})
</script>

<style>
/* Leaflet overrides — unscoped so Leaflet's own DOM nodes are styled */
#rippling-root * {
  box-sizing: border-box;
}

/* Inbound section groupings — make it visually clear that the
   maximum-reach slider drives "what's in the digest" (pie chart) and
   that the other sliders drive "sort order" (mock-up). */
.rpl-sim-group {
  margin-top: 8px;
  padding-bottom: 2px;
}
.rpl-sim-group-title {
  font-size: 11px;
  font-weight: 700;
  color: #4d8b1d;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 1.5px solid #61ae24;
  padding-bottom: 2px;
  margin-bottom: 3px;
}
.rpl-sim-group-sub {
  font-size: 10px;
  color: #777;
  line-height: 1.4;
  margin-bottom: 4px;
}

/* The maximum-reach slider row (#rippling-time-row) sits inside the
   "What's in the digest" visual group when inbound mode is active —
   give it a subtle left rail so it's clearly grouped with the heading
   above and the pie chart below. */
#rippling-panel-body:has(#rippling-sim-contents:not([style*='display: none'])) #rippling-time-row,
#rippling-panel-body:has(#rippling-sim-contents:not([style*='display: none'])) #rippling-sim-pie-wrap {
  border-left: 2px solid #d9eccb;
  padding-left: 8px;
  margin-left: -2px;
}

/* "Sort order" group — the inbound-row gets a similar left rail to
   visually pair with its heading and the digest mock-up button it
   contains. */
#rippling-inbound-row {
  border-left: 2px solid #d9eccb;
  padding-left: 8px;
  margin-left: -2px;
  margin-top: 10px;
}

/* Intro text under the search box */
.rpl-intro {
  font-size: 10.5px;
  color: #555;
  background: #f6f9f1;
  border-left: 3px solid #61ae24;
  padding: 6px 8px;
  border-radius: 3px;
  line-height: 1.4;
  margin: 8px 0;
}

/* Show digest mock-up button */
.rpl-digest-btn {
  margin-top: 8px;
  width: 100%;
  background: #61ae24;
  color: #fff;
  border: none;
  border-radius: 4px;
  padding: 6px 8px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
}
.rpl-digest-btn:hover {
  background: #4d8b1d;
}

/* Modal CSS now lives inside RipplingDigestModal.vue (component-scoped). */

/* Digest-simulator knobs */
.rpl-sim-knob {
  margin: 6px 0;
  padding: 4px 6px 5px;
  border: 1px solid #ececec;
  border-radius: 4px;
  background: #fafafa;
}
.rpl-sim-knob label {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: #555;
  margin-bottom: 1px;
}
.rpl-sim-knob input[type='range'] {
  width: 100%;
  margin: 2px 0;
}
.rpl-sim-help {
  font-size: 9.5px;
  color: #888;
  line-height: 1.3;
  margin-top: 1px;
}

/* Smooth polygon boundary crawl: CSS d-attribute transitions let the browser
   interpolate SVG path shapes between frames when the vertex count is stable.
   Leaflet's setLatLngs calls path.setAttribute('d', …) directly, so any CSS
   transition on `d` takes effect here.  When vertex counts change the browser
   falls back to a discrete jump — that's acceptable since rapid count changes
   only occur during fast isochrone growth. */
#rippling-map .leaflet-overlay-pane path {
  transition: d 150ms ease-out;
}

#rippling-panel {
  position: absolute;
  top: 10px;
  right: 10px;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 3px 12px rgba(0, 0, 0, 0.25);
  width: 290px;
  z-index: 1000;
  overflow: hidden;
}
#rippling-panel-header {
  background: #61ae24;
  color: #fff;
  padding: 12px 16px;
  font-weight: 700;
  font-size: 15px;
  letter-spacing: 0.3px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
#rippling-panel-body {
  padding: 14px 16px 16px;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

#rippling-search-wrap {
  position: relative;
  margin-bottom: 12px;
}
#rippling-search-box {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #ccc;
  border-radius: 6px;
  font-size: 13px;
  outline: none;
}
#rippling-search-box:focus {
  border-color: #61ae24;
}
#rippling-search-results {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: #fff;
  border: 1px solid #ccc;
  border-top: none;
  border-radius: 0 0 6px 6px;
  list-style: none;
  margin: 0;
  padding: 0;
  z-index: 10;
  max-height: 180px;
  overflow-y: auto;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}
#rippling-search-results li {
  padding: 7px 10px;
  cursor: pointer;
  font-size: 12px;
  border-bottom: 1px solid #f0f0f0;
  line-height: 1.3;
}
#rippling-search-results li:hover {
  background: #f2f9e6;
}
#rippling-search-results li:last-child {
  border-bottom: none;
}

.rpl-mode-row {
  display: flex;
  gap: 6px;
  margin-bottom: 14px;
}
.rpl-mode-btn {
  flex: 1;
  padding: 7px 4px;
  border: 1.5px solid #ddd;
  border-radius: 6px;
  background: #fafafa;
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  color: #555;
  transition: all 0.15s;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.rpl-icon {
  font-size: 18px;
}
.rpl-mode-btn:hover {
  border-color: #61ae24;
  color: #61ae24;
  background: #f2f9e6;
}
.rpl-mode-btn.rpl-active {
  border-color: #61ae24;
  background: #61ae24;
  color: #fff;
}

.rpl-slider-row {
  margin-bottom: 12px;
}
.rpl-slider-label {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: #666;
  margin-bottom: 4px;
  font-weight: 500;
}
.rpl-val {
  color: #222;
  font-weight: 700;
}
#rippling-panel-body input[type='range'] {
  width: 100%;
  height: 4px;
  accent-color: #61ae24;
  cursor: pointer;
}

.rpl-tip {
  font-size: 11px;
  color: #888;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid #f0f0f0;
}

.rpl-ripple-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 10px 0 4px;
  padding-top: 10px;
  border-top: 1px solid #f0f0f0;
}
#rippling-btn {
  background: #61ae24;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
#rippling-btn:hover {
  background: #4d8b1d;
}
#rippling-btn.rpl-stop {
  background: #c0392b;
}
#rippling-btn:disabled {
  background: #aaa;
  cursor: default;
}
.rpl-ripple-info {
  font-size: 11px;
  color: #888;
}

.rpl-layer-toggles {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 6px;
  margin: 6px 0;
  padding-top: 8px;
  border-top: 1px solid #f0f0f0;
}
.rpl-layer-toggle {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: #555;
  cursor: pointer;
  user-select: none;
}
.rpl-layer-toggle input[type='checkbox'] {
  cursor: pointer;
  accent-color: #61ae24;
  width: 12px;
  height: 12px;
}

#rippling-timeline {
  position: absolute;
  top: 10px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(255, 255, 255, 0.95);
  border-radius: 10px;
  padding: 10px 24px 12px;
  z-index: 1000;
  min-width: 560px;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.22);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
#rippling-tl-elapsed {
  font-size: 15px;
  font-weight: 700;
  color: #61ae24;
  text-align: center;
  margin-bottom: 8px;
  letter-spacing: 0.3px;
  pointer-events: none;
}
#rippling-tl-scrub-wrap {
  position: relative;
  margin: 26px 4px 34px;
}
#rippling-tl-slider {
  width: 100%;
  height: 6px;
  -webkit-appearance: none;
  appearance: none;
  background: linear-gradient(
    to right,
    #61ae24 var(--tl-pct, 0%),
    #e0e0e0 var(--tl-pct, 0%)
  );
  border-radius: 3px;
  outline: none;
  cursor: pointer;
  margin: 0;
}
#rippling-tl-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 16px;
  height: 16px;
  background: #61ae24;
  border: 3px solid #fff;
  border-radius: 50%;
  box-shadow: 0 1px 5px rgba(0, 0, 0, 0.3);
  cursor: pointer;
}
#rippling-tl-tick-layer {
  position: absolute;
  top: 10px;
  left: 0;
  right: 0;
  pointer-events: none;
}
.rpl-tick {
  position: absolute;
  top: 10px;
  transform: translateX(-50%);
  font-size: 10px;
  color: #888;
  white-space: nowrap;
  font-weight: 500;
}
.rpl-edge-right {
  transform: translateX(-100%);
}
.rpl-expansion {
  color: #c44;
  font-weight: 700;
}
.rpl-tick-mark {
  position: absolute;
  width: 1px;
  height: 6px;
  background: #bbb;
  top: -8px;
  transform: translateX(-50%);
}
.rpl-tick-mark.rpl-expansion {
  background: #c44;
  height: 8px;
}

#rippling-status {
  position: absolute;
  bottom: 30px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(0, 0, 0, 0.65);
  color: #fff;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 13px;
  z-index: 1000;
  pointer-events: none;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

#rippling-legend,
#rippling-legend-inbound {
  position: absolute;
  bottom: 10px;
  left: 10px;
  background: rgba(255, 255, 255, 0.92);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 11px;
  z-index: 1000;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
#rippling-legend h4,
#rippling-legend-inbound h4 {
  margin: 0 0 6px;
  font-size: 11px;
  color: #555;
}
.rpl-leg-item {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 3px 0;
}
.rpl-leg-swatch {
  width: 14px;
  height: 10px;
  border-radius: 2px;
  flex-shrink: 0;
}

.rpl-spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid #f3f3f3;
  border-top: 2px solid #61ae24;
  border-radius: 50%;
  animation: rpl-spin 0.7s linear infinite;
}
@keyframes rpl-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
