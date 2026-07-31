'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const {
  parseSessionStart,
  classifyDevice,
  buildDeviceSummary,
  compareVersion,
  versionFreshness,
  webBuildFreshness,
} = require('./device-summary')

const UA = {
  winEdge:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0',
  macSafari:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
  androidApp:
    'Mozilla/5.0 (Linux; Android 16; SM-A165F Build/BP4A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/150 Mobile Safari/537.36',
  androidChrome:
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150 Mobile Safari/537.36',
  iphone:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
}

test('parseSessionStart returns null for non-session_start lines', () => {
  assert.equal(parseSessionStart('{"event_type":"page_view"}'), null)
  assert.equal(parseSessionStart('not json'), null)
  assert.equal(parseSessionStart(''), null)
})

test('parseSessionStart extracts device fields', () => {
  const d = parseSessionStart(
    JSON.stringify({
      event_type: 'session_start',
      session_id: 's1',
      timestamp: '2026-07-27T13:08:11Z',
      user_agent: UA.winEdge,
      platform: 'Win32',
      viewport_width: 994,
      viewport_height: 413,
      screen_width: 1093,
      screen_height: 615,
      device_pixel_ratio: 1.375,
    })
  )
  assert.equal(d.sessionId, 's1')
  assert.deepEqual(d.viewport, { w: 994, h: 413 })
  assert.deepEqual(d.screen, { w: 1093, h: 615 })
  assert.equal(d.dpr, 1.375)
})

test('classifyDevice: Windows/Edge desktop', () => {
  const c = classifyDevice({ userAgent: UA.winEdge, platform: 'Win32' })
  assert.equal(c.os, 'Windows')
  assert.equal(c.osIcon, '🪟')
  assert.equal(c.formFactor, 'desktop')
  assert.equal(c.formIcon, 'laptop')
  assert.equal(c.browser, 'Edge')
  assert.equal(c.isApp, false)
})

test('classifyDevice: macOS Safari desktop', () => {
  const c = classifyDevice({ userAgent: UA.macSafari, platform: 'MacIntel' })
  assert.equal(c.os, 'macOS')
  assert.equal(c.browser, 'Safari')
  assert.equal(c.formFactor, 'desktop')
})

test('classifyDevice: Android native app (WebView "; wv)")', () => {
  const c = classifyDevice({ userAgent: UA.androidApp, platform: '' })
  assert.equal(c.os, 'Android')
  assert.equal(c.osIcon, '🤖')
  assert.equal(c.formFactor, 'mobile')
  assert.equal(c.isApp, true)
  assert.equal(c.mode, 'app')
})

test('classifyDevice: Android mobile browser is NOT the app', () => {
  const c = classifyDevice({ userAgent: UA.androidChrome, platform: '' })
  assert.equal(c.isApp, false)
  assert.equal(c.mode, 'mobile-web')
  assert.equal(c.browser, 'Chrome')
})

test('classifyDevice: iPhone -> iOS mobile', () => {
  const c = classifyDevice({ userAgent: UA.iphone, platform: 'iPhone' })
  assert.equal(c.os, 'iOS')
  assert.equal(c.osIcon, '🍎')
  assert.equal(c.formFactor, 'mobile')
})

test('buildDeviceSummary collapses repeat sessions and sorts newest-first', () => {
  const mk = (ts, ua, plat, vp, sid) => ({
    userAgent: ua,
    platform: plat,
    viewport: vp,
    screen: vp,
    dpr: 1,
    isTouch: false,
    ts,
    sessionId: sid,
  })
  const recs = [
    mk('2026-07-27T09:00:00Z', UA.winEdge, 'Win32', { w: 994, h: 413 }, 'a'),
    mk('2026-07-27T13:00:00Z', UA.winEdge, 'Win32', { w: 994, h: 413 }, 'b'), // same device, newer
    mk('2026-07-27T19:00:00Z', UA.macSafari, 'MacIntel', { w: 1875, h: 971 }, 'c'),
  ]
  const devices = buildDeviceSummary(recs)
  assert.equal(devices.length, 2, 'two distinct devices')
  assert.equal(devices[0].os, 'macOS', 'newest-first')
  const win = devices.find((d) => d.os === 'Windows')
  assert.equal(win.sessions, 2, 'two Windows sessions collapsed into one row')
  assert.equal(win.lastSeen, '2026-07-27T13:00:00Z')
})

test('classifyDevice maps a browser brand icon (fab), null when unknown', () => {
  assert.equal(classifyDevice({ userAgent: UA.winEdge }).browserIcon, 'edge')
  assert.equal(classifyDevice({ userAgent: UA.macSafari }).browserIcon, 'safari')
  assert.equal(classifyDevice({ userAgent: UA.androidChrome }).browserIcon, 'chrome')
  // Samsung Internet has no registered icon -> null so the UI omits the glyph.
  const samsung =
    'Mozilla/5.0 (Linux; Android 14; SM) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23 Chrome/115 Mobile Safari/537.36'
  assert.equal(classifyDevice({ userAgent: samsung }).browserIcon, null)
})

test('classifyDevice extracts the browser major version', () => {
  assert.equal(classifyDevice({ userAgent: UA.winEdge }).browserVersion, '150')
  assert.equal(classifyDevice({ userAgent: UA.androidChrome }).browserVersion, '150')
  // Safari reports its own version via Version/NN, not the WebKit build.
  assert.equal(classifyDevice({ userAgent: UA.macSafari }).browserVersion, '17')
  assert.equal(classifyDevice({ userAgent: UA.iphone }).browserVersion, '17')
})

test('buildDeviceSummary groups window sizes on one device instead of many cards', () => {
  const mk = (ts, vp) => ({
    userAgent: UA.winEdge,
    platform: 'Win32',
    viewport: vp,
    screen: { w: 1920, h: 1080 }, // same physical screen throughout
    ts,
    sessionId: ts,
  })
  const recs = [
    mk('2026-07-27T09:00:00Z', { w: 994, h: 413 }),
    mk('2026-07-27T10:00:00Z', { w: 994, h: 413 }), // repeat window size
    mk('2026-07-27T13:00:00Z', { w: 1600, h: 900 }), // different window, same device
  ]
  const devices = buildDeviceSummary(recs)
  assert.equal(devices.length, 1, 'one physical device, not three cards')
  const dev = devices[0]
  assert.equal(dev.sessions, 3)
  assert.deepEqual(dev.screen, { w: 1920, h: 1080 }, 'fixed screen kept')
  assert.equal(dev.windows.length, 2, 'two distinct window sizes')
  // Newest window first; repeated size collapses with its session count.
  assert.deepEqual({ w: dev.windows[0].w, h: dev.windows[0].h }, { w: 1600, h: 900 })
  const small = dev.windows.find((w) => w.w === 994)
  assert.equal(small.sessions, 2)
})

test('parseSessionStart extracts app_version', () => {
  const d = parseSessionStart(
    JSON.stringify({
      event_type: 'session_start',
      session_id: 's1',
      user_agent: UA.winEdge,
      app_version: '3.2.28',
    })
  )
  assert.equal(d.appVersion, '3.2.28')
})

test('compareVersion handles dotted numeric versions', () => {
  assert.equal(compareVersion('3.2.28', '3.2.28'), 0)
  assert.equal(compareVersion('3.2.27', '3.2.28'), -1)
  assert.equal(compareVersion('3.3.0', '3.2.28'), 1)
  assert.equal(compareVersion('3.2', '3.2.0'), 0)
  assert.equal(compareVersion('x', '3.2.28'), null)
  assert.equal(compareVersion(null, '3.2.28'), null)
})

test('versionFreshness flags stale / current / unknown', () => {
  assert.equal(versionFreshness('3.2.27', '3.2.28'), 'stale')
  assert.equal(versionFreshness('3.2.28', '3.2.28'), 'current')
  assert.equal(versionFreshness('3.3.0', '3.2.28'), 'current')
  assert.equal(versionFreshness(null, '3.2.28'), 'unknown')
  assert.equal(versionFreshness('3.2.28', null), 'unknown')
})

test('APP surfaces its native version but freshness is unknown (no ref yet)', () => {
  const rec = {
    userAgent: UA.androidApp,
    platform: '',
    viewport: { w: 384, h: 700 },
    ts: '2026-07-27T13:00:00Z',
    sessionId: 'a',
    appVersion: '3.2.27',
  }
  const [dev] = buildDeviceSummary([rec], '3.2.28')
  assert.equal(dev.isApp, true)
  assert.equal(dev.appVersion, '3.2.27', 'native app version is surfaced')
  // We have the member's version but not the current released one to compare to
  // (currentVersion is the web build constant), so we don't assert freshness.
  assert.equal(dev.freshness, 'unknown')
})

test('WEB freshness is a build-date age, not a version comparison', () => {
  const now = Date.parse('2026-07-27T00:00:00Z')
  const mk = (buildDate) => ({
    userAgent: UA.winEdge,
    platform: 'Win32',
    viewport: { w: 994, h: 413 },
    screen: { w: 994, h: 413 },
    ts: '2026-07-27T13:00:00Z',
    sessionId: buildDate,
    buildDate,
  })
  // Built 5 days ago -> stale; built today -> current; no build date -> unknown.
  assert.equal(buildDeviceSummary([mk('2026-07-22T00:00:00Z')], '9.9.9', now)[0].freshness, 'stale')
  assert.equal(buildDeviceSummary([mk('2026-07-27T00:00:00Z')], '9.9.9', now)[0].freshness, 'current')
  assert.equal(buildDeviceSummary([mk(null)], '9.9.9', now)[0].freshness, 'unknown')
})

test('webBuildFreshness thresholds on age in days', () => {
  const now = Date.parse('2026-07-27T12:00:00Z')
  assert.equal(webBuildFreshness('2026-07-27T00:00:00Z', now), 'current')
  assert.equal(webBuildFreshness('2026-07-24T00:00:00Z', now), 'stale') // >2 days
  assert.equal(webBuildFreshness(null, now), 'unknown')
  assert.equal(webBuildFreshness('not-a-date', now), 'unknown')
})
