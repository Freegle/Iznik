import BaseAPI from '@/api/BaseAPI'
import { notAHeldConflict } from '~/api/heldConflict'

export default class VolunteeringAPI extends BaseAPI {
  fetch(id, logError = true) {
    return this.$getv2('/volunteering/' + id, {}, logError)
  }

  list(params) {
    return this.$getv2('/volunteering', params)
  }

  save(data) {
    return this.$patchv2('/volunteering', data, notAHeldConflict)
  }

  async add(data) {
    const { id } = await this.$postv2('/volunteering', data)
    return id
  }

  setPhoto(id, photoid) {
    return this.$patchv2('/volunteering', { id, photoid, action: 'SetPhoto' })
  }

  addDate(id, start, end) {
    return this.$patchv2('/volunteering', { id, start, end, action: 'AddDate' })
  }

  removeDate(id, dateid) {
    return this.$patchv2('/volunteering', { id, dateid, action: 'RemoveDate' })
  }

  del(id) {
    return this.$delv2('/volunteering/' + id)
  }

  renew(id) {
    return this.$patchv2('/volunteering', { id, action: 'Renew' })
  }

  expire(id) {
    return this.$patchv2('/volunteering', { id, action: 'Expire' })
  }
}
