// Robust suppression for Freestar ftUtils.js null-property errors
// (NUXT3-CES getPlacementPosition, NUXT3-D2H getInnerDimensions) operating on
// Sentry's parsed event frames. suppressException() below already matches on
// err.stack string contents, but some events reach Sentry with a stack that
// doesn't string-match (e.g. framework-wrapped TypeErrors where the original
// exception is reconstructed before beforeSend sees it). A frame whose filename
// is ftUtils.js AND whose function is one of Freestar's known null-property
// sites is a narrow Freestar signature — won't mask real bugs in our own code.
export function suppressSentryEvent(event) {
  if (!event?.exception?.values) {
    return false
  }
  for (const ex of event.exception.values) {
    const frames = ex.stacktrace?.frames || []
    for (const frame of frames) {
      const isFtUtilsFrame =
        frame.filename?.includes('/ftUtils.js') ||
        frame.abs_path?.includes('/ftUtils.js')
      const fn = frame.function || ''
      const isFreestarFn =
        fn === 'getPlacementPosition' ||
        fn === 'Object.getPlacementPosition' ||
        fn === 'getInnerDimensions' ||
        fn === 'Object.getInnerDimensions'
      if (isFtUtilsFrame && isFreestarFn) {
        return true
      }
    }
  }
  return false
}

export function suppressException(err) {
  if (!err) {
    return false
  }

  if (
    err.message?.includes('leaflet') ||
    err.message?.includes('LatLng') ||
    err.message?.includes('Map container not found') ||
    err.message?.includes('latLngToLayerPoint') ||
    err.stack?.includes('leaflet') ||
    err.stack?.includes('LMap') ||
    err.stack?.includes('LMarker') ||
    err.stack?.includes('layer') ||
    err.stack?.includes('latLngToLayerPoint') ||
    err.stack?.includes('_updatePosition') ||
    err.message?.includes('Map container not found')
  ) {
    // Leaflet throws all kinds of errors when the DOM elements are removed.  Ignore them all.
    console.log('Leaflet in stack - ignore')
    return true
  }
  if (
    // MT
    err.stack?.includes('chart element')
  ) {
    // GChart seems to show this error occasionally - ignore
    console.log('suppressException chart element - ignore')
    return true
  }

  // focus-trap library throws when a modal briefly has no tabbable elements during a transition
  // (e.g. while buttons are disabled during an API call). The error is transient — the modal
  // recovers — but if unsuppressed it reaches Nuxt's error handler and shows an error page.
  if (err.message?.includes('focus-trap must have at least one container')) {
    console.log('focus-trap timing error during modal transition - suppress')
    return true
  }

  // Freestar (third-party ad provider) ftUtils.js throws a range of null-property
  // TypeErrors from cross-origin iframes — getPlacementPosition reads
  // (this.isPlacementXdom?t:t.parent).document with t.parent=null (NUXT3-CES),
  // getInnerDimensions reads t.display with t=null (NUXT3-D2H). Match on the
  // Freestar signature (ftUtils.js filename or known Freestar function names in
  // the stack) rather than the generic message, so we don't mask real bugs in
  // our own code.
  if (
    err.stack?.includes('ftUtils.js') ||
    err.stack?.includes('getPlacementPosition') ||
    err.stack?.includes('getInnerDimensions')
  ) {
    console.log('Freestar ftUtils - suppress exception')
    return true
  }

  // Browser-native NotReadableError from file/camera/media reads. Sentry issue
  // NUXT3-D2P (568 events / 357 users). Typically fired on mobile Safari/iOS
  // when the user denies permission, an iCloud Photo is not yet downloaded,
  // the file disappears mid-read, the device is low on memory, or the user
  // cancels a file picker. Benign user-environment error, not a Freegle bug.
  // Match on the full inner phrase so unrelated NotReadableErrors still report.
  if (
    err.message?.includes('NotReadableError: The I/O read operation failed') ||
    err.toString?.().includes('NotReadableError: The I/O read operation failed')
  ) {
    console.log('NotReadableError I/O - suppress exception')
    return true
  }

  return false
}
