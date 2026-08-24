/**
 * Device summary for the AI Support Helper.
 *
 * A deterministic (no-AI) view of the recent devices a member has used, built
 * from Loki `client` `session_start` events (which carry viewport/screen/DPR/
 * user-agent/platform) correlated to the member via their `api` sessions.
 *
 * What we CAN show reliably: OS + form-factor (mobile/tablet/desktop), whether
 * they're in the native app vs a browser, browser name, viewport & screen size,
 * device-pixel-ratio (which also reveals browser zoom), per-device last-seen and
 * a session count.
 *
 * What we CANNOT show yet: the running APP VERSION and whether their loaded web
 * build is up to date. Neither is in the logs today — requests only carry
 * X-Freegle-Page/Session/Site/Modal, and session_start has no build/version
 * field. Surfacing those needs the frontend to log its build version. We return
 * them as null so the UI can render "unknown" rather than a fabricated value.
 */

// Parse one Loki `client` log line into a session_start device record, or null.
function parseSessionStart(line) {
  let o
  try {
    o = typeof line === 'string' ? JSON.parse(line) : line
  } catch (_) {
    return null
  }
  if (!o || o.event_type !== 'session_start') return null
  const vw = Number(o.viewport_width)
  const vh = Number(o.viewport_height)
  const sw = Number(o.screen_width)
  const sh = Number(o.screen_height)
  return {
    sessionId: o.session_id || null,
    ts: o.timestamp || null,
    userAgent: o.user_agent || '',
    platform: o.platform || '',
    viewport: vw && vh ? { w: vw, h: vh } : null,
    screen: sw && sh ? { w: sw, h: sh } : null,
    dpr: Number(o.device_pixel_ratio) || null,
    orientation: o.orientation || null,
    isTouch: !!o.is_touch,
    isStandalone: !!o.is_standalone,
    language: o.language || null,
    appVersion: o.app_version || null,
    // ISO build timestamp of the loaded frontend bundle (web staleness = how
    // old the code they're running is). Absent until the frontend logs it.
    buildDate: o.build_date || null,
    url: o.url || '',
  }
}

// Fields that one session_start may carry and another (for the same session)
// may not. Merged by dedupeSessions below.
const MERGEABLE_FIELDS = [
  'appVersion',
  'buildDate',
  'userAgent',
  'platform',
  'screen',
  'viewport',
  'dpr',
  'orientation',
  'language',
]

// Collapse the session_start records of a single browsing session into one.
//
// The app logs session_start TWICE: once the moment the client logger starts
// (before Capacitor has told us anything, so no app version), and again from
// the mobile store once App.getInfo()/Device.getInfo() have returned. Both
// carry the same session_id. Dropping whichever arrives second would make the
// app version depend on Loki's return order, and counting both would double
// every app member's session count — so merge them: keep the first record and
// fill in whatever it is missing from its duplicates, tracking the latest
// timestamp. Records with no session id are passed through untouched.
function dedupeSessions(records) {
  const byId = new Map()
  const out = []
  for (const d of records) {
    if (!d) continue
    if (!d.sessionId) {
      out.push(d)
      continue
    }
    const prev = byId.get(d.sessionId)
    if (!prev) {
      const copy = { ...d }
      byId.set(d.sessionId, copy)
      out.push(copy)
      continue
    }
    for (const k of MERGEABLE_FIELDS) {
      if ((prev[k] === null || prev[k] === undefined || prev[k] === '') && d[k]) {
        prev[k] = d[k]
      }
    }
    if (d.ts && (!prev.ts || d.ts > prev.ts)) prev.ts = d.ts
  }
  return out
}

// Compare dotted numeric versions (e.g. "3.2.28"). Returns -1, 0, 1, or null
// if either side is unparseable.
function compareVersion(a, b) {
  if (!a || !b) return null
  const pa = String(a).split('.').map((n) => parseInt(n, 10))
  const pb = String(b).split('.').map((n) => parseInt(n, 10))
  if (pa.some(Number.isNaN) || pb.some(Number.isNaN)) return null
  const len = Math.max(pa.length, pb.length)
  for (let i = 0; i < len; i++) {
    const x = pa[i] || 0
    const y = pb[i] || 0
    if (x !== y) return x < y ? -1 : 1
  }
  return 0
}

// 'current' | 'stale' | 'unknown' for a device's loaded version vs the deployed one.
function versionFreshness(deviceVersion, currentVersion) {
  if (!deviceVersion || !currentVersion) return 'unknown'
  const cmp = compareVersion(deviceVersion, currentVersion)
  if (cmp === null) return 'unknown'
  // Behind the deployed version => needs a refresh. Equal or ahead => current.
  return cmp < 0 ? 'stale' : 'current'
}

// Web staleness is a BUILD DATE question, not a version one: a web build older
// than `staleDays` means the member is running old code and a refresh will fix
// it. 'current' | 'stale' | 'unknown' (unknown when no build date is logged).
function webBuildFreshness(buildDate, nowMs, staleDays = 2) {
  if (!buildDate) return 'unknown'
  const built = Date.parse(buildDate)
  if (Number.isNaN(built)) return 'unknown'
  const ageDays = (nowMs - built) / 86400000
  return ageDays > staleDays ? 'stale' : 'current'
}

// Classify a device from its user-agent / platform / flags.
// osIcon is an emoji; formIcon is a registered FontAwesome name.
function classifyDevice(d) {
  const ua = d.userAgent || ''
  const ual = ua.toLowerCase()
  const plat = (d.platform || '').toLowerCase()

  let os = 'Unknown'
  let osIcon = '❓'
  let formFactor = 'desktop'

  if (/iphone|ipad|ipod/.test(ual) || /iphone|ipad/.test(plat)) {
    os = 'iOS'
    osIcon = '🍎'
    formFactor = /ipad/.test(ual) ? 'tablet' : 'mobile'
  } else if (/android/.test(ual)) {
    os = 'Android'
    osIcon = '🤖'
    // Android UA says "Mobile" for phones; tablets omit it.
    formFactor = /mobile/.test(ual) ? 'mobile' : 'tablet'
  } else if (/windows|win32|win64/.test(plat) || /windows/.test(ual)) {
    os = 'Windows'
    osIcon = '🪟'
    formFactor = 'desktop'
  } else if (/macintel|mac os|macintosh/.test(plat) || /macintosh|mac os x/.test(ual)) {
    os = 'macOS'
    osIcon = '🍎'
    // A touch "Mac" is really an iPad reporting a desktop UA.
    formFactor = d.isTouch ? 'tablet' : 'desktop'
  } else if (/linux/.test(plat) || /linux/.test(ual)) {
    os = 'Linux'
    osIcon = '🐧'
    formFactor = 'desktop'
  }

  // Native (Capacitor) app vs browser. The Android app is an Android WebView:
  // its UA contains "; wv)". The iOS app runs standalone.
  const isAndroidApp = /;\s*wv\)/.test(ua)
  const isIosApp = os === 'iOS' && d.isStandalone
  const isApp = isAndroidApp || isIosApp

  // Browser name + major version (only meaningful when NOT the app). Order
  // matters: Edge/Opera/Samsung UAs all also contain "Chrome/", so match those
  // first. Edge desktop is "Edg/", Android "EdgA/", iOS "EdgiOS/".
  let browser = 'Browser'
  let browserVersion = null
  let bm
  if ((bm = /edg(?:e|a|ios)?\/(\d+)/i.exec(ua))) {
    browser = 'Edge'
    browserVersion = bm[1]
  } else if ((bm = /(?:opr|opera)\/(\d+)/i.exec(ua))) {
    browser = 'Opera'
    browserVersion = bm[1]
  } else if ((bm = /samsungbrowser\/(\d+)/i.exec(ua))) {
    browser = 'Samsung Internet'
    browserVersion = bm[1]
  } else if ((bm = /firefox\/(\d+)/i.exec(ua))) {
    browser = 'Firefox'
    browserVersion = bm[1]
  } else if ((bm = /chrome\/(\d+)/i.exec(ua))) {
    browser = 'Chrome'
    browserVersion = bm[1]
  } else if (/safari\//i.test(ua) && !/chrome/i.test(ua)) {
    browser = 'Safari'
    // Safari reports its own version as "Version/17.0" (Safari/605… is WebKit).
    const sm = /version\/(\d+)/i.exec(ua)
    browserVersion = sm ? sm[1] : null
  }

  // Registered FA brand-icon name for the browser (used with the 'fab' prefix
  // in the UI), or null when there's no icon for it (unknown/Opera/Samsung) so
  // the UI omits the glyph rather than showing a wrong one.
  let browserIcon = null
  if (browser === 'Chrome') browserIcon = 'chrome'
  else if (browser === 'Safari') browserIcon = 'safari'
  else if (browser === 'Edge') browserIcon = 'edge'
  else if (browser === 'Firefox') browserIcon = 'firefox-browser'

  let mode, modeLabel
  if (isApp) {
    mode = 'app'
    modeLabel = 'App'
  } else if (formFactor === 'mobile' || formFactor === 'tablet') {
    mode = 'mobile-web'
    modeLabel = `${browser} (mobile web)`
  } else {
    mode = 'desktop-web'
    modeLabel = `${browser} (desktop)`
  }

  // Registered FA icons: faMobileAlt / faTabletAlt / faLaptop.
  const formIcon =
    formFactor === 'mobile'
      ? 'mobile-alt'
      : formFactor === 'tablet'
        ? 'tablet-alt'
        : 'laptop'

  // App version: not logged anywhere today; left null so the UI shows "unknown"
  // rather than inventing a value. Wire in here if the app adds a version token.
  const appVersion = null

  return { os, osIcon, formFactor, formIcon, mode, modeLabel, browser, browserIcon, browserVersion, isApp, appVersion }
}

// A device signature: one OS + physical screen + browser + mode. Deliberately
// NOT keyed on the window (viewport) size or device-pixel-ratio, so the same
// physical device used at different window sizes collapses into ONE card that
// lists those sizes, rather than a card per window.
function deviceKey(d, c) {
  const scr = d.screen ? `${d.screen.w}x${d.screen.h}` : '?'
  return `${c.os}|${scr}|${c.browser}|${c.mode}`
}

// Aggregate parsed session_start records into a per-device summary list.
// `records` is an array of parseSessionStart() outputs. `currentVersion` is the
// deployed MOBILE_VERSION (used to judge whether an APP is up to date). `nowMs`
// anchors web build-date staleness. Each device gets `freshness`
// ('current' | 'stale' | 'unknown') — app by version, web by build-date age —
// and a `windows` array of the distinct window sizes seen. Returns newest-first.
function buildDeviceSummary(records, currentVersion = null, nowMs = Date.now()) {
  const byKey = new Map()
  for (const d of records) {
    if (!d) continue
    const c = classifyDevice(d)
    const key = deviceKey(d, c)
    let dev = byKey.get(key)
    if (!dev) {
      dev = {
        os: c.os,
        formFactor: c.formFactor,
        formIcon: c.formIcon,
        mode: c.mode,
        modeLabel: c.modeLabel,
        browser: c.browser,
        browserIcon: c.browserIcon,
        browserVersion: c.browserVersion,
        isApp: c.isApp,
        appVersion: d.appVersion,
        buildDate: d.buildDate || null,
        screen: d.screen || null,
        platform: d.platform,
        userAgent: d.userAgent,
        sessions: 0,
        firstSeen: d.ts,
        lastSeen: d.ts,
        _windows: new Map(),
      }
      byKey.set(key, dev)
    }
    dev.sessions += 1
    // Track newest version (users upgrade) and the seen window. Screen is fixed
    // per device, but keep the newest reading defensively.
    if (d.ts && (!dev.lastSeen || d.ts > dev.lastSeen)) {
      dev.lastSeen = d.ts
      if (d.appVersion) dev.appVersion = d.appVersion
      if (d.buildDate) dev.buildDate = d.buildDate
      if (c.browserVersion) dev.browserVersion = c.browserVersion
      if (d.screen) dev.screen = d.screen
    }
    if (d.ts && (!dev.firstSeen || d.ts < dev.firstSeen)) dev.firstSeen = d.ts
    // Collect the distinct window (viewport) sizes used on this device.
    if (d.viewport) {
      const wk = `${d.viewport.w}x${d.viewport.h}`
      let w = dev._windows.get(wk)
      if (!w) {
        w = { w: d.viewport.w, h: d.viewport.h, sessions: 0, lastSeen: d.ts }
        dev._windows.set(wk, w)
      }
      w.sessions += 1
      if (d.ts && (!w.lastSeen || d.ts > w.lastSeen)) w.lastSeen = d.ts
    }
  }
  const list = Array.from(byKey.values())
  for (const dev of list) {
    dev.windows = Array.from(dev._windows.values()).sort((a, b) =>
      String(b.lastSeen || '').localeCompare(String(a.lastSeen || ''))
    )
    delete dev._windows
    // Two different questions. For the WEB, "up to date" is a build-date age:
    // an old bundle means a refresh will fix them. For the APP it is a version
    // comparison against the current release — config.js MOBILE_VERSION is
    // hand-bumped in step with the app release ("Bump to 3.2.28", and
    // android/app/build.gradle's versionName default tracks it), so it is the
    // right reference for "are they on the current app?". Either way a missing
    // value yields 'unknown', so sessions logged before the app reported its
    // version simply show no badge rather than a wrong one.
    dev.freshness = dev.isApp
      ? versionFreshness(dev.appVersion, currentVersion)
      : webBuildFreshness(dev.buildDate, nowMs)
  }
  return list.sort((a, b) =>
    String(b.lastSeen || '').localeCompare(String(a.lastSeen || ''))
  )
}

module.exports = {
  parseSessionStart,
  dedupeSessions,
  classifyDevice,
  deviceKey,
  buildDeviceSummary,
  webBuildFreshness,
  compareVersion,
  versionFreshness,
}
