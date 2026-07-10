import { defineStore } from 'pinia'

// How long a typed-but-unsent chat draft is kept before it is treated as stale and
// discarded (mirrors the reply pane's 24h draft window in stores/reply.js).
const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000

// Per-chat compose drafts: typed-but-unsent chat text keyed by chat id, so switching
// between chats (or reloading the page) doesn't lose what you were part-way through
// typing - the same convenience the post reply pane already has. Persisted to
// localStorage so it survives a reload; kept in its own tiny store rather than the main
// chat store so the persisted footprint is just the drafts map.
export const useChatDraftStore = defineStore({
  id: 'chatdraft',
  persist: {
    storage: piniaPluginPersistedstate.localStorage(),
    pick: ['drafts'],
  },
  state: () => ({
    // { [chatid]: { text, savedAt } }
    drafts: {},
  }),
  actions: {
    // Save (or, for empty/whitespace text, clear) the draft for a chat. Reassigns the
    // whole map so the persistence plugin reliably picks up the change.
    saveDraft(chatid, text) {
      if (!chatid) return
      if (text && text.trim().length) {
        this.drafts = {
          ...this.drafts,
          [chatid]: { text, savedAt: Date.now() },
        }
      } else {
        this.clearDraft(chatid)
      }
    },
    clearDraft(chatid) {
      if (!chatid || !(chatid in this.drafts)) return
      const next = { ...this.drafts }
      delete next[chatid]
      this.drafts = next
    },
    // Return the draft text for a chat, discarding it first if it has gone stale.
    getDraft(chatid) {
      const d = this.drafts[chatid]
      if (!d) return ''
      if (!d.savedAt || Date.now() - d.savedAt > DRAFT_MAX_AGE_MS) {
        this.clearDraft(chatid)
        return ''
      }
      return d.text || ''
    },
  },
})
