import BaseAPI from '@/api/BaseAPI'

export default class NewsAPI extends BaseAPI {
  fetchFeed(params) {
    return this.$getv2('/newsfeed', params)
  }

  // newsletters: 'all' asks for the Community News posts from every area rather
  // than just our own. The server honours it only for ChitChat moderators.
  async fetch(id, distance, lovelist, logError, newsletters) {
    return await this.$getv2(
      id ? '/newsfeed/' + id : '/newsfeed',
      {
        distance,
        lovelist,
        newsletters,
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

  // Where a convert-to-post would land: the member's own postcode and community,
  // resolved server-side by the same code that does the posting. ChitChat
  // moderators only - it says where another member lives.
  async convertInfo(id) {
    return await this.$getv2(`/newsfeed/${id}/convertinfo`)
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

  // Clear the ChitChat count outright. seen() above needs an id, and the browser only ever
  // has the items it has loaded, so it cannot clear a backlog it has not scrolled through.
  // The server resolves the watermark itself.
  async seenAll() {
    await this.$postv2('/newsfeed', { action: 'SeenAll' })
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
