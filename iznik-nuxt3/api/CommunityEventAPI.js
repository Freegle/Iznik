import BaseAPI from '@/api/BaseAPI'
import { notAHeldConflict } from '~/api/heldConflict'

export default class CommunityEventAPI extends BaseAPI {
  fetch(id, logError = true) {
    return this.$getv2('/communityevent/' + id, {}, logError)
  }

  list(params) {
    return this.$getv2('/communityevent', params)
  }

  save(data) {
    return this.$patchv2('/communityevent', data, notAHeldConflict)
  }

  async add(data) {
    const { id } = await this.$postv2('/communityevent', data)
    return id
  }

  setPhoto(id, photoid) {
    return this.$patchv2('/communityevent', { id, photoid, action: 'SetPhoto' })
  }

  addDate(id, start, end) {
    return this.$patchv2('/communityevent', {
      id,
      start,
      end,
      action: 'AddDate',
    })
  }

  removeDate(id, dateid) {
    return this.$patchv2('/communityevent', {
      id,
      dateid,
      action: 'RemoveDate',
    })
  }

  del(id) {
    return this.$delv2('/communityevent/' + id)
  }
}
