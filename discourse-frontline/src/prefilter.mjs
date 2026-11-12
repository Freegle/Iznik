// Posts that need no model to read them.
//
// Most of a forum is not a report: the service's own replies, a developer answering,
// a "thanks", a "same here". Each of those is settled here by rule, for nothing. Only
// what is left costs a model call.

import { wordCount } from './text.mjs'

const THANKS = /\b(thanks?|thank you|thx|ta|cheers|great|brilliant|perfect|lovely|super|fab|wonderful|excellent|good to know|noted|understood|will do|ok(?:ay)?)\b/i
// A short post with any of these in it is saying something, however politely.
const NOT_JUST_THANKS = /\?|\b(but|still|not|n't|cannot|won't|doesn't|isn't|again|however|although|except|problem|issue|broken|wrong|error|fail|how|why|when|where|could|can you|please)\b/i

const ME_TOO = /\b(me too|same here|same (?:problem|issue|thing)(?: here| for me)?|(?:also|too) (?:seeing|getting|having|happening)|(?:seeing|getting|having) (?:this|the same|that) (?:too|as well|here)|happening (?:to|for) me (?:too|as well))\b|^\s*\+1\b/i
// "Not working" alone is how a first report reads, so it is not in here: only words
// that say it was looked at before.
const STILL_BROKEN = /\b(still (?:not|broken|happening|the same|doing|getting|seeing|can't|cannot|won't|no)|not (?:fixed|resolved)|hasn't (?:worked|fixed|changed)|didn't (?:work|fix)|(?:is|it's|its) back|happening again|same as before|no (?:change|different|better))\b/i
// Words a report of something broken tends to use. Outside the support categories
// only posts with one of these are read at all.
const PROBLEM = /\b(?:error|bug|broken|glitch|crash\w*|fail(?:s|ed|ing)?|stuck|spinn\w+|fr[eo]zen?|freez\w+|(?:blank|white) (?:page|screen)|(?:not|isn't|doesn't|won't|stopped|can't|cannot|couldn't|unable to) (?:\w+ )?(?:work\w*|load\w*|open\w*|send\w*|sent|arriv\w*|receiv\w*|show\w*|log(?:ging)? ?in|sav\w*|post\w*|approv\w*|upload\w*|display\w*)|keeps? (?:crashing|logging|asking|failing))\b/i

export const soundsBroken = (text) => PROBLEM.test(text)

const RESOLVED = /\b((?:now |all )?(?:working|works|fixed|sorted|resolved)(?: now| again)?|that(?:'s| has| is) (?:fixed|sorted|done|worked)|all good|problem solved)\b/i

/**
 * @returns {{ pass: true, listenOnly: boolean } | { pass: false, why: string, signal?: 'metoo'|'still-broken'|'resolved'|'staff'|'thanks' }}
 */
export function prefilter(post, cfg) {
  const author = (post.author ?? '').toLowerCase()
  if (post.postType !== 1) return { pass: false, why: 'not-a-regular-post' }
  if (author === cfg.botUsername || author === 'system' || author === 'discobot') return { pass: false, why: 'own-or-system' }
  // A post already read once (an edit being re-read) passed these two then.
  if (!post.edited) {
    // A private message has no category. Those are never read, whoever they are to.
    if (post.archetype === 'private_message' || post.categoryId === null) return { pass: false, why: 'private-message' }
  }
  // Elsewhere on the forum people mostly talk to each other. The service listens there
  // for something broken, and never speaks.
  const listenOnly = !post.edited && cfg.categories.length > 0 && !cfg.categories.includes(post.categoryId)
  if (listenOnly && !PROBLEM.test(post.text)) return { pass: false, why: 'category-not-watched' }
  if (cfg.staff.includes(author)) return { pass: false, why: 'staff', signal: 'staff' }

  const text = post.text
  const words = wordCount(text)
  if (words === 0 && !post.hasImage) return { pass: false, why: 'empty' }

  // The order matters: "thanks, but it's still not working" is not a thank-you.
  if (words <= 30 && STILL_BROKEN.test(text) && !/\?/.test(text)) return { pass: false, why: 'still-broken', signal: 'still-broken' }
  if (words <= 18 && ME_TOO.test(text) && !/\?/.test(text)) return { pass: false, why: 'me-too', signal: 'metoo' }
  if (words <= 14 && !NOT_JUST_THANKS.test(text)) {
    if (RESOLVED.test(text)) return { pass: false, why: 'resolved', signal: 'resolved' }
    if (THANKS.test(text)) return { pass: false, why: 'thanks', signal: 'thanks' }
  }
  if (words <= 3 && !post.hasImage) return { pass: false, why: 'too-short' }
  return { pass: true, listenOnly }
}
