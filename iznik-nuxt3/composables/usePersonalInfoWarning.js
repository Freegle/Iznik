import { computed } from 'vue'

// UK phone number pattern matching the server-side ContentCheckService.
// Requires a proper UK prefix (0, +44, or 0044) followed by 9–10 digits
// (with optional spaces/hyphens). This mirrors the PHP regex:
//   /(?<!\d)(?:(?:\+44|0044)\s?|0)(?:\d[\s\-]?){9,10}(?!\d)/
const UK_PHONE_RE = /(?<!\d)(?:(?:\+44|0044)\s?|0)(?:\d[\s-]?){9,10}(?!\d)/

// External email address pattern (freegle/trashnothing addresses are excluded).
const EMAIL_RE = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/

const FREEGLE_EMAIL_DOMAINS = [
  'ilovefreegle.org',
  'trashnothing',
  'yahoogroups',
]

export function detectPersonalInfo(text) {
  if (!text) return { hasPhone: false, hasEmail: false }

  const hasPhone = UK_PHONE_RE.test(text)

  let hasEmail = false
  const emailMatch = text.match(EMAIL_RE)
  if (emailMatch) {
    const email = emailMatch[0].toLowerCase()
    const isOurs = FREEGLE_EMAIL_DOMAINS.some((d) => email.includes(d))
    hasEmail = !isOurs
  }

  return { hasPhone, hasEmail }
}

export function groupRestrictsPersonalInfo(group) {
  if (!group) return false
  let rules = group.rules
  if (!rules) return false
  if (typeof rules === 'string') {
    try {
      rules = JSON.parse(rules)
    } catch {
      return false
    }
  }
  return !!rules.restrictpersonalinfo
}

export function usePersonalInfoWarning(groupRef, textRef) {
  return computed(() => {
    if (!groupRestrictsPersonalInfo(groupRef.value)) return false
    const { hasPhone, hasEmail } = detectPersonalInfo(textRef.value)
    return hasPhone || hasEmail
  })
}
