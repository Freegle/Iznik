export function suppressException(err) {
  if (!err) {
    return false
  }

  if (
    err.message?.includes('leaflet') ||
    err.message?.includes('LatLng') ||
    err.message?.includes('Map container not found') ||
    err.stack?.includes('leaflet') ||
    err.stack?.includes('LMap') ||
    err.stack?.includes('LMarker') ||
    err.stack?.includes('layer') ||
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

  // Freestar (third-party ad provider) ftUtils.js: getPlacementPosition reads
  // (this.isPlacementXdom?t:t.parent).document with t.parent=null in cross-origin
  // iframes. Sentry issue NUXT3-CES (~11k events). Match on the Freestar signature
  // (ftUtils.js or getPlacementPosition in stack) rather than the generic message
  // so we don't mask real bugs in our own code.
  if (
    err.stack?.includes('ftUtils.js') ||
    err.stack?.includes('getPlacementPosition')
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
