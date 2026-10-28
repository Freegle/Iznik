/**
 * Push notifications, end to end on the device, without Firebase's servers: the same
 * data message iznik-batch sends (PushNotificationService) is delivered to the app the
 * way Google Play services delivers it, through the FCM broadcast receiver. From there
 * everything is the app's own code: MessagingService, NotificationHelper, the plugin's
 * events into the WebView, and stores/mobile.js routing a tap on the notification.
 */

const { test, expect } = require('../fixtures')
const { timeouts } = require('../config')
const { loginViaHomepage } = require('../utils/user')
const device = require('./device')

// What PushNotificationService::buildChatPayload sends for one unread chat message.
function chatPayload(title, overrides = {}) {
  return {
    badge: '1',
    count: '1',
    chatcount: '1',
    notifcount: '0',
    title,
    message: '',
    chatids: '',
    'content-available': '1',
    image: 'www/images/user_logo.png',
    modtools: '0',
    sound: 'default',
    route: '/chats',
    category: 'CHAT_MESSAGE',
    channel_id: 'chat_messages',
    threadId: 'chats',
    notId: '1',
    ...overrides,
  }
}

async function listenForPushes(page) {
  await page.evaluate(() => {
    window.__e2ePushes = []
    window.Capacitor.Plugins.PushNotifications.addListener(
      'pushNotificationReceived',
      (n) => window.__e2ePushes.push(n)
    )
  })
}

test.describe('Push notifications', () => {
  test.beforeEach(async () => {
    await device.clearNotifications()
  })

  test('a data message in the foreground reaches the WebView and the shade', async ({
    page,
  }) => {
    // A device only gets pushes for a signed-in member, and a chat push makes the
    // app fetch that member's chats.
    await page.gotoAndVerify('/', { maxRetries: 1 })
    await loginViaHomepage(page, 'test@test.com', 'freegle')
    await listenForPushes(page)
    const title = `E2E foreground ${Date.now()}`

    await device.sendFcmData(chatPayload(title))

    // The plugin raises pushNotificationReceived with the payload.
    await expect
      .poll(() => page.evaluate(() => window.__e2ePushes.length), {
        timeout: timeouts.ui.appearance,
      })
      .toBeGreaterThan(0)
    const received = await page.evaluate(() => window.__e2ePushes[0])
    expect(received.data.title).toBe(title)
    expect(received.data.foreground).toBe(true)

    // NotificationHelper posts it on the chat_messages channel whatever the app state.
    const posted = await device.waitForNotification((n) => n.title === title)
    expect(posted.channel).toBe('chat_messages')
  })

  test('a push to the backgrounded app routes it to chats when reopened', async ({
    page,
  }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    await loginViaHomepage(page, 'test@test.com', 'freegle')
    const title = `E2E tap ${Date.now()}`

    // Signing in on the home page sends a member on to /browse (pages/index.vue
    // goHome). Let that land first: a backgrounded WebView throttles its timers, so a
    // redirect still pending when the app is hidden can arrive after the push's route
    // and replace it. A member who has been signed in a while has none pending.
    await expect
      .poll(() => new URL(page.url()).pathname, {
        timeout: timeouts.ui.appearance,
      })
      .not.toBe('/')

    // Background the app and wait until it knows it is: stores/mobile.js decides
    // where a push goes from App.getState(), and the state flips a moment after
    // the launcher takes the screen.
    await device.pressHome()
    await expect
      .poll(
        () =>
          page.evaluate(() =>
            window.Capacitor.Plugins.App.getState().then((s) => s.isActive)
          ),
        { timeout: timeouts.ui.appearance }
      )
      .toBe(false)

    await device.sendFcmData(chatPayload(title))
    await device.waitForNotification((n) => n.title === title)

    await device.tapNotification(title)
    await device.waitForForeground()

    // handleNotification: a chat push received while the app is in the background
    // sets its route to /chats, so the app is on chats when the tap brings it back.
    await expect
      .poll(() => page.url(), { timeout: timeouts.ui.appearance })
      .toMatch(/\/chats(\/|\?|$)/)
  })

  test('a message without a channel is ignored as legacy', async () => {
    const title = `E2E legacy ${Date.now()}`
    await device.sendFcmData({ title, message: 'no channel_id' })
    // No system notification is posted for it.
    await expect
      .poll(
        async () =>
          (await device.appNotifications()).some((n) => n.title === title),
        {
          timeout: timeouts.assertion.normal,
        }
      )
      .toBe(false)
  })
})

// What the app's debug store recorded since it started: stores/mobile.js logs
// "PUSH ACTION" as soon as a notification tap reaches it, and
// "initPushNotifications completed" once push setup is done. A tap carried by the
// start intent is delivered while the listeners are added, so by the second entry
// the first is either there or never coming.
async function pushStartupLog(page) {
  await expect
    .poll(
      () =>
        page
          .evaluate(() =>
            (
              document.getElementById('__nuxt')?.__vue_app__?.config
                .globalProperties.$pinia?.state.value.debug?.logs || []
            ).map((l) => l.message)
          )
          .catch(() => []),
      { timeout: timeouts.navigation.initial }
    )
    .toContainEqual(expect.stringContaining('initPushNotifications completed'))
  return page.evaluate(() =>
    document
      .getElementById('__nuxt')
      .__vue_app__.config.globalProperties.$pinia.state.value.debug.logs.map(
        (l) => l.message
      )
  )
}

test.describe('Starting from a notification', () => {
  // Android keeps the intent a task first started with. When it rebuilds the app
  // after killing it, that intent comes back; if it was a notification tap, the app
  // must not take it as a new one. A member's app opened on the same chat for days.
  async function startFromChatNotification(appWebView) {
    await device.forceStop()
    // A fixed google.message_id lets a test hand the same tap back from Recents.
    const payload = {
      ...chatPayload(`E2E start ${Date.now()}`),
      'google.message_id': `app-e2e-start-${Date.now()}`,
    }
    await device.openFromNotification(payload)
    const page = await appWebView.reattach()

    // The tap itself still works: it reaches the app and opens chats.
    expect(await pushStartupLog(page)).toContainEqual(
      expect.stringContaining('PUSH ACTION')
    )
    await expect
      .poll(() => new URL(page.url()).pathname, {
        timeout: timeouts.ui.appearance,
      })
      .toMatch(/^\/chats/)
    return payload
  }

  test('reopening from the launcher after Android kills the app does not replay the tap', async ({
    appWebView,
  }) => {
    await startFromChatNotification(appWebView)

    await device.pressHome()
    await device.killInBackground()
    await device.launchFromLauncher()
    const page = await appWebView.reattach()

    expect(await pushStartupLog(page)).not.toContainEqual(
      expect.stringContaining('PUSH ACTION')
    )
    expect(new URL(page.url()).pathname).not.toMatch(/^\/chats/)
  })

  test('reopening from Recents after Android kills the app does not replay the tap', async ({
    appWebView,
  }) => {
    const payload = await startFromChatNotification(appWebView)

    // Recents hands back the intent the task started with: the same message id.
    await device.forceStop()
    await device.openFromNotification(payload, {
      fromHistory: true,
    })
    const page = await appWebView.reattach()

    expect(await pushStartupLog(page)).not.toContainEqual(
      expect.stringContaining('PUSH ACTION')
    )
    expect(new URL(page.url()).pathname).not.toMatch(/^\/chats/)
  })

  test('a new tap after Android kills the app still opens chats', async ({
    appWebView,
  }) => {
    await startFromChatNotification(appWebView)

    await device.pressHome()
    await device.killInBackground()
    await device.openFromNotification(chatPayload(`E2E second ${Date.now()}`))
    const page = await appWebView.reattach()

    expect(await pushStartupLog(page)).toContainEqual(
      expect.stringContaining('PUSH ACTION')
    )
    await expect
      .poll(() => new URL(page.url()).pathname, {
        timeout: timeouts.ui.appearance,
      })
      .toMatch(/^\/chats/)
  })
})
