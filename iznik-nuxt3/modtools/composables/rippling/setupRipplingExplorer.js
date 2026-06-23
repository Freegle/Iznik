// All the imperative Leaflet, fetch, animation, and event-wiring logic
// that used to live inline in RipplingExplorer.vue's onMounted body.
// Moved out so the Vue file is just a shell (template + style + a couple
// of refs + a call to setupRipplingExplorer).
//
// State (map, currentLat, currentLng, animation timers, layer caches) is
// kept in the closure created when setupRipplingExplorer is invoked.  The
// returned cleanup function tears the map down and removes listeners.
//
// Parameters:
//   props        — { spatialUrl, jwt } from the host component
//   digestModal  — ref to <RipplingDigestModal>; modal opening is delegated
//   legendMode   — ref ('outbound' | 'inbound') flipped by view-toggle
import { chaikinSmooth, geoToLeaflet, pointInRing } from './geometry.js'
import {
  hasRing,
  quintileOfFreegler,
  groupCentroid,
  distSq,
  ringsOverlap,
  homeGroupOverlapFraction,
} from './polygon.js'
import { partitionInboxData, swingometerDisplay } from './scoring.js'
import { renderPie as renderPieSvg } from './pie.js'
import { buildFreeglerBarHTML } from './freeglerBar.js'

export async function setupRipplingExplorer({
  props,
  digestModal,
  legendMode,
}) {
  await import('leaflet/dist/leaflet.css')
  const L = (await import('leaflet')).default

  let map = null
  const cleanupFns = []

  function apiUrl(path) {
    const sep = path.includes('?') ? '&' : '?'
    return `${props.spatialUrl}${path}${sep}jwt=${encodeURIComponent(
      props.jwt
    )}`
  }

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
  // Minimal mode only: when the isochrone covers >=90% of the home group,
  // this layer draws the home-group polygon in the reach (red) style so the
  // display matches the engine's union behaviour.
  let homeGroupReachLayer = null
  let debounceTimer = null
  let isochroneGeneration = 0
  // Bumped on every location change. Any async fetch tied to a location captures
  // this at the start and discards its result if it no longer matches, so a slow
  // response for the previous spot can't repaint stale data over the new one.
  let locationGeneration = 0
  let fitViewOnNextIsochrone = false
  // Local deprivation baseline: fraction of Q1–Q3 freeglers within the 30-min
  // drive standard isochrone (fairness=0) for the current location.
  // Starts at 60 (national approximate); updated by fetchLocalBaseline().
  let localBaseline = 60
  // True once localBaseline reflects a real measured value for the current
  // location. While false (fetch pending or failed) the swingometer must not
  // present the national-average fallback as this area's measured baseline.
  let localBaselineReady = false

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
    document
      .querySelectorAll(
        '#rippling-panel-body > .rpl-slider-row, .rpl-ripple-row, #rippling-freegler-bar'
      )
      .forEach((el) => {
        if (el.id === 'rippling-inbound-row') return
        if (el.id === 'rippling-time-row') {
          el.style.display = '' // always shown
          return
        }
        el.style.display = inbound ? 'none' : ''
      })
    // Hide the deprivation/freeglers/groups toggles in inbound mode — they
    // describe outbound layers.
    const layerToggles = document.querySelector(
      '#rippling-panel-body > div[style*="flex-wrap"]'
    )
    if (layerToggles) layerToggles.style.display = inbound ? 'none' : ''
    // Also the walk/cycle/drive travel-mode row is outbound-only.
    const travelModeRow = document.querySelector(
      '#rippling-panel-body > .rpl-mode-row:not(#rippling-view-mode)'
    )
    if (travelModeRow) travelModeRow.style.display = inbound ? 'none' : ''
    inboundRow.style.display = inbound ? '' : 'none'
    // Swap the legend via the reactive Vue component.
    legendMode.value = inbound ? 'inbound' : 'outbound'
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
    close: {
      input: document.getElementById('rippling-w-close'),
      val: document.getElementById('rippling-w-close-val'),
    },
    budget: {
      input: document.getElementById('rippling-w-budget'),
      val: document.getElementById('rippling-w-budget-val'),
    },
    anchor: {
      input: document.getElementById('rippling-w-anchor'),
      val: document.getElementById('rippling-w-anchor-val'),
    },
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

  // Writes the text summary + pie chart that sit above the sliders.
  function updateInboxHomeSummary(data, parts) {
    const homeSummary = document.getElementById('rippling-home-summary')
    const total = data.pool_size || 0
    const homeHead =
      data.home_groups && data.home_groups.length
        ? `<strong>Home:</strong> ${data.home_groups
            .map((g) => g.name)
            .join(', ')}`
        : `<strong>No home group at this point.</strong>`
    homeSummary.innerHTML =
      `<div style="font-size:13px;font-weight:700;color:#333;margin-bottom:2px">${total} post${
        total === 1 ? '' : 's'
      } in digest</div>` +
      `${homeHead}<br>` +
      `<span style="color:#27ae60">●</span> ${parts.activeHome.length} active home-group · ` +
      `<span style="color:#1f77b4">●</span> ${parts.activeCross.length} rippled in<br>` +
      `<span style="color:#f39c12">●</span> ${parts.promised.length} promised · ` +
      `<span style="color:#888">●</span> ${parts.taken.length} completed`
    renderPie([
      { count: parts.activeHome.length, color: '#27ae60' },
      { count: parts.activeCross.length, color: '#1f77b4' },
      { count: parts.promised.length, color: '#f39c12' },
      { count: parts.taken.length, color: '#888' },
    ])
    // Lower summary: cluster note only, when there are any clusters.
    simSummaryEl.innerHTML =
      data.poster_groups && data.poster_groups.length
        ? `<strong>${data.poster_groups.length}</strong> same-poster cluster${
            data.poster_groups.length === 1 ? '' : 's'
          }.`
        : ''
  }

  // Draws each home-group polygon on the map, lazily creating the inbox
  // feature group on first use.
  function drawHomeGroupPolygons(homeGroups) {
    if (!homeGroups || !homeGroups.length) return
    if (!inboxLayer) inboxLayer = L.featureGroup().addTo(map)
    homeGroups.forEach((g) => {
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
      inboxLayer.addLayer(layer)
    })
  }

  // Outlines the maximum-reach isochrone as a smooth red polygon.  Chaikin
  // smoothing on the client makes the grid-derived ring read as a curve
  // rather than a staircase.
  function drawInboxIsochrone(isochrone) {
    if (!isochrone || !isochrone.geometry) return
    const ring = isochrone.geometry.coordinates[0]
    if (!ring || ring.length <= 3) return
    const smoothed = chaikinSmooth(ring).map(([lng, lat]) => [lat, lng])
    inboxIsoLayer = L.polygon(smoothed, {
      color: '#cc0000',
      weight: 2.5,
      fill: false,
    }).addTo(map)
  }

  // Numbered post markers, co-located posts collapsed into one marker
  // with a comma-separated rank list.
  function drawDigestMarkers(ranked, group) {
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
      const minRank = bucketPosts[0]._rank
      const color = colorFor(bucketPosts[0])
      const t =
        totalRanked > 1 ? (minRank - 1) / Math.max(totalRanked - 1, 1) : 0
      const baseOpacity = 0.95 - 0.45 * t
      const dotOpacity =
        bucketPosts[0].successful || bucketPosts[0].promised
          ? 0.85
          : baseOpacity
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
  }

  // Purple dashed ring around every position that has >1 post from the
  // same Freegle user (helps spot TrashNothing-style cross-posters).
  function drawPosterClusterRings(posterGroups, group) {
    ;(posterGroups || []).forEach((cl) => {
      L.circleMarker([cl.top_lat, cl.top_lng], {
        radius: 12,
        color: '#9b59b6',
        weight: 2,
        fill: false,
        dashArray: '3,3',
      })
        .bindTooltip(
          `Same poster: ${cl.count} posts (top + ${cl.count - 1} others)`,
          { sticky: true }
        )
        .addTo(group)
    })
  }

  function drawInbox(data) {
    if (!data) return
    const parts = partitionInboxData(data)
    lastRanked = parts.ranked

    updateInboxHomeSummary(data, parts)
    drawHomeGroupPolygons(data.home_groups)
    drawInboxIsochrone(data.isochrone)

    const group = inboxLayer || L.featureGroup().addTo(map)
    inboxLayer = group
    drawDigestMarkers(parts.ranked, group)
    drawPosterClusterRings(data.poster_groups, group)
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
  // The per-post reach modal seeds the location via props; otherwise fall back to
  // URL params (bookmarkable explorer) and finally geolocation.
  const urlParams = new URLSearchParams(window.location.search)
  const pendingView = props.initialView || urlParams.get('view')
  const pendingLat =
    props.initialLat != null
      ? props.initialLat
      : parseFloat(urlParams.get('lat'))
  const pendingLng =
    props.initialLng != null
      ? props.initialLng
      : parseFloat(urlParams.get('lng'))
  const pendingPostcode = urlParams.get('postcode') || urlParams.get('q')
  const seededFromProps = props.initialLat != null && props.initialLng != null

  async function applyUrlInit() {
    if (pendingView === 'inbound' || pendingView === 'outbound') {
      const btn = document.querySelector(
        `.rpl-mode-btn[data-view="${pendingView}"]`
      )
      if (btn) btn.click()
    }
    if (!isNaN(pendingLat) && !isNaN(pendingLng)) {
      setLocation(pendingLat, pendingLng, true)
      // Seeded from props (per-post reach modal): show the reach STATICALLY at the
      // post's current point (how far it has already rippled), with the scrubber, and
      // let the user drag forwards/backwards. No animation.
      if (seededFromProps) {
        startRipple(
          props.initialElapsedHours != null ? props.initialElapsedHours : 0
        )
      }
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

  // Drastic reset of everything tied to the previous location. Called from
  // setLocation before the new spot is drawn, so the panel never shows a mix of
  // old and new (e.g. the previous location's group list). A page reload has the
  // same effect from a clean slate; this gives the in-place equivalent.
  function resetForNewLocation() {
    // Map overlays from the old location.
    clearOutboundLayers() // isochrone + quintile layers, and freegler dots (via event)
    clearInboundLayers() // digest inbox + its isochrone
    Object.values(groupLayerMap).forEach((l) => map.removeLayer(l))
    groupLayerMap = {}
    if (morphLayer && map.hasLayer(morphLayer)) {
      map.removeLayer(morphLayer)
      morphLayer = null
    }
    if (homeGroupReachLayer && map.hasLayer(homeGroupReachLayer)) {
      map.removeLayer(homeGroupReachLayer)
      homeGroupReachLayer = null
    }
    // Cached datasets / derived state for the old spot.
    groupFeatures = []
    homeGroupIds = new Set()
    lastIsoData = null
    lastRanked = []
    allFreeglers = []
    freeglersGrid = []
    totalLocatedFromServer = 0
    localBaselineReady = false
    rippleFrames = []
    crossPostingDetected = false
    rippleMaxImbalance = null
    // Derived panels that would otherwise keep showing the old answer.
    const groupsList = document.getElementById('rippling-groups-list')
    if (groupsList) groupsList.innerHTML = ''
    const groupsSection = document.getElementById('rippling-groups-section')
    if (groupsSection) groupsSection.style.display = 'none'
    const statsEl = document.getElementById('rippling-stats')
    if (statsEl) statsEl.innerHTML = ''
  }

  function setLocation(lat, lng, fly) {
    if (ripplePlaying || rippleFrames.length > 0) stopRipple()
    // A new location invalidates everything derived from the old one. Bump the
    // generation (so in-flight fetches drop their late results) and wipe the
    // cached datasets, map overlays and derived panels straight away, so nothing
    // from the previous spot lingers. Without this a slow groups fetch for the
    // old location could repaint its group list over the new one.
    locationGeneration++
    resetForNewLocation()
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
        p.style.zIndex = 700 // above markerPane (600) and overlayPane (400)
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
    const gen = locationGeneration
    const maxReach = Number(timeSlider.max) || 60
    // New location: the previous area's baseline no longer applies.
    localBaselineReady = false
    try {
      const url = apiUrl(
        `/v1/fairness?lat=${lat.toFixed(6)}&lng=${lng.toFixed(
          6
        )}&minutes=${maxReach}&mode=drive&fairness=0`
      )
      const r = await fetch(url)
      // Discard a late baseline for a location we've since moved away from.
      if (gen !== locationGeneration) return
      if (!r.ok) return
      const data = await r.json()
      if (gen !== locationGeneration) return
      if (data.fairness_score !== undefined && data.fairness_score >= 0) {
        localBaseline = Math.round(data.fairness_score * 100)
        localBaselineReady = true
      }
    } catch (e) {
      // Keep previous baseline on error; localBaselineReady stays false so the
      // swingometer shows "unavailable" rather than presenting 60% as real.
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
            const bounds = L.latLngBounds(
              allCoords.map(([lng, lat]) => [lat, lng])
            )
            if (bounds.isValid())
              map.fitBounds(bounds, {
                padding: [60, 60],
                maxZoom: 13,
                animate: false,
              })
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

  function applyPolyTransition(el, durationMs) {
    if (!el) return
    el.style.transformBox = 'fill-box'
    el.style.transformOrigin = 'center'
    el.style.transform = 'scale(0.88)'
    el.style.transition =
      `transform ${durationMs}ms ease-out,` +
      ` fill-opacity ${durationMs}ms ease-out,` +
      ` opacity ${durationMs}ms ease-out`
  }

  // Fade outgoing polygons out then remove them.  When dur=0 the removal
  // is immediate; otherwise we let the CSS transition finish first so
  // the disappear doesn't jump.
  function tearDownOutgoingLayers(outgoing, dur) {
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
  }

  // Draws all five deprivation-quintile polygons (and their fairness-
  // bonus islands) using the supplied addPoly closure from drawPolygons.
  // Quintile 5 first, 1 last so deprived areas paint on top.
  function drawQuintilePolygons(data, addPoly) {
    ;[5, 4, 3, 2, 1].forEach((q) => {
      const qr = data.quintiles && data.quintiles[q]
      if (!qr) return
      if (hasRing(qr.polygon)) {
        addPoly(
          `q${q}`,
          geoToLeaflet(qr.polygon.geometry.coordinates[0]),
          { color: '#005bb5', weight: 1, fillColor: QCOLORS[q] },
          0.3,
          1,
          `${QNAMES[q]} (standard reach) · ${qr.time_budget_min.toFixed(1)} min`
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
  }

  // Draws the red "standard reach" boundary (no fairness adjustment).
  function drawStandardPolygon(data, addPoly) {
    if (!hasRing(data.standard)) return
    addPoly(
      'standard',
      geoToLeaflet(data.standard.geometry.coordinates[0]),
      { color: '#cc0000', weight: 2.5, fillColor: 'none' },
      0,
      1,
      'Standard reach boundary (no fairness adjustment)'
    )
  }

  function drawPolygons(data, transitionMs, skipStandard = false) {
    lastIsoData = data
    const dur = transitionMs || 0
    const outgoing = Object.assign({}, layers)
    const newLayers = {}

    // Closure that reconciles a polygon against existing layers — either
    // mutates the existing layer in place (so CSS d-transitions can morph
    // it) or creates a new one with a scale/opacity fade-in.
    function addPoly(key, coords, opts, targetFill, targetOpacity, tooltip) {
      const existing = layers[key]
      if (existing && map.hasLayer(existing)) {
        // Set the SVG `d` transition to 70% of the step delay so the
        // shape morph always finishes before the next frame fires.
        // Without this the CSS fixed 450ms would overflow shorter
        // delays, causing stuttering.
        const el = existing.getElement()
        if (el) {
          const dDur = dur > 0 ? Math.round(dur * 0.7) : 0
          el.style.transition = dDur > 0 ? `d ${dDur}ms ease-out` : 'none'
        }
        existing.setLatLngs(coords)
        existing.setStyle({
          ...opts,
          fillOpacity: targetFill,
          opacity: targetOpacity,
        })
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
        applyPolyTransition(el, dur)
        requestAnimationFrame(() => {
          if (el) el.style.transform = 'scale(1)'
          lyr.setStyle({ fillOpacity: targetFill, opacity: targetOpacity })
        })
      } else {
        lyr.setStyle({ fillOpacity: targetFill, opacity: targetOpacity })
      }
      return lyr
    }

    if (showQuintiles) drawQuintilePolygons(data, addPoly)
    if (!skipStandard) drawStandardPolygon(data, addPoly)

    tearDownOutgoingLayers(outgoing, dur)
    layers = newLayers
    requestAnimationFrame(() => requestAnimationFrame(updateFairnessClip))
    map.once('moveend', updateFairnessClip)
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
    const s = swingometerDisplay(pct, localBaseline, localBaselineReady)
    labelEl.textContent = s.label
    labelEl.style.color = s.color
    if (!s.ready) {
      // No measured area baseline yet — don't claim a bias against the
      // national-average fallback.
      pctEl.innerHTML = `${pct}% of Freeglers within reach are in deprived areas<br>
      <span style="color:#999">area baseline unavailable — can't compute bias for this area</span>`
      return
    }
    pctEl.innerHTML = `${pct}% of Freeglers within reach are in deprived areas<br>
      <span style="color:${
        s.aboveBaseline ? '#1a9850' : '#d73027'
      };font-weight:600">${s.aboveBaseline ? '▲' : '▼'} ${s.diff}% ${
      s.aboveBaseline ? 'above' : 'below'
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
    // Populate the needle label and bias text. updateStats only renders the
    // gauge markup (with placeholder labels); setSwingometer fills in the actual
    // reading from the area baseline, so without this call the swingometer is
    // stuck on "Loading Freegler data…" in every area that has deprivation data.
    setSwingometer(roadPct)
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
      minutesOverride !== undefined
        ? minutesOverride
        : parseInt(timeSlider.value)
    const url = apiUrl(
      `/v1/nearby-freeglers?lat=${currentLat.toFixed(
        6
      )}&lng=${currentLng.toFixed(6)}&minutes=${minutes}&mode=${currentMode}`
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
    drawFreeglersLayer() // always re-apply zoom gate (clears dots when zoomed out)
    if (ripplePlaying) return // skip expensive re-fetch during animation
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
    if (viewMode === 'inbound') return // freegler dots are outbound-only
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

  const UNLOCATED_FRACTION = 0.35

  // Walk the freegler sample grid and style each dot inside/outside the
  // boundary; return the count of freeglers inside.
  function styleFreeglersByBoundary(data) {
    let insideCount = 0
    freeglersGrid.forEach((g, i) => {
      const q = quintileOfFreegler(g.lng, g.lat, data)
      if (q !== 0) {
        insideCount += g.count
        if (freeglersMarkers[i])
          freeglersMarkers[i].setStyle({ fillOpacity: 1, opacity: 1 })
      } else if (freeglersMarkers[i])
        freeglersMarkers[i].setStyle({ fillOpacity: 0.12, opacity: 0.2 })
    })
    return insideCount
  }

  // Decide how many located freeglers are inside the boundary.  Prefer the
  // schedule's authoritative `cumulative_users` when the server provided one;
  // otherwise scale the sampled inside-count back up to the population, or
  // fall back to the full located total in static view.
  function estimateNotifiedCount(data, insideCount) {
    if (
      data &&
      data.cumulative_users !== undefined &&
      data.cumulative_users !== null
    )
      return data.cumulative_users
    const totalLocated = totalLocatedFromServer || allFreeglers.length
    const sampleSize = allFreeglers.length
    const isRipple = ripplePlaying || rippleFrames.length > 0
    if (isRipple && sampleSize > 0)
      return Math.round(insideCount * (totalLocated / sampleSize))
    return totalLocated
  }

  // Render the "would be notified" panel at the bottom of the side bar.
  // Hidden when the estimate is zero — showing "~0 would be notified" at the
  // start of a ripple is misleading.
  function renderFreeglerBar(estimatedInsideLocated) {
    const totalLocated = totalLocatedFromServer || allFreeglers.length
    const bar = document.getElementById('rippling-freegler-bar')
    const html = buildFreeglerBarHTML(
      estimatedInsideLocated,
      totalLocated,
      UNLOCATED_FRACTION
    )
    if (!html) {
      bar.style.display = 'none'
      return
    }
    bar.innerHTML = html
    bar.style.display = ''
  }

  // The quintile polygon approach for classifying freeglers is unreliable:
  // a 19-point concave hull for 6,000 scattered Q1 nodes smears over Q4/Q5
  // areas, producing a deprived-biased reading.  Instead use the routing
  // server's own fairness_score (Q1-3 nodes / all tagged nodes).  updateStats
  // already renders the needle; this just tracks the peak for the summary.
  function trackFairnessImbalance(data) {
    if (!ripplePlaying) return
    if (data.fairness_score === undefined || data.fairness_score < 0) return
    const pct = Math.round(data.fairness_score * 100)
    const imbalance = Math.abs(pct - localBaseline)
    if (
      rippleMaxImbalance === null ||
      imbalance > Math.abs(rippleMaxImbalance.pct - localBaseline)
    ) {
      // rippleStep is 0-based frame index; actual drive time = (step+1)*RIPPLE_STEP_MINS.
      rippleMaxImbalance = { pct, minute: (rippleStep + 1) * RIPPLE_STEP_MINS }
    }
  }

  // Refresh the "freeglers inside the boundary" view: dot styling, the
  // notification-count panel, and the fairness-imbalance tracker.
  function updateFreeglersInside(data) {
    const insideCount = styleFreeglersByBoundary(data)
    const estimatedInsideLocated = estimateNotifiedCount(data, insideCount)
    renderFreeglerBar(estimatedInsideLocated)
    trackFairnessImbalance(data)
  }

  let groupLayerMap = {}
  let groupFeatures = []
  let homeGroupIds = new Set()

  async function fetchAndDrawGroups(lat, lng) {
    const gen = locationGeneration
    Object.values(groupLayerMap).forEach((l) => map.removeLayer(l))
    groupLayerMap = {}
    groupFeatures = []
    homeGroupIds = new Set()
    try {
      const url = apiUrl(
        `/v1/groups/nearby?lat=${lat.toFixed(6)}&lng=${lng.toFixed(6)}`
      )
      const r = await fetch(url)
      // A newer location superseded this fetch while it was in flight — drop the
      // result so the old spot's groups can't overwrite the current ones.
      if (gen !== locationGeneration) return
      if (!r.ok) return
      const data = await r.json()
      if (gen !== locationGeneration) return
      groupFeatures = data.features || []
      groupFeatures.forEach((f) => {
        if (f.properties.contains) homeGroupIds.add(f.properties.id)
      })
      drawGroupsOverlay()
    } catch (e) {
      /* no group data — silently skip */
    }
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

  // Geographically-nearest group id, used as fallback "home" when
  // ST_Contains misses (e.g. the click lands exactly on a polygon edge).
  function findNearestGroupId() {
    if (currentLat === null) return null
    let nearestId = null
    let nearestDist = Infinity
    groupFeatures.forEach((f) => {
      const [cLng, cLat] = groupCentroid(f)
      const d = distSq(currentLat, currentLng, cLat, cLng)
      if (d < nearestDist) {
        nearestDist = d
        nearestId = f.properties.id
      }
    })
    return nearestId
  }

  // Returns groups sorted with home first, then by centroid distance.
  function sortRelevantGroups(predicate) {
    return [...groupFeatures].filter(predicate).sort((a, b) => {
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
  }

  // Reconcile the map's Leaflet polygons with the set of relevant groups:
  // remove layers for groups no longer in `visibleIds`, add layers for
  // ones not yet on the map.
  function syncGroupPolygons(visibleIds, nearestGroupId) {
    Object.keys(groupLayerMap).forEach((id) => {
      if (!visibleIds.has(Number(id))) {
        map.removeLayer(groupLayerMap[id])
        delete groupLayerMap[id]
      }
    })
    groupFeatures.forEach((f) => {
      const coords =
        f.geometry && f.geometry.coordinates && f.geometry.coordinates[0]
      if (!coords || coords.length < 4) return
      const id = f.properties.id
      if (!visibleIds.has(id) || groupLayerMap[id]) return
      const isHome = f.properties.contains || id === nearestGroupId
      const latlngs = coords.map(([lng, lat]) => [lat, lng])
      groupLayerMap[id] = L.polygon(latlngs, {
        color: '#27ae60',
        weight: isHome ? 3 : 2,
        fillColor: '#27ae60',
        fillOpacity: isHome ? 0.1 : 0.05,
        dashArray: null,
      })
        .addTo(map)
        .bindTooltip(
          (isHome ? '🏠 ' : '') + (f.properties.nameshort || 'Group'),
          { sticky: true }
        )
    })
  }

  // Sidebar list of relevant groups, with green/red dot meaning "your
  // home group / a cross-post-reachable group" vs "in view but the
  // post wouldn't reach it".
  function renderGroupSidebar(listEl, sorted, reached) {
    listEl.innerHTML = ''
    if (sorted.length === 0) {
      listEl.innerHTML =
        '<span style="color:#aaa;font-style:italic">None visible in current view</span>'
      return
    }
    sorted.forEach((f) => {
      const isHome = f.properties.contains
      const postShows = isHome || reached.has(f.properties.id)
      const dotColor = postShows ? '#27ae60' : '#e74c3c'
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
  }

  function drawGroupsOverlay() {
    const listEl = document.getElementById('rippling-groups-list')
    const sectionEl = document.getElementById('rippling-groups-section')

    if (!showGroups || groupFeatures.length === 0) {
      sectionEl.style.display = 'none'
      return
    }
    sectionEl.style.display = ''

    const reached = reachedGroupIds(lastIsoData)
    const nearestGroupId = findNearestGroupId()
    // Show: home (contains=true), reached cross-posting groups, and
    // always the nearest group as a fallback home if ST_Contains missed.
    const isRelevant = (f) =>
      f.properties.contains ||
      reached.has(f.properties.id) ||
      f.properties.id === nearestGroupId

    const visibleIds = new Set()
    groupFeatures.forEach((f) => {
      const coords =
        f.geometry && f.geometry.coordinates && f.geometry.coordinates[0]
      if (coords && coords.length >= 4 && isRelevant(f)) {
        visibleIds.add(f.properties.id)
      }
    })

    syncGroupPolygons(visibleIds, nearestGroupId)
    renderGroupSidebar(listEl, sortRelevantGroups(isRelevant), reached)

    // Minimal mode (per-post reach modal): also list the groups the CURRENT reach
    // frame hits, bottom-left, so mods see which communities it has reached.
    if (props.minimal && rippleFrames.length) {
      renderMinimalGroupsHit(rippleFrames[rippleStep])
      markCrossPostingStatic()
      // Groups may have just arrived asynchronously (fetchAndDrawGroups resolves
      // after the frame is already shown): re-evaluate the home-group union now.
      maybeExtendReachToHomeGroup(rippleFrames[rippleStep])
    }
  }

  // Renders, into #rippling-reach-groups, the names of the groups the given reach
  // frame overlaps (home first). Used only by the minimal per-post reach modal.
  function renderMinimalGroupsHit(data) {
    const el = document.getElementById('rippling-reach-groups')
    if (!el) return
    const reached = reachedGroupIds(data)
    const esc = (s) =>
      String(s).replace(
        /[&<>]/g,
        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])
      )
    const hits = groupFeatures
      .filter((f) => reached.has(f.properties.id))
      .map((f) => ({
        name: f.properties.nameshort || 'Group',
        home: !!f.properties.contains,
      }))
      .sort(
        (a, b) =>
          (b.home ? 1 : 0) - (a.home ? 1 : 0) || a.name.localeCompare(b.name)
      )
    if (!hits.length) {
      el.innerHTML =
        '<div class="rpl-rg-title">Groups reached</div>' +
        '<div class="rpl-rg-empty">None yet</div>'
      return
    }
    // Home group gets the house; every other reached group is a cross-post (⚡).
    el.innerHTML =
      `<div class="rpl-rg-title">Groups reached (${hits.length})</div>` +
      hits
        .map(
          (g) =>
            `<div class="rpl-rg-item">${g.home ? '🏠 ' : '⚡ '}${esc(
              g.name
            )}</div>`
        )
        .join('')
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

  // Static mode (per-post reach modal): the animation never runs, so add the
  // cross-posting ⚡ tick to the timeline up-front. Adds only the tick mark (does
  // not touch the elapsed label or flash a polygon, unlike markCrossPosting).
  let staticCrossMarked = false
  function addCrossPostingTick(hours) {
    const pct = hoursToLogPct(hours)
    const layer = document.getElementById('rippling-tl-tick-layer')
    if (!layer) return
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
    label.style.cssText = `position:absolute;left:${pct}%;top:-22px;color:#e07000;font-size:11px;font-weight:700;white-space:nowrap;transform:${xform}`
    label.textContent = '⚡'
    layer.appendChild(label)
  }
  function markCrossPostingStatic() {
    if (staticCrossMarked || !rippleFrames.length) return
    // Groups load asynchronously; if they aren't here yet, retry on the next
    // drawGroupsOverlay (which fires when they arrive).
    if (!groupFeatures.length) return
    const n = rippleFrames.length
    for (let i = 0; i < n; i++) {
      const f = rippleFrames[i]
      if (!hasRing(f.standard)) continue
      const hours = frameToHours(i, n)
      if (hours < 24) continue // match the animation's initial 24h self period
      if (checkCrossPosting(f.standard.geometry.coordinates[0])) {
        addCrossPostingTick(hours)
        break
      }
    }
    staticCrossMarked = true
  }

  // Minimal mode (per-post reach modal): match the engine's home-group union behaviour.
  //
  // When the engine detects that the post's isochrone already covers >=90% of the home
  // group's polygon, it treats reach as (isochrone UNION home-group-area).  We replicate
  // that here so the visual display matches what recipients actually experience.
  //
  // Approximation note: @turf/union and @turf/area are not available in this project
  // (only turf-distance / turf-point are).  Instead of a true geometric union we add a
  // separate Leaflet layer drawing the home-group polygon filled in the same red reach
  // style, so the group's area is visually covered by the reach colour.  This is
  // indistinguishable from a true union at normal map zoom levels.
  //
  // The overlap fraction is estimated via homeGroupOverlapFraction (point-grid, ±~0.03).
  //
  // Only active in minimal mode; no-ops silently in the full explorer.
  const HOME_GROUP_UNION_THRESHOLD = 0.9
  function maybeExtendReachToHomeGroup(isoData) {
    // Remove any previous extension layer before re-evaluating.
    if (homeGroupReachLayer && map.hasLayer(homeGroupReachLayer)) {
      map.removeLayer(homeGroupReachLayer)
      homeGroupReachLayer = null
    }
    if (!props.minimal) return
    if (!isoData || !hasRing(isoData.standard)) return
    if (!groupFeatures.length) return

    const isoRing = isoData.standard.geometry.coordinates[0]
    // Find the home-group feature (properties.contains === true).
    const homeFeature = groupFeatures.find((f) => f.properties.contains)
    if (!homeFeature) return
    const groupCoords =
      homeFeature.geometry &&
      homeFeature.geometry.coordinates &&
      homeFeature.geometry.coordinates[0]
    if (!groupCoords || groupCoords.length < 4) return

    const fraction = homeGroupOverlapFraction(isoRing, groupCoords)
    if (fraction < HOME_GROUP_UNION_THRESHOLD) return

    // Isochrone covers >=90% of the home group: draw the home-group polygon
    // filled in the reach style so it appears fully enclosed by the red reach.
    const latlngs = groupCoords.map(([lng, lat]) => [lat, lng])
    homeGroupReachLayer = L.polygon(latlngs, {
      color: '#cc0000',
      weight: 2,
      fillColor: '#cc0000',
      fillOpacity: 0.15,
      dashArray: null,
    })
      .bindTooltip('Reach extended to cover full home group', { sticky: true })
      .addTo(map)
  }

  // Static mode: mark where the reach SHOULD be ("up to", expected — bottom) and, only when
  // the engine is behind, where it ACTUALLY is ("now", actual — top). When they match we show
  // just "up to", so a visible "now" means the post hasn't rippled as far as it should.
  function addReachMarker(layer, hours, text, color, where) {
    const pct = hoursToLogPct(Math.max(0, Math.min(hours, MAX_HOURS)))
    const mark = document.createElement('div')
    mark.className = 'rpl-tick-mark'
    mark.style.cssText = `left:${pct}%;background:${color};height:16px;top:-11px;width:2px`
    layer.appendChild(mark)
    const label = document.createElement('div')
    const xform =
      pct < 12
        ? 'translateX(0)'
        : pct > 88
        ? 'translateX(-100%)'
        : 'translateX(-50%)'
    const top = where === 'top' ? '-28px' : '16px'
    label.style.cssText = `position:absolute;left:${pct}%;top:${top};color:${color};font-size:10px;font-weight:700;white-space:nowrap;transform:${xform}`
    label.textContent = text
    layer.appendChild(label)
  }
  function renderReachMarkers() {
    const layer = document.getElementById('rippling-tl-tick-layer')
    if (!layer) return
    // The scrubber opens at — and the map shows — the EXPECTED point, so the thumb itself
    // is the "up to" indicator (no separate marker needed). Only when the engine is BEHIND
    // do we add an "actually here" marker, so the gap to the thumb is the lag.
    if (props.actualElapsedHours != null) {
      addReachMarker(
        layer,
        props.actualElapsedHours,
        'actually here ▲',
        '#e07000',
        'top'
      )
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

  // Inverse of hoursToLogPct: a log-scale slider position (0..100) back to hours. The
  // scrubber is on this log scale so the thumb lines up with the hour ticks and markers.
  function logPctToHours(pct) {
    return Math.pow(10, (pct / 100) * Math.log10(MAX_HOURS + 1)) - 1
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
    // Slider domain is a 0..1000 LOG-percent (×10) so the thumb shares the log axis with
    // the hour ticks/markers, rather than being linear in frame index.
    slider.max = 1000
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
    // Put the thumb + fill on the SAME log scale as the hour ticks and the reach
    // markers, so the thumb sits directly under the elapsed time it represents (and the
    // green fill ends exactly at the thumb).
    const pct = hoursToLogPct(hours)
    const slider = document.getElementById('rippling-tl-slider')
    slider.value = Math.round(pct * 10) // domain 0..1000
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
      maybeExtendReachToHomeGroup(data)
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
  const RIPPLE_FRAMES = 30 // 30 keyframes = 1 per drive-minute
  const RIPPLE_STEP_MINS = 1
  const N_ANGLES = 360 // resolution of the radial parameterisation

  let rippleFrames = []
  let rippleStep = 0
  let ripplePlaying = false
  let crossPostingDetected = false
  let rippleMaxImbalance = null // {pct, minute} — worst affluence bias seen during animation

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
      // Slider is on the log scale (0..1000): convert back to hours, then to the nearest frame.
      const hours = logPctToHours(parseInt(this.value) / 10)
      const frameIdx = Math.round(
        (hours / MAX_HOURS) * (rippleFrames.length - 1)
      )
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

  // Ripple always uses drive mode — switch silently if the user is in walk
  // mode when they hit play, so the schedule fetch below sees the right mode.
  function ensureDriveMode() {
    if (currentMode === 'drive') return
    currentMode = 'drive'
    document.querySelectorAll('.rpl-mode-btn').forEach((b) => {
      b.classList.toggle('rpl-active', b.dataset.mode === 'drive')
    })
    if (currentLat !== null) updateIsochrone()
  }

  // Reset the panel + timeline UI and re-centre the map ahead of a new
  // ripple animation.
  function prepareRippleUI(btn) {
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
  }

  // Pull the density-driven schedule from the server.  One HTTP call returns
  // the full ticks-tuple of (drive-time, cumulative_users, polygon) — the
  // server picks each tick's drive-time so an equal-population batch is
  // encapsulated at each step.  The "curve" parameter shapes the
  // cumulative-fraction-vs-tick mapping.  Default to the data-driven
  // recommended curve — see plans/reference/ripple-curve-evaluation.md.
  // step-70 (70 % notified at tick 1 then linear) hits 92 % urban / 86 %
  // rural first-replier reach-in-time on the 4,264-post historical sample
  // with ~7 % waste.
  async function fetchRippleSchedule() {
    const curveShape = 'step-70'
    const scheduleURL = apiUrl(
      `/v1/ripple-schedule?lat=${currentLat.toFixed(
        6
      )}&lng=${currentLng.toFixed(
        6
      )}&mode=${currentMode}&ticks=${RIPPLE_FRAMES}&max_minutes=${
        RIPPLE_FRAMES * RIPPLE_STEP_MINS
      }&curve=${curveShape}`
    )
    try {
      return await fetch(scheduleURL).then((r) => r.json())
    } catch (e) {
      return null
    }
  }

  // Initialise the per-animation state and start the rAF loop.
  function beginRippleAnimation(btn) {
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

  // staticAtHours: when non-null, don't animate — build the scrubber and jump to the
  // frame for that many elapsed hours (the per-post reach modal opens here). When null,
  // play the ripple animation from the start (the explorer's "Animate ripple" button).
  async function startRipple(staticAtHours = null) {
    if (currentLat === null) {
      showStatus('Click a location first', false)
      return
    }

    ensureDriveMode()

    const btn = document.getElementById('rippling-btn')
    prepareRippleUI(btn)

    const scheduleResp = await fetchRippleSchedule()
    if (
      !scheduleResp ||
      !scheduleResp.schedule ||
      scheduleResp.schedule.length === 0
    ) {
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
    totalLocatedFromServer =
      scheduleResp.total_freeglers || totalLocatedFromServer

    // Reparameterise every keyframe's standard ring as a radii array, once,
    // so the rAF hot path is pure arithmetic.
    precomputeRadii(rippleFrames, currentLng, currentLat)

    // Fetch all freeglers reachable at the maximum drive time so the dots
    // are loaded before the animation needs them.
    await fetchFreeglers(RIPPLE_FRAMES * RIPPLE_STEP_MINS)
    drawFreeglersLayer()
    ensureMorphLayer()

    if (staticAtHours != null) {
      // Static mode: show the scrubber and jump to the frame for how long the post has
      // already been live, without animating. Dragging the scrubber works as normal.
      staticCrossMarked = false
      buildTimeline(rippleFrames.length)
      document.getElementById('rippling-timeline').style.display = ''
      btn.disabled = false
      btn.textContent = '▶ Animate ripple'
      const maxIdx = rippleFrames.length - 1
      const clamped = Math.max(0, Math.min(staticAtHours, MAX_HOURS))
      jumpToFrame(Math.round((clamped / MAX_HOURS) * maxIdx))
      markCrossPostingStatic()
      renderReachMarkers()
      return
    }

    beginRippleAnimation(btn)
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

  // Final-frame cleanup: pin to last keyframe, remove the morph layer,
  // redraw the static end state, restore the button label, and surface
  // the peak-imbalance summary so the moderator can see how the wave
  // skewed.
  function finishRipple(maxIdx) {
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
      const bias =
        rippleMaxImbalance.pct < localBaseline ? 'affluent' : 'deprived'
      const diff = Math.abs(rippleMaxImbalance.pct - localBaseline)
      doneText += ` · peak ${bias} bias: ${diff}% at ${rippleMaxImbalance.minute} min`
    }
    document.getElementById('rippling-info').textContent = doneText
    drawGroupsOverlay()
  }

  // Updates the info-text + timeline scrubber for a particular keyframe.
  // Called only when we cross into a new frame, not every rAF tick.
  function updateRippleInfoForFrame(frameA, data) {
    // The density-driven schedule provides drive_min and
    // cumulative_users per tick.  Drive-time is non-monotonic (small in
    // dense regions, jumps across voids) — the info text shows the
    // actual values so the moderator can see the algorithm working.
    const mDrive =
      data && data.drive_min !== undefined
        ? data.drive_min
        : (frameA + 1) * RIPPLE_STEP_MINS
    const minuteLabel = Number.isInteger(mDrive)
      ? String(mDrive)
      : mDrive.toFixed(2)
    const tickLabel = `tick ${frameA + 1}/${rippleFrames.length}`
    const usersLabel =
      data && data.cumulative_users !== undefined
        ? ` · ${data.cumulative_users.toLocaleString()} reached`
        : ''
    document.getElementById(
      'rippling-info'
    ).textContent = `${tickLabel} · ${minuteLabel} drive-min${usersLabel}`
    updateTimeline(frameA, rippleFrames.length)
    timeSlider.value = Math.min(30, Math.round(mDrive))
  }

  // Once-per-animation cross-posting trigger.  Fires the first time a
  // tick's reach polygon overlaps a non-home group polygon, after the
  // initial 24-hour "self" period.
  function maybeMarkCrossPosting(frameA, data) {
    if (crossPostingDetected) return
    if (!groupFeatures.length || !data.standard || !hasRing(data.standard))
      return
    const hours = frameToHours(frameA, rippleFrames.length)
    if (hours < 24) return
    const hit = checkCrossPosting(data.standard.geometry.coordinates[0])
    if (hit) {
      crossPostingDetected = true
      markCrossPosting(hours, hit.properties.nameshort, hit)
    }
  }

  // Every third keyframe, re-fit the map to keep the (growing) boundary
  // comfortably in view.  Look ahead three frames so we don't lag the
  // wave.
  function refitMapForFrame(frameA, maxIdx, data) {
    if (frameA % 3 !== 0) return
    const ahead = rippleFrames[Math.min(frameA + 3, maxIdx)]
    const zoomData = ahead && hasRing(ahead.standard) ? ahead : data
    if (!hasRing(zoomData.standard)) return
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
    } catch (e) {
      /* fitBounds rejects pathological rings — safe to ignore */
    }
  }

  // Heavy per-keyframe updates: stats, freegler dots, group overlays,
  // cross-posting detection, map re-fit.  Called only when the rAF tick
  // crosses into a new frame, never every animation frame.
  function onRippleFrameChange(frameA, maxIdx) {
    rippleLastStatsFrame = frameA
    rippleStep = frameA
    const data = rippleFrames[frameA]
    updateRippleInfoForFrame(frameA, data)
    if (!data) return
    // Schedule polygons are pure drive-time (no fairness/quintile info).
    // Suppress the quintile redraw and let the morph layer own the
    // standard ring.
    updateFreeglersInside(data)
    drawGroupsOverlay()
    drawFreeglersLayer()
    maybeMarkCrossPosting(frameA, data)
    refitMapForFrame(frameA, maxIdx, data)
    // Minimal mode: re-evaluate the home-group union at each keyframe boundary
    // (cheap enough here; we avoid calling it every rAF tick in drawMorphedRing).
    maybeExtendReachToHomeGroup(data)
  }

  function animateRipple(now) {
    rippleRafId = null
    if (!ripplePlaying || !rippleFrames.length) return

    const position = currentFramePosition(now)
    const maxIdx = rippleFrames.length - 1

    if (position >= maxIdx) {
      finishRipple(maxIdx)
      return
    }

    // Interpolate the standard ring between two keyframes every tick.
    const frameA = Math.floor(position)
    const frameB = Math.min(frameA + 1, maxIdx)
    const t = position - frameA
    const radii = lerpRadii(
      rippleFrames[frameA]._radii,
      rippleFrames[frameB]._radii,
      t
    )
    drawMorphedRing(radii)

    // Heavy per-keyframe updates only when we cross into a new frame.
    if (frameA !== rippleLastStatsFrame) onRippleFrameChange(frameA, maxIdx)

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

  return function cleanup() {
    cleanupFns.forEach((fn) => fn())
    if (map) {
      map.remove()
      map = null
    }
  }
}
