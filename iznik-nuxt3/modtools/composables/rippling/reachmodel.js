// How far a post ripples, and how far any one member sees — the two different limits
// the explorer has to describe.
//
// A post's reach grows to the CEILING: the widest travel-time budget any band could
// justify. Each member is then admitted on their OWN band, because what predicts
// whether a reply becomes a collection is the replier's surroundings, not the item's.
// In a city there is always someone closer, so conversion collapses past ~20-25
// minutes; in the countryside it does not fall at all out to 45, and a rural
// member's nearest town is the town they already drive to.
//
// This is a THIRD copy of one policy, and the only reason it is tolerable is that it
// is display-only: the explorer draws what the engine decided, it never decides. The
// deciders are App\Services\Ripple\DensityService (iznik-batch, the ripple's own
// ceiling and each member's cap) and iznik-server-go/density (the browse slider's top
// stop, via /town/near). Both read the same RIPPLE_DENSITY_MAX_MINUTES_* env vars
// with these defaults. Change those and change this, or the demo goes back to
// describing a model we no longer run — which is exactly what prompted this file
// (Discourse 9808/675: the page still called a flat 30 minutes "the reach we
// actually use in production now" a month after that stopped being true).
//
// Prefer the live figure where one is available: the marker's own band and cap come
// from /town/near (cap_minutes, density_band), which is the Go implementation
// answering for a real point rather than these constants standing in for it.

/** Travel-time cap per density band, in minutes. Mirrors ripple.density.max_minutes. */
export const REACH_BAND_MINUTES = Object.freeze({
  dense: 20,
  medium: 30,
  sparse: 45,
})

/**
 * The widest budget any band earns, and therefore how far a post's ripple grows.
 *
 * Taken as the max over the bands rather than hardcoded, mirroring
 * DensityService::ceiling(), so re-tuning one band cannot leave the demo drawing a
 * reach narrower than the one posts actually get.
 */
export const REACH_CEILING_MINUTES = Math.max(
  ...Object.values(REACH_BAND_MINUTES)
)

/** What a band is called on screen. 'unknown' is a real state: density could not be measured. */
export function bandLabel(band) {
  switch (band) {
    case 'dense':
      return 'a town or city'
    case 'medium':
      return 'a middling area'
    case 'sparse':
      return 'the countryside'
    default:
      return null
  }
}

/**
 * The two-limit explanation for a dropped marker, once its band is known.
 *
 * Deliberately says both numbers. Reading only the first is what made the page
 * confusing: a moderator saw the demo offer 45 minutes while their own slider stopped
 * at 7-8 miles, with nothing on the page connecting the two.
 *
 * @param {string} band density_band from /town/near
 * @param {number} capMinutes cap_minutes from /town/near
 * @returns {string|null} null when there is no usable band, so callers can stay quiet
 */
export function reachModelSentence(band, capMinutes) {
  const label = bandLabel(band)
  if (!label || !Number.isFinite(capMinutes) || capMinutes <= 0) return null

  return (
    `A post here ripples out to ${REACH_CEILING_MINUTES} minutes' drive. ` +
    `This spot is ${label}, so a member here is shown posts within ${Math.round(
      capMinutes
    )} minutes of them.`
  )
}

/**
 * The caption under the reach slider.
 *
 * The slider used to run past the ceiling, and this caption marked the point beyond
 * which it was hypothetical. It stops at the ceiling now - the page describes what we
 * run, not what we might - so there is no hypothetical stretch left to caption.
 */
export function reachSliderHelp() {
  const bands = Object.entries(REACH_BAND_MINUTES)
    .sort((a, b) => a[1] - b[1])
    .map(([band, mins]) => `${bandLabel(band)} ${mins}`)
    .join(', ')

  return (
    `Posts ripple out to ${REACH_CEILING_MINUTES} minutes, the default here. ` +
    `Who then sees one depends on where THEY are (${bands} minutes), not on the post.`
  )
}
