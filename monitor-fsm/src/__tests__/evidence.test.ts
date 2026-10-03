import { describe, it, expect, beforeEach } from 'vitest'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
  assessPrEvidence, liveEvidenceSection, evidenceLine, identifyingValues,
  appendEvidence, readEvidence, parseBugRef, type EvidenceEntry,
} from '../evidence.js'

beforeEach(() => {
  process.env.MONITOR_FSM_EVIDENCE_DIR = mkdtempSync(join(tmpdir(), 'evidence-'))
})

const now = '2026-10-02T12:00:00Z'
const dbRead = (result: unknown): EvidenceEntry => ({ ts: now, kind: 'db', query: 'SELECT ...', available: true, source: 'prod', result })
const lokiRead = (lines: string[], source = 'prod'): EvidenceEntry => ({
  ts: now, kind: 'loki', query: '{app="freegle"}', available: true, source,
  result: { available: true, source, entries: lines.map(line => ({ ts: now, line })) },
})
const note: EvidenceEntry = { ts: now, kind: 'note', text: 'Every failed confirm call is a wallet cancel, which the parent treats as no wallets.' }
const GROUNDED: EvidenceEntry[] = [dbRead({ columns: ['n'], rows: [['12']] }), note]

const body = (live: string, extra = '') =>
  `## Root Cause\nThe thing.\n\n## Live evidence\n${live}\n\n## Evidence\nfailing test\n\n## Fix\nx${extra}\n`
const good = (record = GROUNDED, extra = '') => body(evidenceLine(9600, 2, record)!, extra)

describe('assessPrEvidence', () => {
  it('accepts a PR whose record has production reads and a note, and whose section is the line', () => {
    expect(assessPrEvidence(good(), 9600, 2, GROUNDED)).toEqual({ ok: true, problems: [], confidential: false })
  })

  // PRs #1654 #1657 #1658 #1659: a test from their own hypothesis, nothing from production.
  it('refuses a PR with no production read on record, whatever the description says', () => {
    const r = assessPrEvidence(body('Query: SELECT COUNT(*) FROM x\nResult: 12 rows.'), 9600, 2, [])
    expect(r.ok).toBe(false)
    expect(r.problems.join(' ')).toMatch(/no record of any production read/)
  })

  it('does not count the local dev Loki as production', () => {
    const r = assessPrEvidence(body('x'), 9600, 2, [lokiRead(['{}'], 'local-dev'), note])
    expect(r.ok).toBe(false)
  })

  it('does not count a read that failed', () => {
    const r = assessPrEvidence(body('x'), 9600, 2, [{ ts: now, kind: 'db', available: false, source: 'prod' }, note])
    expect(r.ok).toBe(false)
  })

  it('needs a note of what the reads showed', () => {
    const r = assessPrEvidence(body('x'), 9600, 2, [GROUNDED[0]])
    expect(r.problems.join(' ')).toMatch(/no note/)
  })

  it('refuses queries or results pasted into the Live evidence section', () => {
    const live = evidenceLine(9600, 2, GROUNDED) + '\nQuery: SELECT COUNT(*) FROM users\nResult: 12'
    const r = assessPrEvidence(body(live), 9600, 2, GROUNDED)
    expect(r.ok).toBe(false)
    expect(r.problems.join(' ')).toMatch(/exactly the line/)
  })

  it('refuses a description with no Live evidence section', () => {
    expect(assessPrEvidence('## Root Cause\nx\n', 9600, 2, GROUNDED).ok).toBe(false)
  })

  // The repository is public.
  it.each([
    ['an email address', 'jane.smith@gmail.com'],
    ['a full postcode', 'CB1 2AB'],
    ['a mobile number', '07700 900123'],
    ['an IP address', '81.2.69.142'],
  ])('refuses a description containing %s anywhere', (_what, pii) => {
    const r = assessPrEvidence(good(GROUNDED, `\nReporter is ${pii}.`), 9600, 2, GROUNDED)
    expect(r.ok).toBe(false)
    expect(r.confidential).toBe(true)
    expect(r.problems.join(' ')).not.toContain(pii)
  })

  // A name has no shape a pattern can see. What the check can see is the record: a
  // name the agent read from production and then repeated in the PR.
  it('refuses a description repeating a name from the production results', () => {
    const record = [dbRead({ columns: ['id', 'fullname'], rows: [['40959901', 'Morag Finchley']] }), note]
    const r = assessPrEvidence(good(record, '\nAffects Morag Finchley on Chrome.'), 9600, 2, record)
    expect(r.confidential).toBe(true)
    expect(r.problems.join(' ')).not.toContain('Morag')
  })

  it('refuses a description repeating a member id from the production results', () => {
    const record = [dbRead({ columns: ['userid', 'status'], rows: [['40959901', 'failed']] }), note]
    expect(assessPrEvidence(good(record, '\nUser 40959901 hit it.'), 9600, 2, record).confidential).toBe(true)
  })

  it('refuses a description repeating what a member wrote, found in a log line', () => {
    const record = [lokiRead([JSON.stringify({ user_id: 40959901, subject: 'OFFER: Blue armchair, Histon' })]), note]
    const r = assessPrEvidence(good(record, '\nThe post OFFER: Blue armchair, Histon failed.'), 9600, 2, record)
    expect(r.confidential).toBe(true)
  })

  it('is not tripped by a short name inside an ordinary word', () => {
    const record = [dbRead({ columns: ['firstname'], rows: [['Sam']] }), note]
    expect(assessPrEvidence(good(record, '\nSample data only.'), 9600, 2, record).ok).toBe(true)
  })

  it('does not count a postcode district or example address as personal data', () => {
    const r = assessPrEvidence(good(GROUNDED, '\nAll in CB1. Test uses test@example.org and 127.0.0.1.'), 9600, 2, GROUNDED)
    expect(r.ok).toBe(true)
  })
})

describe('identifyingValues', () => {
  it('picks out names, emails and ids but not counts or statuses', () => {
    const v = identifyingValues([
      dbRead({ columns: ['userid', 'email', 'status', 'n'], rows: [['40959901', 'a.b@gmail.com', 'held', '412']] }),
    ])
    expect(v).toEqual(expect.arrayContaining(['40959901', 'a.b@gmail.com']))
    expect(v).not.toContain('held')
    expect(v).not.toContain('412')
  })
})

describe('the evidence record', () => {
  it('round-trips through the local file, keyed by bug', () => {
    appendEvidence(9600, 2, { kind: 'note', text: 'hello there, this is long enough' })
    expect(readEvidence(9600, 2)).toHaveLength(1)
    expect(readEvidence(9600, 3)).toEqual([])
  })

  it('will not print an evidence line for a record that would be refused', () => {
    expect(evidenceLine(9600, 2, [note])).toBeNull()
    expect(evidenceLine(9600, 2, GROUNDED)).toMatch(/1 database and 0 log/)
  })

  it('reads a bug reference either way round people write it', () => {
    expect(parseBugRef('10202/1')).toEqual({ topic: 10202, post: 1 })
    expect(parseBugRef('10202.1')).toEqual({ topic: 10202, post: 1 })
    expect(parseBugRef('nope')).toBeNull()
  })
})

describe('liveEvidenceSection', () => {
  it('stops at the next heading', () => {
    expect(liveEvidenceSection(body('one line'))).toBe('one line')
  })
  it('is null when there is no such section', () => {
    expect(liveEvidenceSection('## Evidence\nx')).toBeNull()
  })
})
