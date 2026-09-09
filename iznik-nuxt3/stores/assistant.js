import { defineStore } from 'pinia'
import { watch } from 'vue'
import api from '~/api'
import { useAuthStore } from '~/stores/auth'

// The Freegle chat as the browser sees it: the lines on screen, the conversation the
// server is running, and what Freegle is saying right now. For a member the lines are
// also in their real Freegle chat room; this store is what renders.

export const MAX_LINES = 200

export const useAssistantStore = defineStore('assistant', {
  state: () => ({
    conversation: null,
    lines: [], // { id, who: 'freegle'|'member', text, widget?, ts }
    state: 'HUB',
    chips: [],
    progress: null,
    hostAction: null,
    slots: {},
    facts: {},
    streaming: false,
    streamText: '',
    busy: false,
    error: null,
    lastChatId: null,
    nextId: 1,
    cards: null,
    photos: [],
    // Host action keys already run, so a repeated turn cannot post twice.
    done: [],
    bound: false,
  }),
  persist: {
    storage: piniaPluginPersistedstate.localStorage(),
    pick: [
      'conversation',
      'lines',
      'state',
      'chips',
      'progress',
      'slots',
      'facts',
      'nextId',
      'lastChatId',
    ],
  },
  getters: {
    lastFreegleLine: (state) => {
      for (let i = state.lines.length - 1; i >= 0; i--) {
        if (state.lines[i].who === 'freegle') return state.lines[i]
      }
      return null
    },
    inFlow: (state) => !!state.progress,
  },
  actions: {
    init(config) {
      this.config = config
      if (this.bound) return
      this.bound = true
      // Whoever signs out, or signs in as someone else, on this device: nothing of the
      // last person's chat stays behind, whichever menu they used to leave.
      const auth = useAuthStore()
      watch(
        () => auth.user?.id,
        (now, before) => {
          if (before && now !== before) this.forget()
        }
      )
    },
    forget() {
      this.reset()
      this.cards = null
      this.photos = []
      this.lastChatId = null
      this.done = []
      try {
        api(this.config).assistant.clearAnonToken()
      } catch (e) {
        // no browser storage
      }
    },
    push(line) {
      this.lines.push({ id: this.nextId++, ts: Date.now(), ...line })
      while (this.lines.length > MAX_LINES) this.lines.shift()
    },
    reset() {
      this.conversation = null
      this.lines = []
      this.state = 'HUB'
      this.chips = []
      this.progress = null
      this.hostAction = null
      this.slots = {}
      this.facts = {}
      this.streaming = false
      this.streamText = ''
      this.error = null
    },
    applyTurn(turn) {
      this.conversation = turn.conversation
      this.state = turn.state
      this.chips = turn.chips || []
      this.progress = turn.progress || null
      this.hostAction = turn.hostAction || null
      this.slots = turn.slots || {}
      this.facts = turn.facts || {}
      if (turn.chatid) this.lastChatId = turn.chatid
      if (turn.say) {
        this.push({
          who: 'freegle',
          text: turn.say,
          widget: turn.widget || null,
          fallback: !!turn.fallback,
        })
      }
    },
    // Send something to Freegle: { text } | { tap } | { event }. The member's line is
    // shown at once; Freegle's reply streams in.
    async send(body, memberLine) {
      if (this.busy) return null
      this.busy = true
      this.error = null
      if (memberLine) this.push({ who: 'member', text: memberLine })
      this.streaming = true
      this.streamText = ''
      try {
        const turn = await api(this.config).assistant.turn(
          { ...body, conversation: this.conversation },
          {
            onDelta: (t) => {
              this.streamText += t
            },
          }
        )
        this.applyTurn(turn)
        return turn
      } catch (e) {
        this.error = e?.message || 'error'
        return null
      } finally {
        this.streaming = false
        this.streamText = ''
        this.busy = false
      }
    },
    sendText(text) {
      return this.send({ text }, text)
    },
    sendTap(chip) {
      return this.send({ tap: chip.value }, chip.label)
    },
    sendEvent(event, memberLine = null) {
      return this.send({ event }, memberLine)
    },
  },
})
