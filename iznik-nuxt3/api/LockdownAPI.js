import BaseAPI from '@/api/BaseAPI'

// A 409 with lockdown: true is an expected, deliberate refusal (see
// api/lockdownConflict.js), not an incident - so it must never reach Sentry.
const notALockdownConflict = (data) => !data?.lockdown

export default class LockdownAPI extends BaseAPI {
  // Public: just the notice, as {text}, or null. Never surfaces or active.
  fetch() {
    return this.$getv2('/lockdown')
  }

  // Any moderator: the state, with the notice as its text or null.
  fetchMod() {
    return this.$getv2('/modtools/lockdown')
  }

  // Support/Admin only.
  fetchStats() {
    return this.$getv2('/modtools/lockdown/stats')
  }

  // Support/Admin only. Last 50 rows.
  fetchHistory() {
    return this.$getv2('/modtools/lockdown/history')
  }

  // Support/Admin only. Items still held, newest first, 50 a page. params:
  // kind (chat, post or chitchat), q (searches the text and the sender's name
  // and email), userid, before (the `next` of the previous page).
  fetchHeld(params) {
    return this.$getv2('/modtools/lockdown/held', params)
  }

  // Support/Admin only. data carries an `action` field (press, surfaces,
  // notice, liftall, close) plus that action's own fields.
  patch(data) {
    return this.$patchv2('/lockdown', data, notALockdownConflict)
  }
}
