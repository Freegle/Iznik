// Marking a post Taken/Received/Withdrawn when it already has an outcome comes back as a
// 409 "Outcome already recorded" (the duplicate check in iznik-server-go message.go). The
// member wanted the post marked and it already is - from another tab, another device, an
// old email link, or a second tap - so that is the result they asked for, not a fault.
export const OUTCOME_ALREADY_RECORDED = 'Outcome already recorded'

export function isOutcomeAlreadyRecorded(e) {
  return (
    e?.response?.status === 409 &&
    e?.response?.data?.message === OUTCOME_ALREADY_RECORDED
  )
}

// Keep that 409 out of Sentry. Pass as the logError argument to $postv2.
export const notOutcomeAlreadyRecorded = (data) =>
  data?.message !== OUTCOME_ALREADY_RECORDED
