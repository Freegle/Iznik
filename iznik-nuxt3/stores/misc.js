import { defineStore } from 'pinia'
import { warn } from '~/composables/useClientLog'
// import api from '~/api' // REMOVE FROM MT AS GENERATES SOME CIRCULAR REFERENCE

// How long waitForOnline() will wait for the connection to come back before
// giving up and letting the caller fail normally.  Long enough to ride out a
// tunnel or a lift, short enough that nothing is parked indefinitely.
const WAIT_FOR_ONLINE_TIMEOUT_MS = 30000

export const useMiscStore = defineStore({
  id: 'misc',
  persist: {
    storage: piniaPluginPersistedstate.localStorage(),
    pick: ['vals', 'source', 'marketingConsent'],
  },
  state: () => ({
    time: null,
    breakpoint: null,
    isLandscape: false,
    fullscreenModalOpen: false,
    replyOverlayOpen: false,
    vals: {},
    somethingWentWrong: false,
    errorDetails: null,
    appOutOfDate: false,
    appOutOfDateMessage: '',
    needToReload: false,
    needToReloadHard: false,
    visible: true,
    apiCount: 0,
    unloading: false,
    onlineTimer: null,
    online: true,
    pageTitle: null,
    stickyAdRendered: 0,
    adsDisabled: false,
    boredWithJobs: false,
    lastTyping: null,
    modtools: false,
    modtoolsediting: false, // Set when editing message in situ: do not check for work
    worktimer: false,
    deferGetMessages: false,
    source: null,
    marketingConsent: true,
  }),
  actions: {
    init(config) {
      this.config = config
    },
    set(params) {
      this.vals[params.key] = params.value
    },
    setTime() {
      this.time = new Date()
    },
    setBreakpoint(val) {
      this.breakpoint = val
    },
    setLandscape(val) {
      this.isLandscape = val
    },
    setFullscreenModalOpen(val) {
      this.fullscreenModalOpen = val
    },
    setReplyOverlayOpen(val) {
      this.replyOverlayOpen = val
    },
    setSource(val) {
      this.source = val
      // api(this.config).logs.src(val) // REMOVE FROM MT AS GENERATES SOME CIRCULAR REFERENCE
    },
    setErrorDetails(error) {
      this.somethingWentWrong = true
      this.errorDetails = {
        message: error?.message || 'Unknown error',
        stack: error?.stack || null,
        timestamp: new Date().toISOString(),
        name: error?.name || 'Error',
        cause: error?.cause || null,
      }
    },
    clearError() {
      this.somethingWentWrong = false
      this.errorDetails = null
    },
    setAppOutOfDate(message) {
      // The server kill switch (ret:123 from GET /session) tells us this client
      // build is too old to function. Surface it as a clear, explicit message
      // rather than letting it manifest as a silent logout or generic error.
      this.appOutOfDate = true
      this.appOutOfDateMessage = message || ''
    },
    api(diff) {
      this.apiCount += diff

      if (this.apiCount < 0) {
        console.error('API count went negative')
        console.trace()
        this.apiCount = 0
      }
    },
    startOnlineCheck() {
      // navigator.onLine is not reliable, so we ping the server.
      if (!this.onlineTimer) {
        this.checkOnline()
      }
    },
    async fetchWithTimeout(url, options, timeout = 7000) {
      const controller = new AbortController()
      const signal = controller.signal
      options = options || {}
      options.signal = signal

      const p = fetch(url, options)
      let timedout = false
      let ret = null

      let timer = setTimeout(() => {
        console.log('Request timed out', url)
        timedout = true
        controller.abort()
      }, timeout)

      try {
        ret = await p

        if (timer) {
          clearTimeout(timer)
          timer = null
        }

        return ret
      } catch (e) {
        if (timer) {
          clearTimeout(timer)
          timer = null
        }

        if (timedout) {
          throw new Error('timeout')
        } else {
          throw e
        }
      }
    },
    async checkOnline() {
      if (this.visible) {
        try {
          const response = await this.fetchWithTimeout(
            this.config.public.APIv2 + '/online',
            null,
            5000
          )

          if (response?.status === 200) {
            const rsp = await response.json()

            if (!this.online) {
              console.log('Back online')
              this.online = rsp.online
            }
          } else {
            // Null response happens when we time out
            console.log('Offline', response)
            this.online = false
          }
        } catch (e) {
          if (this.online) {
            console.log('Gone offline', e)
            this.online = false
          }
        }
      }

      this.onlineTimer = setTimeout(this.checkOnline, 1000)
    },
    // Wait until we believe we have a connection again, but never for ever.
    //
    // This used to recurse once a second with no timeout and no reject, so a
    // caller that awaited it while the connection stayed down was awaiting a
    // promise that could never settle.  Every API request awaits this before
    // fetching, and useFetchRetry's retryOn() awaits it again around a retry
    // decision, so one badly-timed connection drop could park a request for
    // the lifetime of the page with no error, no Sentry event and nothing for
    // the caller's catch block to see.  A give-flow photo stranded that way
    // stayed at uploading:true / 100% for good, and because the flow gates
    // Next on anyUploading the member could not post at all (Discourse, user
    // 3512849, 2026-08-10).
    //
    // Give up after WAIT_FOR_ONLINE_TIMEOUT_MS and resolve rather than reject:
    // callers then carry on and get an ordinary fetch failure, which they
    // already handle.  Rejecting here would instead add a new unhandled path
    // to every call site.
    waitForOnline(timeoutMs = WAIT_FOR_ONLINE_TIMEOUT_MS) {
      if (this.online) {
        return
      }

      const startedAt = Date.now()

      return new Promise((resolve) => {
        const poll = () => {
          setTimeout(() => {
            if (this.online) {
              resolve()
              return
            }

            const waitedMs = Date.now() - startedAt

            if (waitedMs >= timeoutMs) {
              // Surfaced so we can see this in Loki next time rather than
              // having to infer a silent hang from its absence.
              warn('waitForOnline gave up', {
                event_type: 'connection_wait_abandoned',
                waited_ms: waitedMs,
              })
              resolve()
              return
            }

            poll()
          }, 1000)
        }

        poll()
      })
    },
    setMarketingConsent(value) {
      this.marketingConsent = value
    },
    async fetchLatestMessage() {
      try {
        const response = await fetch(
          this.config.public.APIv2 + '/latestmessage'
        )
        const data = await response.json()
        return data
      } catch (e) {
        console.log('Failed to fetch latest message time', e)
        return { ret: 1, status: 'Error' }
      }
    },
  },
  getters: {
    get: (state) => (key) => {
      return state.vals[key]
    },
  },
})
