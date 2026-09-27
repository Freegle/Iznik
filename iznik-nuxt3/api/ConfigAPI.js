import BaseAPI from '@/api/BaseAPI'

export default class ConfigAPI extends BaseAPI {
  fetchv2(key) {
    return this.$getv2('/config/' + key)
  }

  // Admin config endpoints
  fetchAdminv2(key) {
    return this.$getv2('/config/admin/' + key)
  }

  patchAdminv2(data) {
    return this.$patchv2('/config/admin', data)
  }

}
