import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { writeFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { main } from '../ground.js'
import { appendEvidence, evidenceLine, readEvidence } from '../evidence.js'

beforeEach(() => {
  process.env.MONITOR_FSM_EVIDENCE_DIR = mkdtempSync(join(tmpdir(), 'ground-ev-'))
  vi.spyOn(console, 'log').mockImplementation(() => {})
  vi.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(() => { vi.restoreAllMocks() })

const file = (text: string) => {
  const p = join(mkdtempSync(join(tmpdir(), 'ground-')), 'body.md')
  writeFileSync(p, text)
  return p
}

describe('ground.js', () => {
  it('records a note against the bug', async () => {
    expect(await main(['note', '9600/2', 'Production shows every failure is a cancel.'])).toBe(0)
    expect(readEvidence(9600, 2)[0]).toMatchObject({ kind: 'note' })
  })

  it('refuses to print an evidence line before any production read', async () => {
    await main(['note', '9600/2', 'Production shows every failure is a cancel.'])
    expect(await main(['evidence-line', '9600/2'])).toBe(1)
  })

  it('check-pr passes a grounded description and fails a bare one', async () => {
    appendEvidence(9600, 2, { kind: 'db', available: true, source: 'prod', result: { columns: ['n'], rows: [['3']] } })
    appendEvidence(9600, 2, { kind: 'note', text: 'Production shows every failure is a cancel.' })
    const line = evidenceLine(9600, 2)!
    expect(await main(['check-pr', file(`## Live evidence\n${line}\n`), '9600/2'])).toBe(0)
    expect(await main(['check-pr', file('## Evidence\nA test.\n'), '9600/2'])).toBe(1)
  })

  it('exits 2 and prints usage for an unknown command', async () => {
    expect(await main(['nope'])).toBe(2)
  })
})
