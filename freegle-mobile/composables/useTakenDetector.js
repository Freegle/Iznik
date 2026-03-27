// Positive patterns — strong signals that a handover happened
const TAKEN_PATTERNS = [
  /\bcollected\b/i,
  /\bpicked up\b/i,
  /\ball done\b/i,
  /\bthanks for the\b/i,
]

// Weaker signals — only match if they appear to be standalone gratitude
const GRATITUDE_PATTERNS = [
  /^(got it|thank you!?)$/i,
  /\bgot it,?\s*thank/i,
]

// Negative patterns — override positive matches
const FALSE_POSITIVE_PATTERNS = [
  /\bcan i collect\b/i,
  /\bcollect (tomorrow|today|on|at|from)\b/i,
  /\bgot your (message|email|reply)\b/i,
  /\bthanks for (getting|replying|your|letting)\b/i,
]

export function detectTaken(text) {
  if (!text) return false

  for (const pattern of FALSE_POSITIVE_PATTERNS) {
    if (pattern.test(text)) return false
  }

  for (const pattern of TAKEN_PATTERNS) {
    if (pattern.test(text)) return true
  }

  for (const pattern of GRATITUDE_PATTERNS) {
    if (pattern.test(text)) return true
  }

  return false
}
