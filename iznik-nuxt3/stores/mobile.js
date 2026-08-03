// mobile.js:
// - This code is run once at app startup - and does nothing on the web
// - Then handles push notifications and deeplinks
//
// Initialise app:
// - Get device info and id
// - Set iOS window.open
// - Enable pinch zoom on Android
// - Enable deeplinks
// - Set up push notifications
//
// Ongoing:
// - Handle push notifications

import { defineStore } from 'pinia'
import { Capacitor } from '@capacitor/core'
import { useAuthStore } from '~/stores/auth'
import { useChatStore } from '~/stores/chat'
import { useNotificationStore } from '~/stores/notification'
import { useDebugStore } from '~/stores/debug'
import { setAppVersion } from '~/composables/useClientLog'
import api from '~/api'

// Helper to get debug store safely (may not be initialized early)
function dbg() {
  try {
    return useDebugStore()
  } catch (e) {
    return null
  }
}

export const useMobileStore = defineStore({
  id: 'mobile',
  state: () => ({
    config: null,
    isApp: false,
    mobileVersion: false,
    // Native app version + build number from Capacitor App.getInfo() — the REAL
    // installed-app version (unlike mobileVersion, which is the web build
    // constant). Null on the website. Sent to the server on /session and logged
    // so support can see which app version a member is actually running.
    appVersion: null,
    appBuild: null,
    deviceinfo: null,
    deviceuserinfo: '',
    isiOS: false,
    osVersion: false,
    devicePersistentId: null,
    lastBadgeCount: -1,
    modtools: false,
    inlineReply: false,
    chatid: false,
    pushed: false,
    pushPlugin: null,
    route: false,
    apprequiredversion: false,
    appupdaterequired: false,
    // URLs (Capacitor.convertFileSrc) of images shared into the app from another
    // app, waiting to be attached to a new OFFER by the give-flow photos page.
    pendingSharedImages: [],
  }),
  actions: {
    init(config) {
      this.config = config

      // Detect if running in a native Capacitor app
      // This works for both app and web builds
      const platform = Capacitor.getPlatform()
      this.isApp = platform === 'ios' || platform === 'android'
      this.isiOS = platform === 'ios'

      if (this.isApp) {
        console.log(
          'Mobile store initialized - running in Capacitor app:',
          platform
        )
        this.mobileVersion = config.public.MOBILE_VERSION
        this.initApp()
      } else {
        console.log('Mobile store initialized - running in web browser')
      }
    },

    async initApp() {
      // Only run app-specific initialization
      // Import app-specific modules dynamically to avoid issues in web build
      const { Device } = await import('@capacitor/device')
      const { Badge } = await import('@capawesome/capacitor-badge')
      const { PushNotifications } = await import(
        '@freegle/capacitor-push-notifications-cap7'
      )
      const { AppLauncher } = await import('@capacitor/app-launcher')
      const { App } = await import('@capacitor/app')

      // Log app and plugin versions for debugging
      const runtimeConfig = useRuntimeConfig()
      const appInfo = await App.getInfo()
      // Keep the native version/build so the session call and client logs can
      // report the REAL installed-app version (not the mobileVersion constant).
      this.appVersion = appInfo.version || null
      this.appBuild = appInfo.build || null
      // Make it available to client logs (session_start) so support sees the
      // real app version a member is running.
      setAppVersion(this.appVersion)
      dbg()?.info('=== APP STARTUP ===')
      dbg()?.info('App version', runtimeConfig.public.MOBILE_VERSION)
      dbg()?.info('Native app version', appInfo.version)
      dbg()?.info('App build', appInfo.build)
      dbg()?.info('App bundle', appInfo.id)
      dbg()?.info('Platform', Capacitor.getPlatform())
      dbg()?.info('Capacitor native', Capacitor.isNativePlatform())

      // On Android, check for background push log (from when app wasn't running)
      if (Capacitor.getPlatform() === 'android') {
        try {
          const result = await PushNotifications.getBackgroundPushLog()
          if (result?.log && result.log.trim()) {
            dbg()?.info('=== BACKGROUND PUSH LOG ===')
            dbg()?.info(result.log)
            dbg()?.info('=== END BACKGROUND PUSH LOG ===')
            // Clear the log after reading
            await PushNotifications.clearBackgroundPushLog()
          } else {
            dbg()?.debug('No background push log entries')
          }
        } catch (e) {
          // getBackgroundPushLog is not implemented on all Android plugin versions — not actionable
          dbg()?.debug('Background push log unavailable', e.message)
        }
      }

      await this.getDeviceInfo(Device)
      this.fixWindowOpen(AppLauncher)
      this.initDeepLinks(App)
      this.initShareIntent(App)
      this.initTextZoom(App)
      await this.initPushNotifications(PushNotifications, Badge)
      await this.checkForAppUpdate()
      this.initWakeUpActions(App)
    },

    async initTextZoom(App) {
      // Respect the OS accessibility text-size setting. WKWebView ignores iOS
      // Dynamic Type for web content entirely, so without this the app renders
      // at a fixed text size no matter what the member set in Settings ->
      // Accessibility. getPreferred() returns the zoom the system wants
      // (Dynamic Type on iOS, font scale on Android); applying it makes text
      // grow WITH REFLOW, unlike pinch zoom which scales the whole viewport
      // including the navbars.
      try {
        const { TextZoom } = await import('@capacitor/text-zoom')

        const apply = async () => {
          try {
            const { value } = await TextZoom.getPreferred()
            if (value && value > 0) {
              await TextZoom.set({ value })
              dbg()?.info('Applied preferred text zoom', { value })
            }
          } catch (e) {
            dbg()?.debug('Text zoom apply failed', e?.message)
          }
        }

        await apply()

        // The member can change the OS setting while we're backgrounded, and
        // getPreferred() only reflects it on next read - re-apply on resume.
        App.addListener('resume', apply)
      } catch (e) {
        // Plugin unavailable (old installed build without it) - nothing to do.
        dbg()?.debug('Text zoom unavailable', e?.message)
      }
    },

    async getDeviceInfo(Device) {
      const deviceinfo = await Device.getInfo()
      this.deviceinfo = deviceinfo

      // Build device info string - avoid duplicates (platform/operatingSystem are often same)
      const parts = []
      if (deviceinfo.manufacturer) parts.push(deviceinfo.manufacturer)
      if (deviceinfo.model) parts.push(deviceinfo.model)
      if (deviceinfo.platform) parts.push(deviceinfo.platform)
      if (deviceinfo.osVersion) parts.push(deviceinfo.osVersion)
      // Only add webViewVersion if different from osVersion
      if (
        deviceinfo.webViewVersion &&
        deviceinfo.webViewVersion !== deviceinfo.osVersion
      ) {
        parts.push('WebView ' + deviceinfo.webViewVersion)
      }
      this.deviceuserinfo = parts.join(' ')

      console.log('deviceuserinfo', this.deviceuserinfo)
      this.isiOS = deviceinfo.platform === 'ios'
      this.osVersion = deviceinfo.osVersion
      const deviceid = await Device.getId()
      this.devicePersistentId = deviceid.identifier
    },

    fixWindowOpen(AppLauncher) {
      // External links should be opened with ExternalLink but catch calls to window.open here
      // Internal links are handled internally and external links use AppLauncher
      window.open = (url, target) => {
        dbg()?.info('window.open called', { url, target })
        console.log('App window.open', url, target)
        if (url.substring(0, 4) !== 'http') {
          dbg()?.info('window.open routing internally', { url })
          const router = useRouter()
          router.push(url)
        } else {
          dbg()?.info('window.open calling AppLauncher.openUrl', { url })
          AppLauncher.openUrl({ url })
            .then((result) => {
              dbg()?.info('AppLauncher.openUrl success', { url, result })
            })
            .catch((error) => {
              dbg()?.error('AppLauncher.openUrl failed', {
                url,
                error: error?.message || error,
              })
            })
        }
      }
      dbg()?.info('window.open override installed')
    },

    extractQueryStringParams(url) {
      let urlParams = false
      const qm = url.indexOf('?')
      if (qm >= 0) {
        const qs = url.substring(qm + 1)
        const pl = /\+/g
        const search = /([^&=]+)=?([^&]*)/g
        const decode = (s) => {
          return decodeURIComponent(s.replace(pl, ' '))
        }
        urlParams = {}
        let match
        while ((match = search.exec(qs))) {
          urlParams[decode(match[1]).replace(/\./g, '_')] = decode(match[2])
        }
      }
      return urlParams
    },

    initWakeUpActions(App) {
      if (process.client) {
        App.addListener('resume', (event) => {
          try {
            const notificationStore = useNotificationStore()
            notificationStore.fetchCount()

            const chatStore = useChatStore()
            chatStore.fetchChats(null, false)

            // Re-trigger push registration on resume so that a missed/failed
            // initial registration or a rotated FCM/APNs token recovers. The
            // existing 'registration' listener re-fires with the current token
            // and savePushId() is a no-op unless something actually changed.
            this.reRegisterPush()
          } catch (e) {}
        })
      }
    },

    // Re-register for push on the native app. Safe to call repeatedly: it only
    // calls register() (which re-fires the existing 'registration' listener);
    // it does NOT re-add listeners, re-create channels or re-request
    // permissions. No-op on web or before push has been initialised.
    async reRegisterPush() {
      if (!process.client || !this.isApp || !this.pushPlugin) {
        return
      }

      try {
        const permStatus = await this.pushPlugin.checkPermissions()
        if (permStatus?.receive === 'granted') {
          dbg()?.info('Re-registering push on resume')
          await this.pushPlugin.register()
        } else {
          dbg()?.info('Skipping push re-register; permission not granted', {
            receive: permStatus?.receive,
          })
        }
      } catch (e) {
        dbg()?.warn('Push re-register on resume failed', e?.message)
      }
    },

    initDeepLinks(App) {
      if (process.client) {
        App.addListener('appUrlOpen', async (event) => {
          console.log('appUrlOpen', event.url)
          // "Share an image into Freegle" on iOS: the Share Extension opens
          // freegleshare://shared?p=<path>... — queue the image(s) and route
          // into the give flow (mirrors the Android FreegleShare bridge).
          if (event.url && event.url.indexOf('freegleshare://') === 0) {
            this.handleSharedUrl(event.url)
            return
          }
          const lookfor = 'ilovefreegle.org'
          const ilfpos = event.url.indexOf(lookfor)
          if (ilfpos !== -1) {
            const route = event.url
              .substring(ilfpos + lookfor.length)
              .replace('/chat/', '/chats/')
            console.log('appUrlOpen route', route)
            const router = useRouter()
            if (route.includes('src=forgotpass')) {
              const authStore = useAuthStore()
              await authStore.clearRelated()
              await authStore.logout()
              const params = this.extractQueryStringParams(route)
              await authStore.login({
                u: params.u,
                k: params.k,
              })
            }
            if (route.includes('one-click-unsubscribe')) {
              const ustart = route.indexOf('/', 1)
              if (ustart !== -1) {
                const kstart = route.indexOf('/', ustart + 1)
                if (kstart !== -1) {
                  const uid = parseInt(route.substring(ustart + 1, kstart))
                  const authStore = useAuthStore()
                  const loggedInAs = authStore.user?.id
                  if (loggedInAs === uid) {
                    const ret = await authStore.forget()
                    if (!ret) {
                      authStore.forceLogin = false
                      router.push('/unsubscribe/unsubscribed')
                      return
                    }
                  }
                }
              }
              router.push('/unsubscribe')
              return
            }
            setTimeout(() => {
              console.log('appUrlOpen route push', route)
              router.push(route)
            }, 500)
          }
        })
      }
    },

    // "Share an image into Freegle" (Android ACTION_SEND). The native layer
    // (MainActivity) copies the shared image(s) to its cache and exposes them via
    // the window.FreegleShare JS bridge. We pull them at startup (cold share) and
    // on every resume (warm share), then route into the give flow with the photos
    // pre-attached. No-op unless the native bridge is present.
    initShareIntent(App) {
      if (process.client) {
        this.checkSharedIntent()
        App.addListener('resume', () => {
          this.checkSharedIntent()
        })
      }
    },

    checkSharedIntent() {
      if (!process.client || !this.isApp) return
      try {
        const bridge = window.FreegleShare
        if (!bridge || typeof bridge.consume !== 'function') return
        const raw = bridge.consume()
        if (!raw) return
        let paths
        try {
          paths = JSON.parse(raw)
        } catch (e) {
          return
        }
        if (!Array.isArray(paths) || paths.length === 0) return
        // Convert native cache-file paths into URLs the WebView can fetch().
        this.pendingSharedImages = paths.map((p) => Capacitor.convertFileSrc(p))
        console.log('Shared images received', this.pendingSharedImages.length)
        const router = useRouter()
        router.push('/give/mobile/photos')
      } catch (e) {
        console.log('checkSharedIntent failed', e?.message)
      }
    },

    // iOS share-extension handoff: parse freegleshare://shared?p=<path>&p=<path>,
    // convert each shared-container path to a URL the WebView can fetch(), queue
    // it, and route into the give flow. Same destination as checkSharedIntent.
    handleSharedUrl(url) {
      try {
        const qpos = url.indexOf('?')
        if (qpos === -1) return
        const paths = url
          .substring(qpos + 1)
          .split('&')
          .filter((kv) => kv.startsWith('p='))
          .map((kv) => decodeURIComponent(kv.substring(2)))
          .filter(Boolean)
        if (!paths.length) return
        this.pendingSharedImages = paths.map((p) => Capacitor.convertFileSrc(p))
        console.log(
          'Shared images received (iOS)',
          this.pendingSharedImages.length
        )
        const router = useRouter()
        router.push('/give/mobile/photos')
      } catch (e) {
        console.log('handleSharedUrl failed', e?.message)
      }
    },

    async initPushNotifications(PushNotifications, Badge) {
      dbg()?.info('initPushNotifications started', { isiOS: this.isiOS })

      // Keep a reference to the plugin so we can re-register on app resume
      // (see reRegisterPush()) without re-adding listeners or re-requesting
      // permissions.
      this.pushPlugin = PushNotifications

      if (!this.isiOS) {
        // Delete old channels
        await PushNotifications.deleteChannel({ id: 'PushDefaultForeground' })
        await PushNotifications.deleteChannel({ id: 'NewPosts' })
        await PushNotifications.deleteChannel({ id: 'modtools' }) // recreate below with correct settings

        // Create notification channels matching server-side categories
        // Channel IDs must match what the server sends in android.notification.channel_id

        // Chat messages - HIGH importance for heads-up notifications
        await PushNotifications.createChannel({
          id: 'chat_messages',
          name: 'Chat Messages',
          description: 'Direct messages with other Freeglers',
          importance: 4, // HIGH - shows as heads-up notification
          visibility: 1,
          lights: true,
          lightColor: '#5ECA24',
          vibration: true,
        })

        // Social - DEFAULT importance for ChitChat comments, replies, loves
        await PushNotifications.createChannel({
          id: 'social',
          name: 'ChitChat & Social',
          description: 'Comments, replies, and likes on your posts',
          importance: 3, // DEFAULT - sound and appears in tray
          visibility: 1,
          lights: true,
          lightColor: '#5ECA24',
          vibration: false,
        })

        // Reminders - DEFAULT importance for post expiry, collection reminders
        await PushNotifications.createChannel({
          id: 'reminders',
          name: 'Reminders',
          description: 'Post expiry warnings and collection reminders',
          importance: 3, // DEFAULT - sound and appears in tray
          visibility: 1,
          lights: true,
          lightColor: '#5ECA24',
          vibration: false,
        })

        // Tips - LOW importance for encouragement/engagement prompts
        await PushNotifications.createChannel({
          id: 'tips',
          name: 'Tips & Suggestions',
          description: 'Helpful tips and encouragement',
          importance: 2, // LOW - no sound, appears in tray
          visibility: 1,
          lights: false,
          lightColor: '#5ECA24',
          vibration: false,
        })

        // New posts - LOW importance for digest/relevant/nearby posts
        await PushNotifications.createChannel({
          id: 'new_posts',
          name: 'New Posts',
          description: 'New offers and wanted posts nearby',
          importance: 2, // LOW - no sound, appears in tray
          visibility: 1,
          lights: false,
          lightColor: '#5ECA24',
          vibration: false,
        })

        // ModTools - HIGH importance for mod work items and chat
        await PushNotifications.createChannel({
          id: 'modtools',
          name: 'ModTools Alerts',
          description: 'Pending messages, mod chat, and moderation work items',
          importance: 4, // HIGH - heads-up notification
          visibility: 1,
          lights: true,
          lightColor: '#0077CC',
          vibration: true,
        })

        dbg()?.info('Android notification channels created')
      } else {
        // iOS: Register notification action categories
        // This enables Reply, Mark Read, and View action buttons on chat notifications
        try {
          const result = await PushNotifications.registerActionCategories()
          console.log('iOS notification categories registered:', result)
          dbg()?.info('iOS notification categories registered', result)
        } catch (e) {
          console.log('iOS registerActionCategories not available:', e.message)
          dbg()?.warn('iOS registerActionCategories not available', e.message)
        }
      }

      let permStatus = await PushNotifications.checkPermissions()
      dbg()?.info('Push permission status', permStatus)

      await PushNotifications.addListener('registration', (token) => {
        console.log('Push registration success, token: ', token.value)
        dbg()?.info('Push registration success', {
          tokenLength: token.value?.length,
          tokenStart: token.value?.substring(0, 20) + '...',
        })
        this.mobilePushId = token.value
        const authStore = useAuthStore()
        authStore.savePushId()

        if (!this.isiOS) {
          PushNotifications.listChannels().then((result) => {
            for (const channel of result.channels) {
              dbg()?.debug('Channel registered', channel)
            }
          })
        }
      })
      console.log('addListener registration done')

      await PushNotifications.addListener('registrationError', (error) => {
        console.log('Error on registration: ', error)
        dbg()?.error('Push registration ERROR', error)
      })
      console.log('addListener registrationError done')

      await PushNotifications.addListener(
        'pushNotificationReceived',
        (notification) => {
          console.log('============ Push received:', notification)
          dbg()?.info('PUSH RECEIVED', notification)
          this.handleNotification(notification, PushNotifications, Badge)
        }
      )
      console.log('addListener pushNotificationReceived done')

      await PushNotifications.addListener(
        'pushNotificationActionPerformed',
        async (n) => {
          console.log('Push action performed:', n)
          dbg()?.info('PUSH ACTION', {
            actionId: n.actionId,
            inputValue: n.inputValue,
            notification: n.notification,
          })
          const actionId = n.actionId
          const inputValue = n.inputValue

          // Handle specific actions
          if (actionId === 'reply' && inputValue && inputValue.trim()) {
            dbg()?.info('Handling reply action')
            await this.handleReplyAction(n.notification, inputValue.trim())
            return
          } else if (actionId === 'mark_read') {
            dbg()?.info('Handling mark_read action')
            await this.handleMarkReadAction(n.notification)
            return
          }

          // Default behavior - navigate to the notification target
          if (n.notification) n.notification.okToMove = true
          this.handleNotification(n.notification, PushNotifications, Badge)
        }
      )
      console.log('addListener pushNotificationActionPerformed done')

      permStatus = await PushNotifications.requestPermissions()
      dbg()?.info('Push requestPermissions result', permStatus)
      if (permStatus.receive === 'granted') {
        await PushNotifications.register()
        dbg()?.info('Push register() called successfully')
      } else {
        console.log('Error on request: ', permStatus)
        dbg()?.error('Push permission NOT granted', permStatus)
      }

      this.setBadgeCount(0, Badge)
      dbg()?.info('initPushNotifications completed')
    },

    async setBadgeCount(badgeCount, Badge) {
      if (!this.isApp) return
      if (isNaN(badgeCount)) badgeCount = 0
      if (badgeCount !== this.lastBadgeCount) {
        console.log('setBadgeCount', badgeCount)
        if (!Badge) {
          Badge = (await import('@capawesome/capacitor-badge')).Badge
        }
        await Badge.set({ count: badgeCount })
        this.lastBadgeCount = badgeCount
      }
    },

    async handleNotification(notification, PushNotifications, Badge) {
      const router = useRouter()
      console.log('handleNotification A', notification)
      dbg()?.info('handleNotification called', notification)
      if (!notification) {
        console.error('--- notification NOT SET')
        dbg()?.error('handleNotification: notification NOT SET')
        return
      }
      try {
        const data = notification.data
        if (!data) {
          console.error('--- notification.data NOT SET')
          dbg()?.error('handleNotification: notification.data NOT SET')
          return
        }

        // Only process new-style notifications (with channel_id) for routing/refresh.
        // Without channel_id (e.g. modtools pushes that don't set $category on the
        // server), still update the badge from aps.badge so the iOS icon stays in sync.
        if (!data.channel_id) {
          if (data.badge !== undefined) {
            this.setBadgeCount(parseInt(data.badge), Badge)
          }
          console.log('--- Ignoring legacy notification without channel_id')
          dbg()?.warn('Ignoring legacy notification without channel_id', data)
          return
        }

        let foreground = false
        if ('foreground' in data) {
          console.log('--- FOREGROUND', data.foreground)
          foreground = data.foreground
        } else console.log('--- FOREGROUND NOT SET')

        if (!('count' in data)) {
          data.count = 0
        }
        if (!('modtools' in data)) {
          data.modtools = 0
        }
        const modtools = data.modtools === '1'
        this.modtools = modtools
        data.count = parseInt(data.badge)

        if (data.count === 0) {
          // Zero-count push is the silent badge-clear from iznik-batch. Clear
          // any leftover tray entries for this app so a stale "N pending"
          // notification doesn't linger after the work has been handled.
          try {
            await PushNotifications.removeAllDeliveredNotifications()
          } catch (e) {
            console.log('removeAllDeliveredNotifications failed', e?.message)
          }
        }
        console.log('handleNotification badgeCount', data.count)
        this.setBadgeCount(data.count, Badge)

        // Suppress foreground notification banners on Android ModTools — badge is
        // already updated above; we don't want the tray notification to appear.
        // Pass the integer tray id (data.notId), NOT the Capacitor notification
        // object: notification.id is the Firebase message id (string), which the
        // Android plugin tries to unbox into int → NullPointerException crash.
        if (!this.isiOS && modtools) {
          const notId = parseInt(data.notId)
          if (Number.isFinite(notId)) {
            try {
              await PushNotifications.removeDeliveredNotifications({
                notifications: [{ id: notId }],
              })
            } catch (e) {
              console.log('removeDeliveredNotifications failed', e?.message)
            }
          }
        }

        // When a push notification is received while the app is in the foreground,
        // immediately refresh chats to update unread counts and trigger message fetching.
        // This ensures new messages appear without waiting for the 30-second poll.
        //
        // NEW_POSTS (daily digest) pushes don't carry chat data, so refreshing the
        // chat store would be pointless noise.  We just let the badge update (done
        // above) take effect and let the user tap to browse — no further action needed
        // in the foreground.
        if (foreground && data.channel_id !== 'new_posts') {
          console.log('Foreground push received - refreshing chats')
          dbg()?.info('Foreground push - triggering chat refresh')
          const chatStore = useChatStore()
          chatStore.fetchChats(null, false)

          // If the notification includes a specific chat ID, also fetch messages for that chat directly.
          // This is faster than waiting for the unseen watcher in ChatPane.
          if (data.chatids) {
            const chatId = parseInt(data.chatids)
            if (chatId) {
              console.log(
                'Foreground push - fetching messages for chat',
                chatId
              )
              dbg()?.info('Foreground push - fetching messages', { chatId })
              chatStore.fetchMessages(chatId)
            }
          }
        }

        if (!this.isiOS && 'inlineReply' in data) {
          const inlineReply = data.inlineReply.trim()
          console.log('======== inlineReply', inlineReply)
          if (inlineReply) {
            this.inlineReply = inlineReply
            this.chatid = parseInt(data.chatids)
          }
        }
        console.log('handleNotification B', this.inlineReply, this.chatid)

        if ('route' in data) {
          this.route = data.route
        }
        console.log('handleNotification C', this.route)

        if (this.inlineReply) {
          this.inlineReply = false
          this.pushed = false
          this.route = false
          return
        }

        const { App } = await import('@capacitor/app')
        const appState = await App.getState()
        const active = appState ? appState.isActive : false
        let okToMove = false
        if (this.isiOS) {
          okToMove = !active
        } else {
          okToMove = (!foreground && active) || (foreground && !active)
        }
        if (notification.okToMove) okToMove = true
        console.log(
          'this.isiOS',
          this.isiOS,
          'active',
          active,
          'okToMove',
          okToMove
        )

        if (this.route && okToMove) {
          this.route = this.route.replace('/chat/', '/chats/')
          console.log('router.currentRoute', router.currentRoute)
          if (router.currentRoute.value.path !== this.route) {
            console.log('GO TO ', this.route)
            router.push(this.route)
          }
        }

        this.route = false
      } catch (e) {
        console.log('hangleNotification exception', e.message)
      }
    },

    async handleReplyAction(notification, replyText) {
      // Send a reply message directly from the notification
      console.log('handleReplyAction', replyText, notification)
      try {
        const data = notification?.data
        if (!data) {
          console.error('handleReplyAction: no notification data')
          return
        }

        // Get chat ID from notification data
        const chatId = parseInt(data.chatids)
        if (!chatId) {
          console.error('handleReplyAction: no chat ID in notification')
          return
        }

        // Send the reply via API
        await api(this.config).chat.send({
          roomid: chatId,
          message: replyText,
        })
        console.log('handleReplyAction: message sent successfully')
        // Confirm the reply with a success haptic (best-effort; in-app only).
        try {
          const { Haptics, NotificationType } = await import(
            '@capacitor/haptics'
          )
          await Haptics.notification({ type: NotificationType.Success })
        } catch (he) {
          dbg()?.debug('haptic not available', he?.message)
        }
      } catch (e) {
        console.error('handleReplyAction error:', e.message)
      }
    },

    async handleMarkReadAction(notification) {
      // Mark the chat as read without opening the app
      console.log('handleMarkReadAction', notification)
      try {
        const data = notification?.data
        if (!data) {
          console.error('handleMarkReadAction: no notification data')
          return
        }

        // Get chat ID from notification data
        const chatId = parseInt(data.chatid || data.chatids)
        if (!chatId) {
          console.error('handleMarkReadAction: no chat ID in notification')
          return
        }

        // Get the message ID from the notification - use the actual message ID, not a magic number
        const messageId = parseInt(data.messageid)
        if (!messageId) {
          console.error('handleMarkReadAction: no message ID in notification')
          return
        }

        // Mark as read up to this specific message ID
        await api(this.config).chat.markRead(chatId, messageId, false)
        console.log(
          'handleMarkReadAction: chat marked as read up to message',
          messageId
        )

        // Update badge count
        const { Badge } = await import('@capawesome/capacitor-badge')
        const newCount = Math.max(0, (parseInt(data.badge) || 1) - 1)
        this.setBadgeCount(newCount, Badge)
      } catch (e) {
        console.error('handleMarkReadAction error:', e.message)
      }
    },

    async checkForAppUpdate() {
      const requiredKey = this.isiOS
        ? 'app_fd_version_ios_required'
        : 'app_fd_version_android_required'

      const reqdValues = await api(this.config).config.fetchv2(requiredKey)
      if (reqdValues && reqdValues.length === 1) {
        const requiredVersion = reqdValues[0].value
        if (requiredVersion) {
          this.apprequiredversion = requiredVersion
          if (this.versionOutOfDate(requiredVersion)) {
            this.appupdaterequired = true
            console.log('==========appupdate required!')
          }
        }
      }
    },

    versionOutOfDate(newver) {
      const runtimeConfig = useRuntimeConfig()
      const currentver = runtimeConfig.public.MOBILE_VERSION
      if (!newver) return false
      const anewver = newver.split('.')
      const acurrentver = currentver.split('.')
      for (let vno = 0; vno < 3; vno++) {
        const cv = parseInt(acurrentver[vno])
        const nv = parseInt(anewver[vno])
        if (nv > cv) return true
        if (nv < cv) return false
      }
      return false
    },
  },
})
