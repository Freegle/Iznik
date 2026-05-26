<template>
  <div id="rippling-root" style="position: relative; width: 100%; height: 100%">
    <div id="rippling-map" style="position: absolute; inset: 0"></div>

    <div id="rippling-panel">
      <div id="rippling-panel-header">
        <span>🗺</span>
        Rippling Out Explorer
      </div>
      <div id="rippling-panel-body">
        <div id="rippling-search-wrap">
          <input
            id="rippling-search-box"
            type="text"
            placeholder="Search UK location…"
            autocomplete="off"
          />
          <ul id="rippling-search-results"></ul>
        </div>

        <div class="rpl-mode-row">
          <button class="rpl-mode-btn" data-mode="walk">
            <span class="rpl-icon">🚶</span>Walk
          </button>
          <button class="rpl-mode-btn" data-mode="cycle">
            <span class="rpl-icon">🚴</span>Cycle
          </button>
          <button class="rpl-mode-btn rpl-active" data-mode="drive">
            <span class="rpl-icon">🚗</span>Drive
          </button>
        </div>

        <div class="rpl-slider-row">
          <div class="rpl-slider-label">
            <span>Travel time</span>
          </div>
          <input
            id="rippling-time-slider"
            type="range"
            min="1"
            max="30"
            step="1"
            value="15"
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

        <div id="rippling-stats">
          <div class="rpl-tip">
            Click on the map or search to set an offer location.
          </div>
        </div>

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
  </div>
</template>

<script setup>
import { onMounted, onUnmounted } from '#imports'

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

  document.querySelectorAll('.rpl-mode-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document
        .querySelectorAll('.rpl-mode-btn')
        .forEach((b) => b.classList.remove('rpl-active'))
      btn.classList.add('rpl-active')
      currentMode = btn.dataset.mode
      if (ripplePlaying || rippleFrames.length > 0) stopRipple()
      if (currentLat !== null) scheduleUpdate()
    })
  })

  timeSlider.addEventListener('input', () => {
    if (currentLat !== null) scheduleUpdate()
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

  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        if (currentLat === null)
          setLocation(pos.coords.latitude, pos.coords.longitude, true)
      },
      () => {}
    )
  }

  function setLocation(lat, lng, fly) {
    if (ripplePlaying || rippleFrames.length > 0) stopRipple()
    currentLat = lat
    currentLng = lng
    if (marker) map.removeLayer(marker)
    marker = L.circleMarker([lat, lng], {
      radius: 8,
      color: '#e8380d',
      weight: 3,
      fillColor: '#fff',
      fillOpacity: 1,
    })
      .addTo(map)
    if (fly) map.flyTo([lat, lng], Math.max(map.getZoom(), 13))
    fitViewOnNextIsochrone = true
    fetchLocalBaseline(lat, lng)
    fetchAndDrawGroups(lat, lng)
    updateIsochrone()
  }

  // Fetch the natural deprivation fraction for this location: the proportion of
  // freeglers within a 30-minute drive (no fairness adjustment) that are in
  // deprived quintiles (Q1–Q3). This becomes the "proportionate" baseline for
  // the swingometer so that the needle measures algorithmic effect, not the
  // area's inherent demographics.
  async function fetchLocalBaseline(lat, lng) {
    try {
      const url = apiUrl(
        `/v1/fairness?lat=${lat.toFixed(6)}&lng=${lng.toFixed(6)}&minutes=30&mode=drive&fairness=0`
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

  function chaikinSmooth(ring, iterations) {
    iterations = iterations || 3
    let pts = ring.slice(0, -1)
    for (let iter = 0; iter < iterations; iter++) {
      const smoothed = []
      const n = pts.length
      for (let j = 0; j < n; j++) {
        const a = pts[j]
        const b = pts[(j + 1) % n]
        smoothed.push([0.75 * a[0] + 0.25 * b[0], 0.75 * a[1] + 0.25 * b[1]])
        smoothed.push([0.25 * a[0] + 0.75 * b[0], 0.25 * a[1] + 0.75 * b[1]])
      }
      pts = smoothed
    }
    pts.push(pts[0])
    return pts
  }

  function geoToLeaflet(coords) {
    return chaikinSmooth(coords).map(([lng, lat]) => [lat, lng])
  }

  let lastIsoData = null

  function drawPolygons(data, transitionMs) {
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

    if (hasRing(data.standard)) {
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

  function pointInRing(fLng, fLat, ring) {
    let inside = false
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const [xi, yi] = ring[i]
      const [xj, yj] = ring[j]
      if (
        yi > fLat !== yj > fLat &&
        fLng < ((xj - xi) * (fLat - yi)) / (yj - yi) + xi
      )
        inside = !inside
    }
    return inside
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
    // insideCount     — all freeglers inside the standard isochrone (q≠0), used
    //                   for the "X would be notified" count bar.
    // quintileTaggedCount — freeglers inside a Q1–Q5 polygon (q≥1).  Used as
    //                   the denominator for the swingometer so that untagged
    //                   freeglers (q==-1) don't distort the deprivation %.
    // deprivedCount   — freeglers in Q1–Q3 polygons (swingometer numerator).
    let insideCount = 0
    let quintileTaggedCount = 0
    let deprivedCount = 0
    freeglersGrid.forEach((g, i) => {
      const q = quintileOfFreegler(g.lng, g.lat, data)
      if (q !== 0) {
        // q == -1: inside standard isochrone but no quintile tag (untagged road).
        // q == 1-5: inside a quintile polygon.
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

    // In normal view: totalLocated = full count within the current isochrone.
    // During ripple animation: allFreeglers was fetched at 30 min (full range);
    // insideCount is the sample count inside the current (smaller) polygon.
    // Scale by totalLocated/sampleSize to estimate the true count inside.
    const totalLocated = totalLocatedFromServer || allFreeglers.length
    const sampleSize = allFreeglers.length
    const isRipple = ripplePlaying || rippleFrames.length > 0
    const estimatedInsideLocated = isRipple && sampleSize > 0
      ? Math.round(insideCount * (totalLocated / sampleSize))
      : totalLocated

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

  function segmentsIntersect(ax, ay, bx, by, cx, cy, dx, dy) {
    const d1x = bx - ax
    const d1y = by - ay
    const d2x = dx - cx
    const d2y = dy - cy
    const cross = d1x * d2y - d1y * d2x
    if (Math.abs(cross) < 1e-12) return false
    const t = ((cx - ax) * d2y - (cy - ay) * d2x) / cross
    const u = ((cx - ax) * d1y - (cy - ay) * d1x) / cross
    return t > 0 && t < 1 && u > 0 && u < 1
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

    if (ripplePlaying) {
      clearTimeout(rippleTimer)
      rippleTimer = setTimeout(stepRipple, 2000)
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
    rippleStep = frameIdx
    // Sync rippleScheduleIdx to the first schedule entry at or beyond frameIdx
    // so that Resume continues from the right position after a manual scrub.
    if (rippleSchedule.length) {
      rippleScheduleIdx = rippleSchedule.findIndex((fi) => fi >= frameIdx)
      if (rippleScheduleIdx < 0) rippleScheduleIdx = rippleSchedule.length
    }
    const data = rippleFrames[frameIdx]
    updateTimeline(frameIdx, rippleFrames.length)
    if (data) {
      drawPolygons(data, 0)
      updateStats(data)
      updateFreeglersInside(data)
      drawGroupsOverlay()
    }
  }

  // Source frames: 120 frames at 0.25-min steps = 0.25..30 min drive.
  // Fine granularity ensures the boundary genuinely moves at each step in the
  // populated zone, rather than dwelling on the same polygon shape.
  const RIPPLE_FRAMES = 120
  const RIPPLE_STEP_MINS = 0.25

  let rippleFrames = []
  let rippleStep = 0
  let rippleTimer = null
  let ripplePlaying = false
  let crossPostingDetected = false
  let rippleMaxImbalance = null  // {pct, minute} — worst affluence bias seen during animation

  // Population-based animation schedule.
  // rippleSchedule[i] is the index into rippleFrames[] to display at animation tick i.
  // Built in startRipple(); each tick advances by ~1/N_POP_STEPS of total freeglers.
  let rippleSchedule = []
  let rippleScheduleIdx = 0

  // Number of equal-population animation steps in the populated zone.
  // 300 steps means each step adds ~0.33% of total freeglers, giving a very
  // gradual crawl through populated areas rather than sudden jumps.
  const N_POP_STEPS = 300

  /**
   * Build a population-based animation schedule from the pre-fetched frames.
   *
   * Returns an array of frame indices (into rippleFrames[]) to display in
   * sequence.  Two phases:
   *   Phase 1 – Before the first freegler: show every PRE_SKIP-th frame so
   *             the boundary visibly expands from the origin without dwelling.
   *   Phase 2 – From the first freegler onward: N_POP_STEPS equal-population
   *             thresholds.  Each threshold maps to the first frame whose
   *             cumulative freegler count meets or exceeds it, so the animation
   *             slows down exactly where freeglers are dense and speeds past
   *             empty outer regions.
   *
   * Multiple consecutive schedule entries can point to the same frame index
   * when a single frame spans several population thresholds (dense zone); the
   * 0.25-min resolution means those frames still represent genuine new territory.
   */
  function buildRippleSchedule(frames) {
    if (!freeglersGrid.length) {
      // No grid loaded: fall back to showing every frame at equal pace.
      return frames.map((_, i) => i)
    }

    // --- Step 1: cumulative freegler count per frame ---
    const cumulative = frames.map((data) => {
      if (!data || !hasRing(data.standard)) return 0
      const ring = data.standard.geometry.coordinates[0]
      let n = 0
      freeglersGrid.forEach((g) => {
        if (pointInRing(g.lng, g.lat, ring)) n += g.count
      })
      return n
    })
    // Ensure monotonically non-decreasing (guard against null/bad frames).
    for (let i = 1; i < cumulative.length; i++) {
      if (cumulative[i] < cumulative[i - 1]) cumulative[i] = cumulative[i - 1]
    }

    const total = cumulative[cumulative.length - 1] || 0
    if (total === 0) {
      // No freeglers anywhere: show all frames at equal pace.
      return frames.map((_, i) => i)
    }

    // --- Step 2: find first frame that contains any freegler ---
    const firstFreegler = cumulative.findIndex((c) => c > 0)

    const schedule = []

    // --- Phase 1: fast march through the pre-freegler zone ---
    // Show every PRE_SKIP-th frame so the boundary is seen to grow, but we
    // don't dwell in the empty area.  Always include the frame just before
    // the first freegler so there is a clean hand-off.
    const PRE_SKIP = 3
    for (let fi = 0; fi < firstFreegler; fi += PRE_SKIP) {
      schedule.push(fi)
    }
    if (firstFreegler > 0) {
      const prev = firstFreegler - 1
      if (!schedule.length || schedule[schedule.length - 1] !== prev) {
        schedule.push(prev)
      }
    }

    // --- Phase 2: N_POP_STEPS equal-population steps ---
    const stepSize = total / N_POP_STEPS
    const startFi = Math.max(0, firstFreegler)
    for (let k = 1; k <= N_POP_STEPS; k++) {
      const threshold = k * stepSize
      let found = frames.length - 1
      for (let fi = startFi; fi < frames.length; fi++) {
        if (cumulative[fi] >= threshold) {
          found = fi
          break
        }
      }
      schedule.push(found)
    }

    // Always end on the very last frame.
    if (schedule[schedule.length - 1] !== frames.length - 1) {
      schedule.push(frames.length - 1)
    }

    return schedule
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
        clearTimeout(rippleTimer)
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

    const fairness = parseInt(fairnessSlider.value) / 100
    // RIPPLE_FRAMES frames at RIPPLE_STEP_MINS-min steps: 0.25, 0.50, ..., 30.0 min.
    // Fine granularity means the boundary genuinely moves at each animation
    // step in the populated zone, rather than dwelling on the same shape.
    const promises = Array.from({ length: RIPPLE_FRAMES }, (_, i) => {
      const m = ((i + 1) * RIPPLE_STEP_MINS).toFixed(2)
      const url = apiUrl(
        `/v1/fairness?lat=${currentLat.toFixed(6)}&lng=${currentLng.toFixed(
          6
        )}&minutes=${m}&mode=${currentMode}&fairness=${fairness}`
      )
      return fetch(url)
        .then((r) => r.json())
        .catch(() => null)
    })
    rippleFrames = await Promise.all(promises)

    // Always fetch at 30 minutes so all dots in the full animation range are
    // available. The animation expands from 0.25→30 min, so we need every
    // freegler reachable at minute 30 in allFreeglers from the start.
    await fetchFreeglers(30)
    drawFreeglersLayer()

    // Build a population-based animation schedule: each tick covers
    // ~1/N_POP_STEPS of total freeglers.  The boundary crawls quickly through
    // empty early frames and slows down exactly where freeglers are dense.
    rippleSchedule = buildRippleSchedule(rippleFrames)
    rippleScheduleIdx = 0
    rippleStep = rippleSchedule[0] ?? 0

    ripplePlaying = true
    crossPostingDetected = false
    rippleMaxImbalance = null
    btn.disabled = false
    btn.textContent = '⏹ Stop'
    btn.classList.add('rpl-stop')
    document.getElementById('rippling-freegler-bar').style.display = ''
    buildTimeline(rippleFrames.length)
    document.getElementById('rippling-timeline').style.display = ''

    stepRipple()
  }

  function stopRipple() {
    ripplePlaying = false
    crossPostingDetected = false
    rippleFrames = []
    rippleSchedule = []
    rippleScheduleIdx = 0
    timelineBuilt = false
    clearTimeout(rippleTimer)
    const btn = document.getElementById('rippling-btn')
    btn.textContent = '▶ Animate ripple'
    btn.classList.remove('rpl-stop')
    document.getElementById(
      'rippling-info'
    ).textContent = `by ${currentMode} · ${RIPPLE_STEP_MINS}–30 min`
    document.getElementById('rippling-freegler-bar').style.display = 'none'
    document.getElementById('rippling-timeline').style.display = 'none'
    if (currentLat !== null) fetchAndDrawGroups(currentLat, currentLng)
    else drawGroupsOverlay()
  }

  function stepRipple() {
    if (rippleScheduleIdx >= rippleSchedule.length) {
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

    // Advance to the frame specified by the population schedule.
    rippleStep = rippleSchedule[rippleScheduleIdx]
    const data = rippleFrames[rippleStep]

    // Actual drive minutes: frame 0 = RIPPLE_STEP_MINS, frame N-1 = 30 min.
    const mDrive = (rippleStep + 1) * RIPPLE_STEP_MINS
    const minuteLabel = Number.isInteger(mDrive) ? String(mDrive) : mDrive.toFixed(2)
    document.getElementById(
      'rippling-info'
    ).textContent = `${currentMode} · ${minuteLabel} min`
    updateTimeline(rippleStep, rippleFrames.length)
    timeSlider.value = Math.round(mDrive)

    const spd =
      parseInt(document.getElementById('rippling-speed-slider').value) || 3
    const delay = Math.round(20000 / Math.pow(spd, 1.4))

    if (data) {
      drawPolygons(data, Math.round(delay * 0.8))
      updateStats(data)
      updateFreeglersInside(data)
      drawGroupsOverlay()
      drawFreeglersLayer()

      const hours = frameToHours(rippleStep, rippleFrames.length)
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

      // Zoom map to keep the expanding boundary in view.
      // Use a lookahead frame (from the schedule) to avoid sudden zoom jumps.
      if (rippleScheduleIdx % 2 === 0) {
        const aheadIdx = Math.min(rippleScheduleIdx + 5, rippleSchedule.length - 1)
        const lookahead = rippleFrames[rippleSchedule[aheadIdx]]
        const zoomData =
          lookahead && hasRing(lookahead.standard) ? lookahead : data
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

    rippleScheduleIdx++
    if (ripplePlaying) rippleTimer = setTimeout(stepRipple, delay)
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

/* Smooth polygon boundary crawl: CSS d-attribute transitions let the browser
   interpolate SVG path shapes between frames when the vertex count is stable.
   Leaflet's setLatLngs calls path.setAttribute('d', …) directly, so any CSS
   transition on `d` takes effect here.  When vertex counts change the browser
   falls back to a discrete jump — that's acceptable since rapid count changes
   only occur during fast isochrone growth. */
#rippling-map .leaflet-overlay-pane path {
  transition: d 450ms ease-out;
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

#rippling-legend {
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
#rippling-legend h4 {
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
