/**
 * The decisions the documentation screenshot generator makes without a browser, kept apart
 * from it so they can be unit tested: which viewport a shot is taken at, and whether it
 * should be taken at all. See docs-screenshots.mjs.
 */

export const VIEWPORTS = {
  // Freegle is mobile-first, so the member guide uses a phone.
  member: { width: 390, height: 844 },
  // ModTools is a desktop tool. At phone width its menu takes half the screen and the page
  // beside it is cut off mid-word.
  mod: { width: 1100, height: 800 },
}

/** A shot's own viewport if it names one, else the one for its app. */
export function viewportFor(shot) {
  return shot.viewport || VIEWPORTS[shot.app === 'mod' ? 'mod' : 'member']
}

/**
 * Why a shot should not be taken this run, or null when it should.
 *
 * A trialOnly shot shows something that exists only for a moderator with a community in the
 * post-moderation trial: a countdown, the Check queue, automated review's decisions. For
 * any other moderator (the seeded test account on CI, which has no trial) the page shows
 * none of it, and regenerating would replace the image with one that does not show what the
 * docs describe. So the shot is skipped and the committed image kept.
 *
 * @param {object} shot an entry of the manifest
 * @param {{inTrial?: boolean}|undefined} session what is known of the logged-in moderator
 */
export function skipReason(shot, session) {
  if (shot.trialOnly && !session?.inTrial) {
    return 'the moderator has no community in the post-moderation trial, so the page would show none of it; keeping the committed image'
  }

  return null
}
