import BaseAPI from '@/api/BaseAPI'

// A 409 with lockdown: true is an expected, deliberate refusal (see
// api/lockdownConflict.js), not an incident - so it must never reach Sentry.
const notALockdownConflict = (data) => !data?.lockdown

export default class LockdownAPI extends BaseAPI {
  // Public: just the notice, if any. Never surfaces or active.
  fetch() {
    return this.$getv2('/lockdown')
  }

  // Any moderator: full state (phrases only present for Support/Admin - the
  // Go API decides that, not us).
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

  // Support/Admin only. data carries an `action` field (press, surfaces,
  // notice, phrases, markspam, releaseclass, liftall, close) plus that
  // action's own fields.
  patch(data) {
    return this.$patchv2('/lockdown', data, notALockdownConflict)
  }
}
