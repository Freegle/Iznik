/**
 * Whether a posted OFFER carried a photo. An experiment on the give flow can raise the share of
 * offers that are posted while lowering the share that have a photo, and posts without a photo get
 * fewer replies, so every experiment on the give flow reads this beside the flow's completion.
 *
 * One client log per posted OFFER: event_type give_posted, with the count of the member's own photos
 * (an AI illustration is not one), whether there was any, and the member's arms in variant, the same
 * string the flow events carry. Nothing the member typed is sent.
 */
import { action } from '~/composables/useClientLog'
import { experimentVariant } from '~/composables/useExperiment'

// A photo the member added: not an AI illustration, not still uploading, and with an id the post
// can carry.
export function ownPhotoCount(message) {
  return (message?.attachments || []).filter(
    (a) =>
      a &&
      !a.uploading &&
      !(a.externalmods && a.externalmods.ai) &&
      (typeof a.id === 'number' || a.ouruid || a.externaluid)
  ).length
}

// The most photos on any one of the drafts about to be submitted. Read before the submit, which
// empties the drafts' attachments.
export function givePhotoCount(drafts) {
  return Math.max(0, ...(drafts || []).map(ownPhotoCount))
}

// Called when a submit has worked, with the count read before it.
export function reportGivePosted(photos) {
  try {
    action('give_posted', {
      event_type: 'give_posted',
      photos,
      with_photo: photos > 0,
      variant: experimentVariant(),
    })
  } catch {
    // Telemetry never throws into the flow.
  }
}
