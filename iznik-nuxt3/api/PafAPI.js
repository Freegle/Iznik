import BaseAPI from '@/api/BaseAPI'

// Royal Mail address data (PAF) imports, for the PAF update on the SysAdmin Housekeeping tab.
// Admin only.
export default class PafAPI extends BaseAPI {
  fetchImports() {
    return this.$getv2('/sysadmin/paf')
  }

  // The latest release on AFD's site, as the batch last found it.
  fetchLatest() {
    return this.$getv2('/sysadmin/paf/latest')
  }

  // The latest release, unlocked with the key from AFD's email.
  startImport(key) {
    // A wrong key or a second request is an expected refusal, not one for Sentry.
    return this.$postv2('/sysadmin/paf', { key }, false)
  }
}
