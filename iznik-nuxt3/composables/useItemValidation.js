// Client-side compose validation. A post has to actually say what's being given
// away or wanted, so we reject — before posting — item names and descriptions
// that carry no real information: a value with no descriptive words (a bare
// number, a price, or only punctuation/symbols), or a content-free catch-all
// term like "anything" / "everything".

export const INVALID_ITEM_MESSAGE =
  "That's not a valid item — please say what it actually is, not just a number or symbols."

export const VAGUE_ITEM_MESSAGE =
  "Please be specific about the item — a general word like that isn't enough for people to know what you mean."

// Description/body messages depend on the post type so the wording reads
// naturally ("looking for" vs "giving away").
export const INVALID_BODY_MESSAGE_OFFER =
  "Please describe what you're giving away — numbers or symbols on their own don't tell anyone what it is."

export const INVALID_BODY_MESSAGE_WANTED =
  "Please explain what you're looking for — numbers or symbols on their own don't tell anyone what you want."

// Catch-all terms that say nothing about the actual item. Matched as whole
// strings (anchored), lower-cased and trimmed, so "anything" is blocked but
// "anything blue" or "garden tools" is fine. Deliberately narrower than the
// PostItem "too vague" *warning* list, which also nudges on legitimate-but-broad
// categories ("furniture", "tools", "garden") that we still let people post.
const UNPOSTABLE_ITEM_PATTERNS = [
  /^any$/,
  /^anything$/,
  /^anything else$/,
  /^everything$/,
  /^every thing$/,
  /^all$/,
  /^stuff$/,
  /^things$/,
  /^items$/,
  /^goods$/,
  /^don'?t know$/,
  /^browsing$/,
  /^browse$/,
  /^eney fink$/,
  /^eney think$/,
  // Additional content-free catch-alls found slipping through the anchored list
  // above on live data (30 days of production WANTED posts). None is ever a real
  // item, so specific single-word posts ("bike", "sofa", "fan", "tv") are never
  // affected; together these add ~13/30d of low-quality posts with zero
  // false positives in the sampled matches.
  /^various( items| stuff| things)?$/,
  /^free (stuff|items|things)$/,
  /^freebies$/,
  /^something$/,
  /^owt$/,
  /^whatever$/,
  /^unwanted( items| stuff)?$/,
  /^misc$/,
  /^miscellaneous$/,
  /^sundries$/,
  /^no idea$/,
  /^not sure$/,
  /^nothing specific$/,
  // The "anything / everything / something" family with only a content-free
  // qualifier ("anything nice", "everything really") still says nothing about
  // the item. A *specific* trailer ("anything for a toddler", "something for the
  // garden") is deliberately NOT matched, so real posts still get through.
  /^(anything|everything|something) (else|nice|really|useful|good|free|at all)$/,
]

// Shared core: true when the value has no descriptive letters after trimming —
// i.e. it's nothing but digits, punctuation, currency symbols and/or whitespace,
// e.g. "123", " 42 ", "12.50", "1,000", "£5" or "!!!". Anything containing a
// letter of any script ("3 chairs", "size 12 boots", "café", "стол") is allowed.
// Empty/nullish → false (emptiness is handled separately by the required check).
function hasNoDescriptiveText(value) {
  if (!value) return false
  const trimmed = value.trim()
  if (trimmed === '') return false
  // \p{L} matches a letter in any language, so non-English descriptions pass.
  return !/\p{L}/u.test(trimmed)
}

/**
 * True when the item name carries no descriptive words — a bare number, a price,
 * or only punctuation/symbols (e.g. "123", "12.50", "£5", "!!!"). See
 * hasNoDescriptiveText.
 *
 * @param {string|null|undefined} item
 * @returns {boolean}
 */
export function isNumericOnlyItem(item) {
  return hasNoDescriptiveText(item)
}

/**
 * True when the description/body carries no descriptive words — same rule as
 * items.
 *
 * @param {string|null|undefined} body
 * @returns {boolean}
 */
export function isNumericOnlyBody(body) {
  return hasNoDescriptiveText(body)
}

/**
 * True when the item is a content-free catch-all term ("anything",
 * "everything", …) that says nothing about the actual item. Empty/nullish →
 * false.
 *
 * @param {string|null|undefined} item
 * @returns {boolean}
 */
export function isVagueItem(item) {
  if (!item) return false
  const v = item.trim().toLowerCase()
  return UNPOSTABLE_ITEM_PATTERNS.some((re) => re.test(v))
}

/**
 * An item that can never be posted: a value with no descriptive words or a
 * content-free catch-all term. This is the single predicate the compose flows
 * gate submission on.
 *
 * @param {string|null|undefined} item
 * @returns {boolean}
 */
export function isUnpostableItem(item) {
  return isNumericOnlyItem(item) || isVagueItem(item)
}

/**
 * The message explaining why an item can't be posted, or null if it's fine.
 *
 * @param {string|null|undefined} item
 * @returns {string|null}
 */
export function unpostableItemMessage(item) {
  if (isNumericOnlyItem(item)) return INVALID_ITEM_MESSAGE
  if (isVagueItem(item)) return VAGUE_ITEM_MESSAGE
  return null
}

/**
 * The "say more in the body" message appropriate to the post type.
 *
 * @param {string} type 'Offer' or 'Wanted'
 * @returns {string}
 */
export function invalidBodyMessage(type) {
  return type === 'Wanted'
    ? INVALID_BODY_MESSAGE_WANTED
    : INVALID_BODY_MESSAGE_OFFER
}
