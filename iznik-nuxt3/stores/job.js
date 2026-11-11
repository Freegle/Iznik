import { defineStore } from 'pinia'
import { nextTick } from 'vue'
import api from '~/api'

// WhatJobs treats a repeat click from the same user/device on the same advert within 24h as
// invalid, so we remember which adverts this device has opened and do not send it to the
// same one again inside that window: the ad slots swap it for a different job, and a
// second tap that lands within a moment of the first (a double-tap, a page reload) is
// dropped. Kept in localStorage so it survives the email-link and app round trips.
const OPENED_KEY = 'jobsOpened'
export const JOB_REPEAT_WINDOW = 24 * 60 * 60 * 1000
export const JOB_DOUBLE_TAP_WINDOW = 2000

// The swap waits a moment, so the link that was tapped is still in the page when the
// browser follows it. Removing it straight away would take it out of the document before
// the navigation starts.
export const JOB_SWAP_DELAY = 1500

function readOpened() {
  try {
    const now = Date.now()
    const stored = JSON.parse(localStorage.getItem(OPENED_KEY) || '{}')
    const opened = {}

    for (const [id, at] of Object.entries(stored)) {
      if (typeof at === 'number' && now - at < JOB_REPEAT_WINDOW) {
        opened[id] = at
      }
    }

    return opened
  } catch (e) {
    return {}
  }
}

function writeOpened(opened) {
  try {
    localStorage.setItem(OPENED_KEY, JSON.stringify(opened))
  } catch (e) {
    // Private browsing or storage blocked - the in-memory record still covers this page.
  }
}

// The email job page shows a card with an "Open this job" button when the visitor looks like a mail
// scanner. A person who taps it once is not asked again on that device, for any job. A scanner's
// browser starts with fresh storage every time, so it never benefits. Unlike the record of opened
// adverts this has no expiry: the card is a one-off.
const GATE_TAPPED_KEY = 'jobGateTapped'

function readGateTapped() {
  try {
    return localStorage.getItem(GATE_TAPPED_KEY) === '1'
  } catch (e) {
    return false
  }
}

function writeGateTapped() {
  try {
    localStorage.setItem(GATE_TAPPED_KEY, '1')
  } catch (e) {
    // Private browsing or storage blocked - the in-memory flag still covers this page.
  }
}

export const useJobStore = defineStore('job', {
  state: () => ({
    list: [],
    fetching: null,
    blocked: false,
    // Job id -> when this device last opened it. Adverts in here are hidden from the ad slots.
    opened: {},
    lastOpenedAt: 0,
    // Whether this device has tapped the email job page card in this page load.
    gateTapped: false,
  }),
  actions: {
    init(config) {
      this.config = config
    },
    restoreOpened() {
      if (typeof window === 'undefined') {
        return
      }

      this.opened = { ...readOpened(), ...this.opened }
    },
    // Whether a tap on this advert would be a repeat WhatJobs does not pay for. Reads storage
    // as well as state so it also sees opens from another page load, without hiding anything.
    openedRecently(id) {
      const at = this.opened[id] || readOpened()[id]
      return Boolean(at) && Date.now() - at < JOB_REPEAT_WINDOW
    },
    // Whether an advert was opened a moment ago, so this tap is a double-tap rather than a choice.
    isDoubleTap() {
      return Date.now() - this.lastOpenedAt < JOB_DOUBLE_TAP_WINDOW
    },
    recordOpened(id, swapDelay = JOB_SWAP_DELAY) {
      const now = Date.now()
      this.lastOpenedAt = now

      // Persist straight away, so a reload inside the delay still knows about it.
      writeOpened({ ...readOpened(), [id]: now })

      const hide = () => {
        this.opened = { ...this.opened, [id]: now }
      }

      if (swapDelay) {
        setTimeout(hide, swapDelay)
      } else {
        hide()
      }
    },
    // Whether this device has tapped the email job page card before. Reads storage as well as state
    // so it also sees a tap from another page load.
    hasTappedGate() {
      return this.gateTapped || readGateTapped()
    },
    recordGateTap() {
      this.gateTapped = true
      writeGateTapped()
    },
    // tz is the browser's time zone, which the API uses to know a UK browser when it sees one.
    async fetchOne(id, tz) {
      let job = null

      try {
        job = await api(this.config).job.fetchOnev2(id, false, tz)
      } catch (e) {
        console.log('Jobs fetch failed - perhaps ad blocked', e)
        this.blocked = true
      }

      return job
    },
    async fetch(lat, lng, category = null, force = false) {
      this.restoreOpened()

      try {
        if (!this.list?.length || force) {
          if (this.fetching) {
            await this.fetching
            await nextTick()
          } else {
            this.fetching = api(this.config).job.fetchv2(lat, lng, category)
            this.list = await this.fetching
            this.fetching = null
          }
        }
      } catch (e) {
        console.log('Jobs fetch failed - perhaps ad blocked', e)
        this.blocked = true
      }

      return this.list
    },
    log(params) {
      api(this.config).job.log(params)
    },
  },
  getters: {
    byId: (state) => (id) => {
      return state.list.find((i) => i.id === id)
    },
    // The jobs to show in ad slots: the list without the adverts this device opened recently.
    available: (state) => {
      const now = Date.now()
      return state.list.filter(
        (j) =>
          !(state.opened[j.id] && now - state.opened[j.id] < JOB_REPEAT_WINDOW)
      )
    },
  },
})
