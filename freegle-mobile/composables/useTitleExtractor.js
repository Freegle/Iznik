const OFFER_PREFIXES = [
  /^got an?\s+/i,
  /^offering\s+(an?\s+)?/i,
  /^free to collect\s*[-–—]?\s*/i,
  /^giving away\s+(an?\s+)?/i,
  /^give away\s+(an?\s+)?/i,
  /^free[:\s]+/i,
]

const WANTED_PREFIXES = [
  /^looking for\s+(an?\s+)?/i,
  /^anyone got\s+(an?\s+)?/i,
  /^need\s+(an?\s+)?/i,
  /^wanted[:\s]+/i,
  /^does anyone have\s+(an?\s+)?/i,
  /^in search of\s+(an?\s+)?/i,
]

export function extractTitle(text, type) {
  if (!text) return ''

  let title = text.trim()

  if (type === 'Offer') {
    for (const prefix of OFFER_PREFIXES) {
      title = title.replace(prefix, '')
    }
  } else if (type === 'Wanted') {
    for (const prefix of WANTED_PREFIXES) {
      title = title.replace(prefix, '')
    }
  }

  // Take text up to first comma, full stop, or question mark
  const endMatch = title.match(/[,.?]/)
  if (endMatch) {
    title = title.substring(0, endMatch.index)
  }

  // Truncate at 40 characters on a word boundary
  if (title.length > 40) {
    title = title.substring(0, 40).replace(/\s+\S*$/, '')
  }

  title = title.trim()
  if (title.length > 0) {
    title = title.charAt(0).toUpperCase() + title.slice(1)
  }

  return title
}
