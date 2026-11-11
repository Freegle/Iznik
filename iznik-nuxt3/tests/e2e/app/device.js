// adb-driven access to the emulator the `app` Playwright project runs against: shell
// commands, the UI hierarchy (uiautomator dump), taps, the notification shade, fake FCM
// messages, deep links. Playwright drives the WebView over CDP; this covers everything
// outside it. Every wait polls an observable condition with a deadline from config.js.
const { execFile } = require('child_process')
const { timeouts } = require('../config')

const ADB = process.env.APP_E2E_ADB || 'adb'
const SERIAL = process.env.APP_E2E_SERIAL
const PACKAGE = process.env.APP_E2E_PACKAGE || 'org.ilovefreegle.direct'
const ACTIVITY = process.env.APP_E2E_ACTIVITY || '.MainActivity'

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/** Quote a string for the device's /system/bin/sh. */
function shQuote(s) {
  return `'${String(s).replace(/'/g, `'\\''`)}'`
}

function adbOnce(args, { timeoutMs, binary }) {
  return new Promise((resolve, reject) => {
    execFile(
      ADB,
      ['-s', SERIAL, ...args],
      {
        timeout: timeoutMs,
        maxBuffer: 64 * 1024 * 1024,
        encoding: binary ? 'buffer' : 'utf8',
      },
      (err, stdout, stderr) => {
        if (err) {
          reject(
            new Error(
              `adb ${args.join(' ')} failed: ${String(stderr || err.message).trim()}`
            )
          )
        } else {
          resolve(stdout)
        }
      }
    )
  })
}

async function adb(args, { timeoutMs = 60000, binary = false } = {}) {
  if (!SERIAL) {
    throw new Error('APP_E2E_SERIAL is not set: the app project needs a device')
  }
  try {
    return await adbOnce(args, { timeoutMs, binary })
  } catch (e) {
    // A loaded machine can make the adb server drop the emulator for a moment; its
    // port scan finds it again (scripts/app-e2e/lib.sh). Wait for it, then retry once.
    if (
      !/device '[^']*' not found|device offline|no devices\/emulators found/.test(
        e.message
      )
    ) {
      throw e
    }
    await adbOnce(['wait-for-device'], { timeoutMs: 90000, binary: false })
    return adbOnce(args, { timeoutMs, binary })
  }
}

/** Runs one command in the device shell; the command is passed as a single string. */
async function shell(cmd, opts) {
  return String(await adb(['shell', cmd], opts)).replace(/\r\n/g, '\n')
}

/** Polls fn until it returns a truthy value; throws with `what` on timeout. */
async function poll(fn, { timeoutMs, intervalMs = 500, what = 'condition' }) {
  const deadline = Date.now() + timeoutMs
  let lastErr
  for (;;) {
    try {
      const v = await fn()
      if (v) return v
    } catch (e) {
      lastErr = e
    }
    if (Date.now() >= deadline) {
      const tail = lastErr ? ` (last error: ${lastErr.message})` : ''
      throw new Error(
        `timed out after ${timeoutMs}ms waiting for ${what}${tail}`
      )
    }
    await delay(intervalMs)
  }
}

// --- UI hierarchy -------------------------------------------------------------------

/**
 * The current screen as a flat list of nodes from `uiautomator dump`:
 * { text, resourceId, desc, className, clickable, bounds: {x1,y1,x2,y2} }.
 *
 * An "isn't responding" dialog is answered with Wait first, as a member would. On a
 * loaded CI emulator the app or System UI stalls past Android's five seconds often
 * enough, and the dialog then sits over everything until someone answers it: in job
 * 39544 it covered the camera and the photo picker for seven minutes, so every dump
 * was of the dialog. Hiding the dialogs (hide_error_dialogs) is no answer, because
 * Android then kills the stalled app instead.
 */
async function uiNodes() {
  for (let answered = 0; ; answered++) {
    const nodes = await dumpNodes()
    const wait = nodes.find(
      (n) => n.resourceId === 'android:id/aerr_wait' && n.bounds
    )
    if (!wait || answered >= 3) {
      return nodes
    }
    const title = nodes.find((n) => /isn.t responding/i.test(n.text))
    console.log(
      `[device] answered Wait to "${title ? title.text : 'not responding'}"`
    )
    await tapNode(wait)
  }
}

async function dumpNodes() {
  const xml = await poll(
    async () => {
      const out = await shell('uiautomator dump /sdcard/app-e2e-window.xml', {
        timeoutMs: 30000,
      })
      if (!/dumped to/i.test(out)) return null
      const dump = String(
        await adb(['exec-out', 'cat', '/sdcard/app-e2e-window.xml'])
      )
      return dump.includes('<hierarchy') ? dump : null
    },
    {
      timeoutMs: timeouts.ui.appearance,
      intervalMs: 1000,
      what: 'a uiautomator dump',
    }
  )
  const nodes = []
  const attr = (tag, name) => {
    const m = new RegExp(`\\s${name}="([^"]*)"`).exec(tag)
    return m ? m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&') : ''
  }
  for (const tag of xml.match(/<node\b[^>]*>/g) || []) {
    const b = /\[(\d+),(\d+)\]\[(\d+),(\d+)\]/.exec(attr(tag, 'bounds'))
    nodes.push({
      text: attr(tag, 'text'),
      resourceId: attr(tag, 'resource-id'),
      desc: attr(tag, 'content-desc'),
      className: attr(tag, 'class'),
      clickable: attr(tag, 'clickable') === 'true',
      bounds: b ? { x1: +b[1], y1: +b[2], x2: +b[3], y2: +b[4] } : null,
    })
  }
  return nodes
}

function matches(node, sel) {
  if (sel.text !== undefined) {
    if (
      sel.text instanceof RegExp
        ? !sel.text.test(node.text)
        : node.text !== sel.text
    ) {
      return false
    }
  }
  if (sel.textContains !== undefined && !node.text.includes(sel.textContains))
    return false
  if (sel.resourceId !== undefined && node.resourceId !== sel.resourceId)
    return false
  if (sel.desc !== undefined && node.desc !== sel.desc) return false
  return true
}

async function findNode(sel) {
  return (await uiNodes()).find((n) => matches(n, sel) && n.bounds) || null
}

async function waitForNode(sel, { timeoutMs = timeouts.ui.appearance } = {}) {
  return poll(() => findNode(sel), {
    timeoutMs,
    intervalMs: 750,
    what: `a UI node matching ${JSON.stringify(sel)}`,
  })
}

async function tapNode(node) {
  const { x1, y1, x2, y2 } = node.bounds
  await shell(
    `input tap ${Math.round((x1 + x2) / 2)} ${Math.round((y1 + y2) / 2)}`
  )
}

/** Waits for a node matching sel and taps its centre. */
async function tap(sel, opts) {
  const node = await waitForNode(sel, opts)
  await tapNode(node)
  return node
}

// --- The app ------------------------------------------------------------------------

async function pid() {
  const out = (await shell(`pidof ${PACKAGE}`).catch(() => '')).trim()
  return out ? parseInt(out, 10) : null
}

async function foregroundPackage() {
  const out = await shell('dumpsys activity activities')
  const m =
    /(?:topResumedActivity|mResumedActivity)[^\n]*?\s([\w.]+)\/[\w.]+/.exec(out)
  return m ? m[1] : null
}

async function pressHome() {
  await shell('input keyevent KEYCODE_HOME')
  await poll(async () => (await foregroundPackage()) !== PACKAGE, {
    timeoutMs: timeouts.ui.appearance,
    what: 'the app to leave the foreground',
  })
}

async function launch() {
  await shell(`am start -W -n ${PACKAGE}/${ACTIVITY}`)
}

async function waitForForeground() {
  await poll(async () => (await foregroundPackage()) === PACKAGE, {
    timeoutMs: timeouts.ui.appearance,
    what: 'the app to come to the foreground',
  })
}

/** Opens a URL the way a tap in another app would (android.intent.action.VIEW). */
async function openUrl(url) {
  await shell(
    `am start -W -a android.intent.action.VIEW -d ${shQuote(url)} ${PACKAGE}`
  )
}

async function screenshot(path) {
  const png = await adb(['exec-out', 'screencap', '-p'], { binary: true })
  require('fs').writeFileSync(path, png)
}

/** Kills the app's process, as Android does to a backgrounded app it needs memory from. */
async function forceStop() {
  await shell(`am force-stop ${PACKAGE}`)
  await poll(async () => !(await pid()), {
    timeoutMs: timeouts.ui.appearance,
    what: 'the app process to exit',
  })
}

// --- How Android starts and restarts the app ----------------------------------------

/**
 * Starts the app the way a tap on a notification that Firebase put in the shade does:
 * an explicit start of MainActivity, with the message's data as extras alongside its
 * google.message_id. Chat pushes are sent with a notification block (forceVisible in
 * PushNotificationService), so this is the intent a member's tap produces. fromHistory
 * adds FLAG_ACTIVITY_LAUNCHED_FROM_HISTORY, which is how Recents hands that same
 * intent back after Android has killed the app.
 */
async function openFromNotification(data, { fromHistory = false } = {}) {
  const extras = Object.entries({
    'google.message_id': `app-e2e-${Date.now()}`,
    ...data,
  })
    .map(([k, v]) => `--es ${shQuote(k)} ${shQuote(v)}`)
    .join(' ')
  // FLAG_ACTIVITY_NEW_TASK, and FLAG_ACTIVITY_LAUNCHED_FROM_HISTORY from Recents.
  const flags = fromHistory ? '0x10100000' : '0x10000000'
  await shell(`am start -W -n ${PACKAGE}/${ACTIVITY} -f ${flags} ${extras}`)
}

/**
 * Kills the backgrounded app the way Android does when it wants the memory: the task
 * and the activity's saved state survive, so the next start rebuilds the activity
 * rather than starting afresh. Unlike forceStop, which clears all that. Needs adbd
 * running as root, as start-emulator.sh arranges.
 */
async function killInBackground() {
  // SIGKILL, as the low-memory killer sends. `am kill` will not touch an app that
  // has only just left the screen.
  const running = await pid()
  if (running) await shell(`kill -9 ${running}`)
  await poll(async () => !(await pid()), {
    timeoutMs: timeouts.ui.appearance,
    what: 'the backgrounded app process to be killed',
  })
}

/** Taps the app's launcher icon: MAIN/LAUNCHER, with the flags a launcher sends. */
async function launchFromLauncher() {
  // FLAG_ACTIVITY_NEW_TASK | FLAG_ACTIVITY_RESET_TASK_IF_NEEDED
  await shell(
    `am start -W -a android.intent.action.MAIN -c android.intent.category.LAUNCHER -f 0x10200000 -n ${PACKAGE}/${ACTIVITY}`
  )
}

// --- Photos: the camera, the gallery, sharing into the app --------------------------

/**
 * Puts a local image in the device's gallery (MediaStore) and returns its content URI,
 * the kind of URI a gallery app hands over when it shares a photo.
 */
async function addImageToGallery(localPath) {
  const ext = require('path').extname(localPath) || '.jpg'
  const name = `app-e2e-${Date.now()}${ext}`
  await adb(['push', localPath, `/sdcard/Pictures/${name}`])
  // Newest in the gallery, so the photo picker shows it first (push keeps the
  // asset's own date).
  await shell(`touch /sdcard/Pictures/${name}`)
  // A root `adb push` writes beneath MediaStore's notice; the scanner indexes it.
  await shell(
    `am broadcast -a android.intent.action.MEDIA_SCANNER_SCAN_FILE -d file:///sdcard/Pictures/${name}`
  )
  const id = await poll(
    async () => {
      const out = await shell(
        `content query --uri content://media/external/images/media --projection _id --where "_display_name='${name}'"`
      )
      const m = /_id=(\d+)/.exec(out)
      return m ? m[1] : null
    },
    { timeoutMs: timeouts.ui.appearance, what: `MediaStore to index ${name}` }
  )
  return `content://media/external/images/media/${id}`
}

/** True when Android offers the app as a target for sharing this MIME type. */
async function isShareTarget(mime) {
  const out = await shell(
    `pm query-activities -a android.intent.action.SEND -t ${mime}`
  )
  return out.includes(`packageName=${PACKAGE}`)
}

/**
 * Shares an image into the app the way a gallery app does after the member picks
 * Freegle in the share sheet: ACTION_SEND with the image as EXTRA_STREAM and a read
 * grant on it. Sent as the shell user, which can read MediaStore and so can grant it;
 * a root sender's grant does not take. The URI also goes in the intent data, because
 * `am` does not move EXTRA_STREAM into the clip data the grant applies to, as the
 * framework does for an app's own startActivity().
 */
async function shareImage(uri, mime) {
  const out = await shell(
    `su shell am start -W -a android.intent.action.SEND -t ${mime} -d ${shQuote(uri)} --eu android.intent.extra.STREAM ${shQuote(uri)} --grant-read-uri-permission -n ${PACKAGE}/${ACTIVITY}`
  )
  if (!/Status: ok|Complete/.test(out)) {
    throw new Error(`share intent did not start the app: ${out.trim()}`)
  }
}

/**
 * Takes a picture in the system camera app (AOSP Camera, com.android.camera2, which
 * the google_apis images ship) once the app has handed over to it, and accepts it.
 * The AVD's back camera is emulated, so there is always a picture to take. Any
 * first-run prompt the camera puts up is dismissed on the way.
 */
async function takePictureWithSystemCamera() {
  const CAMERA = 'com.android.camera2'
  const FIRST_RUN =
    /^(NEXT|OK|GOT IT|NO THANKS|ALLOW|WHILE USING THE APP|ONLY THIS TIME)$/i
  // The shutter is on screen before the camera has opened, and the camera drops a tap
  // that comes before it is ready to capture (on a CI emulator it was, and the camera
  // sat on its preview until the test gave up). So the shutter is tapped again each
  // round until the review screen's done button replaces it.
  const done = await poll(
    async () => {
      const nodes = await uiNodes()
      const doneButton = nodes.find(
        (n) => n.resourceId === `${CAMERA}:id/done_button` && n.bounds
      )
      if (doneButton) {
        return doneButton
      }
      const shutter = nodes.find(
        (n) => n.resourceId === `${CAMERA}:id/shutter_button` && n.bounds
      )
      const prompt = nodes.find((n) => FIRST_RUN.test(n.text) && n.bounds)
      if (shutter || prompt) {
        await tapNode(shutter || prompt)
      }
      return null
    },
    {
      timeoutMs: timeouts.ui.appearance,
      intervalMs: 2500,
      what: 'the camera to take a picture (its done button)',
    }
  )
  await tapNode(done)
}

/**
 * In the system photo picker (Android 13+, the MediaProvider module the camera
 * plugin's pickImages() opens), selects the newest photo and confirms with "Add".
 */
async function pickNewestFromPhotoPicker() {
  const thumb = await poll(
    async () =>
      (await uiNodes()).find(
        (n) => /^Photo taken on/.test(n.desc) && n.clickable && n.bounds
      ) || null,
    { timeoutMs: timeouts.ui.appearance, what: 'a photo in the picker' }
  )
  await tapNode(thumb)
  const add = await poll(
    async () =>
      (await uiNodes()).find(
        (n) => n.resourceId.endsWith(':id/button_add') && n.bounds
      ) || null,
    { timeoutMs: timeouts.ui.appearance, what: "the picker's Add button" }
  )
  await tapNode(add)
}

// --- Push notifications -------------------------------------------------------------

/**
 * Delivers an FCM data message to the app the way Google Play services would: the
 * broadcast FCM itself sends to the app's FirebaseInstanceIdReceiver, which runs
 * MessagingService.onMessageReceived with these key/value pairs as the data payload.
 * Needs adbd running as root (the receiver is guarded by a Google-only permission, which
 * root bypasses); start-emulator.sh arranges that.
 */
async function sendFcmData(data) {
  // FCM stamps every message with a sender and an id; RemoteMessage.getData()
  // leaves both out of the data payload, getMessageId() reads the id.
  const extras = Object.entries({
    from: '000000000000',
    'google.message_id': `app-e2e-${Date.now()}`,
    ...data,
  })
    .map(([k, v]) => `--es ${shQuote(k)} ${shQuote(v)}`)
    .join(' ')
  const out = await shell(
    `am broadcast -a com.google.android.c2dm.intent.RECEIVE -n ${PACKAGE}/com.google.firebase.iid.FirebaseInstanceIdReceiver ${extras}`
  )
  if (!/Broadcast completed/.test(out)) {
    throw new Error(`FCM broadcast did not complete: ${out.trim()}`)
  }
}

/** The notifications the app currently has posted: [{ title, text, channel }]. */
async function appNotifications() {
  const out = await shell('dumpsys notification --noredact')
  const result = []
  // One record per "NotificationRecord(...: pkg=<pkg> ...)" block.
  const blocks = out.split(/NotificationRecord\(/).slice(1)
  for (const block of blocks) {
    if (!block.includes(`pkg=${PACKAGE} `)) continue
    const title = /android\.title=String \((.*)\)\s*\n/.exec(block)
    const text = /android\.text=String \((.*)\)\s*\n/.exec(block)
    // The record header: "Notification(channel=chat_messages shortcut=null ...)".
    const channel = /Notification\(channel=([^\s)]+)/.exec(block)
    result.push({
      title: title ? title[1] : '',
      text: text ? text[1] : '',
      channel: channel ? channel[1] : '',
    })
  }
  return result
}

async function waitForNotification(
  pred,
  { timeoutMs = timeouts.ui.appearance } = {}
) {
  return poll(async () => (await appNotifications()).find(pred) || null, {
    timeoutMs,
    intervalMs: 750,
    what: 'a matching app notification',
  })
}

async function clearNotifications() {
  await shell('cmd notification cancel_all 2>/dev/null || true').catch(() => {})
  await shell('service call notification 1 2>/dev/null || true').catch(() => {})
}

/**
 * Opens the shade and taps the notification carrying this title. The request to open
 * the shade can be lost while the launcher is still animating in (on a CI emulator it
 * was, just after the app was sent home), so it is repeated each round until the
 * notification is on screen.
 */
async function tapNotification(title) {
  const node = await poll(
    async () => {
      const found = await findNode({ text: title })
      if (!found) {
        await shell('cmd statusbar expand-notifications')
      }
      return found
    },
    {
      timeoutMs: timeouts.ui.appearance,
      intervalMs: 1500,
      what: `the notification "${title}" in the shade`,
    }
  )
  await tapNode(node)
}

async function collapseShade() {
  await shell('cmd statusbar collapse')
}

module.exports = {
  PACKAGE,
  SERIAL,
  adb,
  shell,
  poll,
  uiNodes,
  findNode,
  waitForNode,
  tap,
  pid,
  foregroundPackage,
  pressHome,
  launch,
  waitForForeground,
  openUrl,
  screenshot,
  forceStop,
  openFromNotification,
  killInBackground,
  launchFromLauncher,
  addImageToGallery,
  isShareTarget,
  shareImage,
  takePictureWithSystemCamera,
  pickNewestFromPhotoPicker,
  sendFcmData,
  appNotifications,
  waitForNotification,
  clearNotifications,
  tapNotification,
  collapseShade,
}
