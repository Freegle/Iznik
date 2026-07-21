// A moderation action refused because another moderator holds the item comes back
// as a 409 carrying `heldby` (see the hold enforcement in iznik-server-go). That is
// an expected, handled outcome - the whole point of a hold - not a fault, so keep it
// out of Sentry. Pass as the logError argument to $postv2/$patchv2.
export const notAHeldConflict = (data) => !data?.heldby
