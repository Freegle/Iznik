#!/usr/bin/env node
// Preflight for the Playwright `app` project: attach to the app's WebView the way
// tests/e2e/fixtures.js does, and say exactly what failed if it cannot. Run by run.sh
// before Playwright, whose own report of a worker-fixture failure comes only at the
// end of the run.
//
// Usage: node attach-check.cjs <iznik-nuxt3 dir>
// Environment: APP_E2E_SERIAL, APP_E2E_PACKAGE, ANDROID_ADB_SERVER_PORT (optional).
const path = require('path')

const nuxtDir = process.argv[2]
const serial = process.env.APP_E2E_SERIAL
const pkg = process.env.APP_E2E_PACKAGE || 'org.ilovefreegle.direct'
const port = Number(process.env.ANDROID_ADB_SERVER_PORT) || 5037

function fail(msg) {
  console.error(`[attach-check] FAILED: ${msg}`)
  process.exit(1)
}

;(async () => {
  if (!nuxtDir || !serial) fail('usage: attach-check.cjs <iznik-nuxt3 dir>, with APP_E2E_SERIAL set')
  let android
  try {
    android = require(path.join(nuxtDir, 'node_modules', 'playwright'))._android
  } catch (e) {
    fail(`cannot load playwright from ${nuxtDir}/node_modules: ${e.message}`)
  }
  const devices = await android.devices({ port }).catch((e) => fail(`adb server on port ${port}: ${e.message}`))
  console.log(`[attach-check] adb server port ${port}: ${devices.map((d) => d.serial()).join(', ') || 'no devices'}`)
  const device = devices.find((d) => d.serial() === serial)
  if (!device) fail(`${serial} is not among the devices Playwright sees`)
  // As the fixtures do: bring the activity up (a just-booted emulator's Play services can
  // take the app down and leave it restarted for a broadcast with no WebView), wait for
  // the app's devtools socket, then attach by socket name, never by package (Playwright's
  // own package lookup, `ps -A | grep <pid>` with the last line winning, can be wrong).
  const activity = process.env.APP_E2E_ACTIVITY || '.MainActivity'
  let appPid = 0
  let socketName = ''
  for (let i = 0; i < 60 && !socketName; i++) {
    await device.shell(`am start -W -n ${pkg}/${activity}`)
    appPid = parseInt(String(await device.shell(`pidof ${pkg}`)).trim(), 10)
    if (appPid && String(await device.shell('cat /proc/net/unix')).includes(`webview_devtools_remote_${appPid}`)) {
      socketName = `webview_devtools_remote_${appPid}`
    } else {
      await new Promise((resolve) => setTimeout(resolve, 2000))
    }
  }
  if (!socketName) fail(`${pkg} did not come up with a WebView socket (pid ${appPid || 'none'})`)
  console.log(`[attach-check] ${pkg} pid ${appPid}, socket ${socketName}`)
  device.setDefaultTimeout(60000)
  const webView = await device.webView({ socketName }).catch((e) =>
    fail(`no WebView on ${socketName}: ${e.message} (seen: ${device.webViews().map((w) => `${w.socketName()}=${w.pkg() || '?'}`).join(', ') || 'none'})`)
  )
  const page = await webView.page().catch((e) => fail(`WebView page: ${e.message}`))
  const url = page.url()
  const mounted = await page
    .evaluate(() => !!document.getElementById('__nuxt'))
    .catch((e) => fail(`evaluate in the WebView: ${e.message}`))
  console.log(`[attach-check] attached to ${pkg} pid ${webView.pid()} at ${url} (app root present: ${mounted})`)
  await device.close().catch(() => {})
  process.exit(0)
})().catch((e) => fail(e.stack || e.message))
