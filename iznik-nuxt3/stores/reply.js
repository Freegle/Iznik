import { defineStore } from 'pinia'

export const useReplyStore = defineStore({
  id: 'reply',
  persist: {
    storage: piniaPluginPersistedstate.localStorage(),
    pick: [
      'replyMsgId',
      'replyMessage',
      'replyingAt',
      'machineState',
      'isNewUser',
      'draftMsgId',
      'draftMessage',
      'draftCollect',
      'draftEmail',
      'draftAt',
    ],
  },
  state: () => ({
    // A reply the user has committed to sending (Send clicked). Once these are
    // set for a logged-in user, useReplyToPost will send it on page load - so
    // in-progress typing must never be stored here.
    replyMsgId: null,
    replyMessage: null,
    replyingAt: null,
    // State machine state for resume capability
    machineState: null,
    isNewUser: false,
    // A composing draft (typed but not submitted), restored if the reply pane
    // is closed and reopened on the same post. Kept separate from the
    // pending-send fields above so a draft can never auto-send.
    draftMsgId: null,
    draftMessage: null,
    draftCollect: null,
    draftEmail: null,
    draftAt: null,
  }),
  actions: {
    init(config) {
      this.config = config
    },
    // Clear all reply state
    clearReply() {
      this.replyMsgId = null
      this.replyMessage = null
      this.replyingAt = null
      this.machineState = null
      this.isNewUser = false
      this.clearDraft()
    },
    // Save state machine state for resume
    saveMachineState(state, isNewUser = false) {
      this.machineState = state
      this.isNewUser = isNewUser
    },
    // Save a composing draft so close/reopen doesn't lose typed text
    saveDraft({ msgId, message, collect, email }) {
      this.draftMsgId = msgId
      this.draftMessage = message
      this.draftCollect = collect
      this.draftEmail = email
      this.draftAt = Date.now()
    },
    clearDraft() {
      this.draftMsgId = null
      this.draftMessage = null
      this.draftCollect = null
      this.draftEmail = null
      this.draftAt = null
    },
  },
})

// export const mutations = {
//   set(state, params) {
//     state.replyMsgId = params.replyMsgId
//     state.replyMessage = params.replyMessage
//     state.replyingAt = params.replyingAt
//   }
// }
//
// export const getters = {
//   get: state => {
//     return state
//   }
// }
//
// export const actions = {
//   set({ commit }, params) {
//     commit('set', params)
//   }
// }
