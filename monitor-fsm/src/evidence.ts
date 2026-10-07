// Has a bug-fix PR been checked against production, and does its description keep
// members' details out of a public repository?
//
// PRs #1654, #1657, #1658 and #1659 were all closed as guesses. Each carried a test
// written from its own hypothesis and nothing from production. Asking for grounding
// in a prompt was skipped exactly when the agent was most sure of itself, so it is
// now something the driver checks.
//
// The evidence itself never goes in the PR. Production results are full of members'
// details - names, emails, addresses, IPs, what they wrote - and the repository is
// public. ground.js records every production read a fix agent makes, with its
// result, in a local evidence record that is never published. The PR description
// carries one line saying the record exists and what it holds. create_pr then
// confirms against the record, not against the agent's say-so: the reads happened,
// they were against production, and the agent wrote down what they showed.
//
// Like specifics.ts this is deliberately code rather than a model's opinion, so the
// same PR always gets the same answer.
import { appendFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

/** One production read, or the agent's note of what the reads showed. */
export interface EvidenceEntry {
  ts: string
  kind: 'db' | 'loki' | 'note'
  query?: string
  purpose?: string
  available?: boolean
  source?: string
  rowCount?: number
  /** The raw result, kept locally so a human can audit the diagnosis. */
  result?: unknown
  text?: string
}

export function evidenceDir(): string {
  return process.env.MONITOR_FSM_EVIDENCE_DIR || '/tmp/freegle-monitor/evidence'
}

export function parseBugRef(ref: string): { topic: number; post: number } | null {
  const m = /^(\d+)[/.-](\d+)$/.exec(String(ref ?? '').trim())
  return m ? { topic: Number(m[1]), post: Number(m[2]) } : null
}

function recordPath(topic: number, post: number): string {
  return join(evidenceDir(), `${topic}-${post}.jsonl`)
}

export function appendEvidence(topic: number, post: number, entry: Omit<EvidenceEntry, 'ts'>): void {
  mkdirSync(evidenceDir(), { recursive: true })
  appendFileSync(recordPath(topic, post), JSON.stringify({ ts: new Date().toISOString(), ...entry }) + '\n')
}

export function readEvidence(topic: number, post: number): EvidenceEntry[] {
  const p = recordPath(topic, post)
  if (!existsSync(p)) return []
  return readFileSync(p, 'utf8').split('\n').filter(Boolean).flatMap(l => {
    try { return [JSON.parse(l) as EvidenceEntry] } catch { return [] }
  })
}

/** Production reads that returned something: local-dev Loki says nothing about members. */
function productionReads(record: EvidenceEntry[]): EvidenceEntry[] {
  return record.filter(e => (e.kind === 'db' || e.kind === 'loki') && e.available === true && e.source !== 'local-dev')
}

const hasNote = (record: EvidenceEntry[]) => record.some(e => e.kind === 'note' && String(e.text ?? '').trim().length >= 20)

/**
 * The one line a PR description carries about its evidence. Null when the record
 * would not pass, so an agent cannot print a line it has not earned.
 */
export function evidenceLine(topic: number, post: number, record = readEvidence(topic, post)): string | null {
  const reads = productionReads(record)
  if (reads.length === 0 || !hasNote(record)) return null
  const db = reads.filter(e => e.kind === 'db').length
  const logs = reads.filter(e => e.kind === 'loki').length
  return `Checked against production before this PR was opened: ${db} database and ${logs} log read(s). ` +
    `The results and what they showed are kept in the monitor's local evidence record ${topic}/${post}, not published here.`
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

// Members' details in any text. Each pattern names a kind, so a refusal can say what
// it found without repeating it.
const PERSONAL: Array<[string, RegExp]> = [
  ['an email address', /[\w.+-]+@(?!example\.(?:com|org)\b)(?!users\.noreply\.github\.com\b)(?!anthropic\.com\b)[\w-]+(?:\.[\w-]+)+/i],
  ['a full postcode', /\b[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}\b/],
  ['a phone number', /(?:\+44\s?|\b0)7\d{3}\s?\d{3}\s?\d{3}\b|\b0[1-3]\d{2,3}\s?\d{3}\s?\d{3,4}\b/],
  ['an IP address', /\b(?!127\.0\.0\.1\b)(?!0\.0\.0\.0\b)(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}\b/],
]

// Fields whose value identifies a member or is something they wrote.
const IDENTIFYING_FIELD = /^(?:.*name|e?mail|email.*|username|display.*|fullname|firstname|lastname|subject|textbody|body|message|text|address.*|postcode|phone.*|ip|ipaddress|realname|comment.*|location.*)$/i

// Fields holding an id that points at a member or something they posted.
const ID_FIELD = /^(?:id|userid|user_id|fromuser|touser|byuser|msgid|messageid|message_id|chatid|chat_id|refmsgid|refchatid|uid)$/i

/**
 * Values in the record's results that would identify a member if they turned up in
 * the PR: anything in an identifying column or log field, and anything shaped like
 * personal data wherever it sits.
 */
export function identifyingValues(record: EvidenceEntry[]): string[] {
  const out = new Set<string>()
  const consider = (v: unknown, field?: string) => {
    if (v === null || v === undefined || typeof v === 'object') return
    const s = String(v).trim()
    if (field && ID_FIELD.test(field) && /^\d{5,}$/.test(s)) { out.add(s); return }
    if (s.length < 3 || !/[a-z]/i.test(s)) return
    if (field && IDENTIFYING_FIELD.test(field)) out.add(s)
    for (const [, re] of PERSONAL) {
      const m = new RegExp(re.source, re.flags.includes('g') ? re.flags : re.flags + 'g')
      for (const hit of s.match(m) ?? []) out.add(hit)
    }
  }
  const walk = (v: unknown, field?: string) => {
    if (typeof v === 'string' && /^\s*\{/.test(v)) {
      try { walk(JSON.parse(v)); return } catch { /* not JSON */ }
    }
    if (Array.isArray(v)) { v.forEach(x => walk(x, field)); return }
    if (v && typeof v === 'object') { for (const [k, x] of Object.entries(v)) walk(x, k); return }
    consider(v, field)
  }
  for (const e of productionReads(record)) {
    const r = e.result as any
    if (r && Array.isArray(r.columns) && Array.isArray(r.rows)) {
      for (const row of r.rows) (row as unknown[]).forEach((cell, i) => walk(cell, String(r.columns[i] ?? '')))
    } else if (r && Array.isArray(r.entries)) {
      for (const en of r.entries) walk(en.line)
    } else {
      walk(r)
    }
  }
  return [...out]
}

export interface PrEvidence {
  ok: boolean
  /** Plain-English reasons, never quoting the offending text. */
  problems: string[]
  /** True when the description contains a member's details. */
  confidential: boolean
}

export function assessPrEvidence(body: string, topic: number, post: number, record = readEvidence(topic, post)): PrEvidence {
  const text = body ?? ''
  const problems: string[] = []

  if (productionReads(record).length === 0) {
    problems.push(`there is no record of any production read for ${topic}/${post} (run them through ground.js with --bug ${topic}/${post})`)
  } else if (!hasNote(record)) {
    problems.push(`the evidence record for ${topic}/${post} has no note of what the production reads showed`)
  }

  const live = liveEvidenceSection(text)
  const expected = evidenceLine(topic, post, record)
  if (live === null) {
    problems.push('there is no Live evidence section')
  } else if (expected && live !== expected) {
    problems.push('the Live evidence section must be exactly the line ground.js evidence-line prints, with no queries or results')
  }

  const kinds = PERSONAL.filter(([, re]) => re.test(text)).map(([kind]) => kind)
  // Whole words only, so a member called Sam does not trip on "Sample".
  const leaked = identifyingValues(record).some(v =>
    new RegExp(`(?<![\\w@.])${v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w@])`, 'i').test(text))
  if (leaked) kinds.push('a detail taken from the production results')
  if (kinds.length > 0) {
    problems.push(`the description contains ${kinds.join(', ')}; this repository is public`)
  }

  return { ok: problems.length === 0, problems, confidential: kinds.length > 0 }
}

/**
 * What the adversarial reviewer is shown about a Discourse bug fix: the reporter's own
 * words and the evidence record. The gate above proves reads were made; it cannot tell
 * whether they show the reported failure or only its surroundings. PR #1665 read that
 * nearly every post ripples and fixed the wrong digest; #1664's evidence was an absence
 * of errors. The reviewer judges that, with this in front of it. This goes to the
 * review model only, never to GitHub.
 */
export function reviewGroundingSection(reporterWords: string, record: EvidenceEntry[]): string {
  const MAX_RESULT = 600
  const MAX_TOTAL = 9000
  const lines: string[] = []
  lines.push('WHAT THE REPORTER WROTE:', (reporterWords || '(could not be fetched)').trim().slice(0, 2500), '')
  if (record.length === 0) {
    lines.push('EVIDENCE RECORD: no production reads were recorded for this report.')
    return lines.join('\n')
  }
  lines.push('EVIDENCE RECORD (the production reads the fix agent made, and its notes):')
  for (const e of record) {
    if (e.kind === 'note') {
      lines.push(`- NOTE: ${String(e.text ?? '').slice(0, 800)}`)
      continue
    }
    const status = e.available !== true ? 'failed' : e.source === 'local-dev' ? 'local-dev, not production' : `${e.rowCount ?? 0} rows`
    const r = e.result as any
    const shown = r?.columns && r?.rows ? { columns: r.columns, rows: r.rows.slice(0, 10) }
      : r?.entries ? r.entries.slice(0, 5).map((x: any) => x.line)
      : r
    lines.push(`- ${e.kind.toUpperCase()} (${status})${e.purpose ? ` for "${e.purpose}"` : ''}: ${String(e.query ?? '').slice(0, 400)}`)
    lines.push(`  returned: ${JSON.stringify(shown ?? null).slice(0, MAX_RESULT)}`)
  }
  const out = lines.join('\n')
  return out.length > MAX_TOTAL ? out.slice(0, MAX_TOTAL) + '\n(evidence truncated)' : out
}
