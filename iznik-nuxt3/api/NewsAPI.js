import BaseAPI from '@/api/BaseAPI'

export default class NewsAPI extends BaseAPI {
  fetchFeed(params) {
    return this.$getv2('/newsfeed', params)
  }

  async fetch(id, distance, lovelist, logError) {
    return await this.$getv2(
      id ? '/newsfeed/' + id : '/newsfeed',
      {
        distance,
        lovelist,
      },
      logError
    )
  }

  async send(data) {
    const { id } = await this.$postv2('/newsfeed', data)
    return id
  }

  edit(id, message) {
    return this.$patchv2('/newsfeed', { id, message, action: 'Edit' })
  }

  love(id) {
    return this.$postv2('/newsfeed', { id, action: 'Love' })
  }

  unlove(id) {
    return this.$postv2('/newsfeed', { id, action: 'Unlove' })
  }

  async unfollow(id) {
    await this.$postv2('/newsfeed', { id, action: 'Unfollow' })
  }

  async report(id, reason) {
    await this.$postv2('/newsfeed', { id, reason, action: 'Report' })
  }

  async referto(id, type) {
    await this.$postv2('/newsfeed', { id, action: 'ReferTo' + type })
  }

  async unhide(id, type) {
    await this.$postv2('/newsfeed', { id, action: 'Unhide' })
  }

  async hide(id, type) {
    await this.$postv2('/newsfeed', { id, action: 'Hide' })
  }

  async convertToStory(id, type) {
    await this.$postv2('/newsfeed', { id, action: 'ConvertToStory' })
  }

  // ChitChat moderators only - the server 403s for anyone else, because the
  // answer names one of the poster's own posts.
  async duplicate(id) {
    return await this.$getv2(`/newsfeed/${id}/duplicate`)
  }

  // Records on the thread that a volunteer has posted this properly for the
  // member, pointing at the OFFER/WANTED just created. ChitChat moderators only.
  async convertedToPost(id, msgid) {
    return await this.$postv2('/newsfeed', {
      id,
      msgid,
      action: 'ConvertedToPost',
    })
  }

  async seen(id) {
    await this.$postv2('/newsfeed?bump=' + id, { id, action: 'Seen' })
  }

  del(id) {
    return this.$requestv2('DELETE', `/newsfeed/${id}`, {})
  }

  async count(distance, log) {
    return await this.$getv2(
      '/newsfeedcount',
      {
        distance,
      },
      log
    )
  }
}
