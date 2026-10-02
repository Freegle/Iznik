// Has a bug-fix PR shown that its diagnosis matches production, and does its
// description keep members' details out of a public repository?
//
// PRs #1654, #1657, #1658 and #1659 were all closed as guesses. Each carried a test
// written from its own hypothesis and nothing from production; two said so in their
// own description ("I did not check live data", "could not be reproduced"). The
// grounding tools existed, but using them was a request in a prompt, and a request
// is skipped exactly when the agent is most sure of itself. This makes it a rule the
// driver checks: no concrete artefact from production, no PR.
//
// Like specifics.ts, this is deliberately regular expressions rather than a model's
// opinion, so the same description always gets the same answer.

export interface PrEvidence {
  ok: boolean
  /** Plain-English reasons, never quoting the offending text. */
  problems: string[]
  /** True when the description itself contains a member's details. */
  confidential: boolean
}

const HEADING = /^##\s+Live evidence\s*$/im

/** The body of the "## Live evidence" section, trimmed, or null if there is none. */
export function liveEvidenceSection(body: string): string | null {
  const m = HEADING.exec(body)
  if (!m) return null
  const rest = body.slice(m.index + m[0].length)
  const next = rest.search(/^##\s/m)
  return (next === -1 ? rest : rest.slice(0, next)).trim()
}

// The section admitting that nothing was checked.
const DISCLAIMER = /\b(?:did not|didn't|could not|couldn't|was not|wasn't|were not|not) (?:check|checked|query|queried|reproduce|reproduced|look|looked|verified|confirm|confirmed)\b|\bunavailable\b|\bungrounded\b|\bnot grounded\b|\binferred\b|^\s*(?:n\/a|none|-)\s*\.?\s*$/im

// Concrete artefacts. A query only counts with what it returned beside it.
const SQL = /\bselect\b[\s\S]{1,600}?\bfrom\b/i
const LOGQL = /\{\s*[a-z_]+\s*=~?\s*"[^"]+"/
const RESULT = /^\s*result\s*:/im
const SENTRY = /\bsentry\b[^\n]{0,40}?\b\d{6,}\b|\bsentry\.io\/\S+/i
const SCREENSHOT = /!\[[^\]]*\]\([^)]+\)|upload:\/\//

// Members' details. Each pattern names a kind, so a refusal can say what it found
// without repeating it.
const PERSONAL: Array<[string, RegExp]> = [
  ['an email address', /[\w.+-]+@(?!example\.(?:com|org)\b)(?!users\.noreply\.github\.com\b)(?!anthropic\.com\b)[\w-]+(?:\.[\w-]+)+/i],
  ['a full postcode', /\b[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}\b/],
  ['a phone number', /(?:\+44\s?|\b0)7\d{3}\s?\d{3}\s?\d{3}\b|\b0[1-3]\d{2,3}\s?\d{3}\s?\d{3,4}\b/],
  ['an IP address', /\b(?!127\.0\.0\.1\b)(?!0\.0\.0\.0\b)(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}\b/],
]

export function assessPrEvidence(body: string): PrEvidence {
  const text = body ?? ''
  const problems: string[] = []

  const live = liveEvidenceSection(text)
  if (live === null || live === '') {
    problems.push('there is no Live evidence section showing what production says')
  } else if (DISCLAIMER.test(live)) {
    problems.push('the Live evidence section says production was not checked')
  } else {
    const query = SQL.test(live) || LOGQL.test(live)
    const artefact = (query && RESULT.test(live)) || SENTRY.test(live) || SCREENSHOT.test(live)
    if (!artefact) {
      problems.push(query
        ? 'the Live evidence query has no "Result:" line saying what it returned'
        : 'the Live evidence section needs a query with its result, a Sentry issue, or the reporter\'s screenshot')
    }
  }

  const found = PERSONAL.filter(([, re]) => re.test(text)).map(([kind]) => kind)
  if (found.length > 0) {
    problems.push(`the description contains ${found.join(', ')}; this repository is public`)
  }

  return { ok: problems.length === 0, problems, confidential: found.length > 0 }
}
