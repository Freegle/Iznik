// Pure helper for the deprivation swingometer on the reach map.
//
// This file used to also classify and format digest-simulator posts. The explorer no
// longer previews a digest - what a member is shown is now answered by the reach map's
// inbound direction, which needs a boundary rather than a ranked post list - so those
// helpers went with it.

// Classify the swingometer reading against the area's deprivation baseline.
//
// `baselineReady` is false when the area baseline hasn't been measured for the
// current location yet (the fetch is pending or failed). In that case we must
// NOT present the national-average fallback as if it were this area's measured
// figure — return an explicit "unavailable" state so the UI can say so instead
// of showing a confident-but-wrong bias.
export function swingometerDisplay(pct, localBaseline, baselineReady) {
  if (!baselineReady) {
    return { ready: false, label: 'Area baseline unavailable', color: '#999' }
  }
  const lo = localBaseline - 8
  const hi = localBaseline + 8
  const label =
    pct < lo ? 'Affluent bias' : pct > hi ? 'Deprived bias' : 'Balanced'
  const color = pct < lo ? '#4477aa' : pct > hi ? '#d73027' : '#1a9850'
  const aboveBaseline = pct >= localBaseline
  const diff = Math.abs(pct - localBaseline)
  return { ready: true, label, color, aboveBaseline, diff }
}
