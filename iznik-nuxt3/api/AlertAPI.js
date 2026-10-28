import BaseAPI from '@/api/BaseAPI'

export default class AlertAPI extends BaseAPI {
  fetch(params) {
    return this.$getv2('/modtools/alert', params)
  }

  add(data) {
    return this.$putv2('/modtools/alert', data)
  }

  // Alerts a moderator must reply to before carrying on.
  pending() {
    return this.$getv2('/modtools/alert/pending')
  }

  reply(id, message) {
    return this.$postv2('/modtools/alert/' + id + '/reply', { message })
  }

  snooze(id) {
    return this.$postv2('/modtools/alert/' + id + '/snooze', {})
  }

  cancel(id) {
    return this.$postv2('/modtools/alert/' + id + '/cancel', {})
  }

  record(data) {
    // Click-tracking for admin alerts. The Go handler lives under the modtools
    // prefix; this posted to '/alert' (never registered) and silently 404ed.
    return this.$postv2('/modtools/alert', data)
  }
}
