// "Don't ask me to donate" lasts a year. The server sets settings.nodonationasksuntil when the
// switch is turned on, and a daily job switches off the ones that have lapsed; until that runs,
// a passed date already means we may ask again. An opt-out with no date predates the lapse.
export function donationAsksPaused(settings, now = Date.now()) {
  if (!settings?.nodonationasks) {
    return false
  }

  const until = settings.nodonationasksuntil

  if (!until) {
    return true
  }

  const t = new Date(until).getTime()

  return isNaN(t) || now < t
}

// When a running opt-out ends, or null if there is none or it has no date yet.
export function donationAsksPausedUntil(settings, now = Date.now()) {
  if (!donationAsksPaused(settings, now) || !settings.nodonationasksuntil) {
    return null
  }

  const t = new Date(settings.nodonationasksuntil)

  return isNaN(t.getTime()) ? null : t
}

export function formatDonationAsksUntil(date) {
  return date
    ? date.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      })
    : null
}
