/**
 * Build a case-insensitive regex matching a well-formed Freegle subject prefix —
 * the keyword and its colon, e.g. "OFFER:", "WANTED:", or a group's custom keyword
 * like "OFFERED:"/"REQUESTED:".
 *
 * Groups can configure their own OFFER/WANTED/TAKEN/RECEIVED keywords (group
 * settings.keywords), and a few common variants are recognised too. This single
 * source is used both to STRIP the keyword for display (useMessageDisplay) and, in
 * ModTools, to decide whether a subject follows the group's keyword convention for
 * the subject-colour highlight — so a Wanted post shown with a custom keyword such
 * as "REQUESTED" is recognised rather than wrongly flagged. See Discourse 9481/594.
 *
 * @param {Object} keywords A group's settings.keywords ({offer,wanted,taken,received}).
 * @returns {RegExp} matches "^<keyword>:\s*" case-insensitively.
 */
export function buildKeywordRegex(keywords = {}) {
  const kw = keywords || {}
  // Include BOTH the group's configured keyword AND the standard keyword + common
  // variants for each type. A group that customises its WANTED keyword to "Request"
  // still has older posts titled "WANTED:" — both must be recognised so the same
  // type is treated (and coloured) consistently.
  const keywordList = [
    kw.offer,
    'OFFER',
    'OFFERED', // common offer variant
    'OFFERING', // common offer variant
    kw.wanted,
    'WANTED',
    'REQUESTED', // common wanted variant
    'REQUEST', // common wanted variant
    kw.taken,
    'TAKEN',
    kw.received,
    'RECEIVED',
  ]
  const pattern = keywordList
    .filter(Boolean)
    .map((k) => String(k).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    .join('|')
  return new RegExp(`^(${pattern}):\\s*`, 'i')
}
