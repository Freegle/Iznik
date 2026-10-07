import BaseAPI from '@/api/BaseAPI'
import { notAHeldConflict } from '~/api/heldConflict'

export default class AdminsAPI extends BaseAPI {
  fetch(params) {
    // One ADMIN is /modtools/admin/<id>. The list endpoint ignores an id parameter and returns the
    // whole list, which the store then filed under that id as if it were the ADMIN.
    if (params?.id) {
      return this.$getv2('/modtools/admin/' + params.id)
    }
    return this.$getv2('/modtools/admin', params)
  }

  async add(data) {
    const { id } = await this.$postv2(
      '/modtools/admin',
      data,
      (res) => res?.error !== 400
    )
    return id
  }

  // Sends a test of the ADMIN to one address and returns the token that lets it be created.
  // A 400 is the moderator's content or address being refused, which the page shows them.
  async test(data) {
    const { testtoken } = await this.$postv2(
      '/modtools/admin',
      { ...data, action: 'Test' },
      (res) => res?.error !== 400
    )
    return testtoken
  }

  async patch(data) {
    await this.$patchv2('/modtools/admin', data, notAHeldConflict)
  }

  async del(data) {
    await this.$delv2('/modtools/admin', data)
  }

  async hold(id) {
    await this.$postv2(
      '/modtools/admin',
      {
        id,
        action: 'Hold',
      },
      notAHeldConflict
    )
  }

  async release(id) {
    await this.$postv2('/modtools/admin', {
      id,
      action: 'Release',
    })
  }
}
