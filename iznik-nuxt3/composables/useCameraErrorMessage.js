import { action } from '~/composables/useClientLog'

// Human-readable feedback for a failed Capacitor Camera/gallery call.
//
// Per the plugin's own Android/iOS source, Camera.getPhoto()/pickImages()
// reject with "User cancelled photos app" when the user just backs out (not
// an error - stay silent) and with "User denied access to camera"/"User
// denied access to photos" when the OS permission prompt was declined. Every
// call site used to just console.log() whichever of these came back, so a
// declined permission looked identical to nothing happening at all - no
// upload, no error, no clue what to do next.
//
// This function is pure (maps error -> message); reportCameraError() below
// wraps it with the Loki log line.
export function cameraErrorMessage(e) {
  const message = e?.message || ''

  if (/cancelled/i.test(message)) {
    return null
  }

  if (/denied/i.test(message)) {
    return "We couldn't get to your camera or photos because permission was declined. Please allow camera/photos access for Freegle in your phone's settings, then try again."
  }

  return "Sorry, we couldn't get that photo. Please try again."
}

// Same mapping as cameraErrorMessage(), but also logs a Loki client action when the failure
// was a declined OS permission (not a plain cancellation). A decline is a member's choice,
// not a fault, so it is counted in Loki and not raised in Sentry. Returns the user-facing
// message (or null to stay silent on cancellation).
export function reportCameraError(e) {
  const message = e?.message || ''

  if (/denied/i.test(message) && !/cancelled/i.test(message)) {
    action('camera_permission_denied', { error_message: message })
  }

  return cameraErrorMessage(e)
}
