import * as Sentry from '@sentry/browser'

// The compose store's drafts as an array, whatever state the store is in.
//
// The store is restored from local storage and `messages` is meant to be an array, but production
// has had it truthy and not an array, which made `.find` throw inside the flow telemetry
// (Sentry NUXT3 "(n.messages || []).find is not a function"). Telemetry must never break a page, so
// anything that is not an array reads as no drafts. The first time it happens it is reported once
// with what the value was, which is the fact needed to find out how it got that way.
let reported = false

export function composeMessageList(store) {
  const messages = store?.messages

  if (Array.isArray(messages)) {
    return messages
  }

  if (messages !== undefined && messages !== null && !reported) {
    reported = true
    Sentry.captureMessage('compose store messages is not an array', {
      level: 'warning',
      extra: {
        valueType: typeof messages,
        tag: Object.prototype.toString.call(messages),
        constructorName: messages?.constructor?.name,
        keys:
          typeof messages === 'object'
            ? Object.keys(messages).slice(0, 10)
            : undefined,
      },
    })
  }

  return []
}

export function _resetComposeMessageListReportForTests() {
  reported = false
}
