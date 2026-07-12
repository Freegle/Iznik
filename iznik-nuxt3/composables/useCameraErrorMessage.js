// Human-readable feedback for a failed Capacitor Camera/gallery call.
//
// Per the plugin's own Android/iOS source, Camera.getPhoto()/pickImages()
// reject with "User cancelled photos app" when the user just backs out (not
// an error - stay silent) and with "User denied access to camera"/"User
// denied access to photos" when the OS permission prompt was declined. Every
// call site used to just console.log() whichever of these came back, so a
// declined permission looked identical to nothing happening at all - no
// upload, no error, no clue what to do next.
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
