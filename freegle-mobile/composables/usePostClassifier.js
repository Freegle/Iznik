const OFFER_PATTERNS = [
  /\bgot an?\b/i,
  /\boffering\b/i,
  /\bfree to\b/i,
  /\bgiving away\b/i,
  /\bgive away\b/i,
  /\bfree[:\s]/i,
]

const WANTED_PATTERNS = [
  /\blooking for\b/i,
  /\banyone got\b/i,
  /\bneed an?\b/i,
  /\bwanted[:\s]?\b/i,
  /\bdoes anyone have\b/i,
  /\bin search of\b/i,
]

export function classifyPost(text) {
  if (!text) return 'Discussion'

  // Check Wanted first — "anyone got" contains "got a" which would false-positive as Offer
  for (const pattern of WANTED_PATTERNS) {
    if (pattern.test(text)) return 'Wanted'
  }

  for (const pattern of OFFER_PATTERNS) {
    if (pattern.test(text)) return 'Offer'
  }

  return 'Discussion'
}
