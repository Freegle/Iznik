import BaseAPI from '@/api/BaseAPI'

export default class AIImagesAPI extends BaseAPI {
  review() {
    return this.$getv2('/admin/ai-images/review')
  }

  count() {
    return this.$getv2('/admin/ai-images/count')
  }

  regenerate(id, notes) {
    return this.$postv2(`/admin/ai-images/${id}/regenerate`, { notes })
  }

  accept(id, pendingExternaluid) {
    return this.$postv2(`/admin/ai-images/${id}/accept`, {
      pending_externaluid: pendingExternaluid,
    })
  }
}
