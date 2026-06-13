// A purely-numeric "item" (e.g. "123") isn't a meaningful description of what's
// being given away or wanted, so we reject it client-side before posting.
export const INVALID_ITEM_MESSAGE =
  "That's not a valid item — please say what it actually is, not just a number."

/**
 * True when the item name is nothing but digits (ignoring surrounding
 * whitespace), e.g. "123" or " 42 ". Decimals, thousands separators and any
 * item that merely contains a number ("3 chairs") are allowed. Empty/nullish
 * values return false — emptiness is handled separately by the required check.
 *
 * @param {string|null|undefined} item
 * @returns {boolean}
 */
export function isNumericOnlyItem(item) {
  if (!item) return false
  return /^\d+$/.test(item.trim())
}
