import { describe, it, expect } from 'vitest'
import { classifyReviewBlockers } from '../actions/index'
import { reviewGroundingSection, type EvidenceEntry } from '../evidence'

// PRs #1664 and #1665 passed the evidence gate - they had made production reads - and
// were still guesses. #1665 read that almost every post ripples (context) and fixed the
// author's own digest when the reporter meant his members' digests. #1664's evidence
// was "no payment-failure events in the logs" (an absence). The reviewer now sees the
// reporter's words and the evidence record, and these findings close the PR.

const now = '2026-10-02T12:00:00Z'

describe('classifyReviewBlockers on grounding findings', () => {
  it.each([
    ['misread report', 'the PR fixes the author digest, the reporter meant other members'],
    ['ungrounded diagnosis', 'evidence is volumes and an absence of errors, not the failure'],
    ['false premise', 'the code already releases held replies when reach is done'],
  ])('treats %s as terminal, not something to expand', (category, description) => {
    const { terminal, allCompletable } = classifyReviewBlockers([{ category, description }])
    expect(terminal).toHaveLength(1)
    expect(allCompletable).toBe(false)
  })
})

describe('reviewGroundingSection', () => {
  const record: EvidenceEntry[] = [
    { ts: now, kind: 'db', available: true, source: 'prod', purpose: 'share rippled', query: 'SELECT COUNT(*) c FROM messages_groups', rowCount: 1, result: { columns: ['c'], rows: [['25000']] } },
    { ts: now, kind: 'loki', available: true, source: 'prod', query: '{app="apiv2"} |= "x"', rowCount: 0, result: { entries: [] } },
    { ts: now, kind: 'db', available: false, source: 'prod', query: 'SELECT bad', result: { reason: 'query failed' } },
    { ts: now, kind: 'note', text: 'Nearly every post ripples so the cap bites.' },
  ]

  it('gives the reviewer the reporter words, each read with what it returned, and the note', () => {
    const s = reviewGroundingSection('My post was unseen by most of my local Freeglers.', record)
    expect(s).toContain('unseen by most of my local Freeglers')
    expect(s).toContain('SELECT COUNT(*) c FROM messages_groups')
    expect(s).toContain('25000')
    expect(s).toContain('0 rows')
    expect(s).toContain('Nearly every post ripples')
    expect(s).toMatch(/failed/)
  })

  it('says plainly when there is no evidence record', () => {
    expect(reviewGroundingSection('x', [])).toMatch(/no production reads/i)
  })

  it('stays bounded however large the results are', () => {
    const big: EvidenceEntry[] = Array.from({ length: 40 }, () => ({
      ts: now, kind: 'loki', available: true, source: 'prod', query: '{app="freegle"}', rowCount: 100,
      result: { entries: Array.from({ length: 100 }, () => ({ ts: now, line: 'x'.repeat(500) })) },
    }))
    expect(reviewGroundingSection('x', big).length).toBeLessThan(12000)
  })
})
