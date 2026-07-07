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

  // Individual worry word management
  addWorrywordv2(data) {
    return this.$postv2('/config/admin/worry_words', data)
  }

  deleteWorrywordv2(id) {
    return this.$requestv2('DELETE', `/config/admin/worry_words/${id}`, {})
  }

  // Individual spam keyword management
  addSpamKeywordv2(data) {
    return this.$postv2('/config/admin/spam_keywords', data)
  }

  deleteSpamKeywordv2(id) {
    return this.$requestv2('DELETE', `/config/admin/spam_keywords/${id}`, {})
  }

  // Concern keywords management
  fetchConcernKeywordsv2(params = {}) {
    const query = new URLSearchParams(params).toString()
    return this.$getv2('/config/admin/concern_keywords' + (query ? '?' + query : ''))
  }

  addConcernKeywordv2(data) {
    return this.$postv2('/config/admin/concern_keywords', data)
  }

  deleteConcernKeywordv2(id) {
    return this.$requestv2('DELETE', `/config/admin/concern_keywords/${id}`, {})
  }
}
