import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { getDb, resetDbForTests, upsertDiscourseBug, getDiscourseBug } from '../db/index.js'
import { appendEvidence, evidenceLine } from '../evidence.js'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Handler = (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

let createPr: Handler
let db: ReturnType<typeof getDb>
let ghCalls: string[][]
let posted: Array<{ topic: number; raw: string }>
let prBody: string

let GROUNDED = ''
const UNGROUNDED = '## Root Cause\nx\n\n## Evidence\nA test reproduces it. I did not check live data in this run.\n'

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  process.env.MONITOR_FSM_EVIDENCE_DIR = mkdtempSync(join(tmpdir(), 'cpr-ev-'))
  appendEvidence(9600, 2, { kind: 'db', available: true, source: 'prod', result: { columns: ['n'], rows: [['12']] } })
  appendEvidence(9600, 2, { kind: 'note', text: 'Production shows the own post is never in the window query.' })
  GROUNDED = `## Root Cause\nx\n\n## Live evidence\n${evidenceLine(9600, 2)}\n\n## Evidence\ntest\n`
  const mod = await import('../actions/index.js')
  ghCalls = []
  posted = []
  prBody = GROUNDED
  vi.spyOn(mod.prGateDeps, 'gh').mockImplementation(async (args: string[]) => {
    ghCalls.push(args)
    if (args[0] === 'pr' && args[1] === 'view') {
      return { code: 0, stderr: '', stdout: JSON.stringify({
        number: 1700, title: 'fix(x): y', url: 'u', author: {}, headRefName: 'fix/x-9600-2',
        files: [{ path: 'iznik-batch/app/X.php' }], body: prBody,
      }) }
    }
    return { code: 0, stderr: '', stdout: '' }
  })
  vi.spyOn(mod.questionAnswerDeps, 'postDiscourseReply').mockImplementation(async (topic: number, raw: string) => {
    posted.push({ topic, raw })
    return { ok: true }
  })
  vi.spyOn(mod.questionAnswerDeps, 'fetchReporterQuote').mockResolvedValue('The digest is missing my post.')
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  createPr = (mod.actions.find((a: any) => a.name === 'create_pr')!).handler
  upsertDiscourseBug(db, { topic: 9600, post: 2, reporter: 'Sam', excerpt: 'The digest is missing my post', state: 'open' })
})

afterEach(() => {
  vi.restoreAllMocks()
  resetDbForTests()
})

const closed = () => ghCalls.filter(a => a[0] === 'pr' && a[1] === 'close')

describe('create_pr live-evidence gate', () => {
  it('records a grounded bug-fix PR as before', async () => {
    const r = await createPr({ prNumber: 1700, topic: 9600, post: 2 }, {})
    expect(r.verified).toBe(true)
    expect(closed()).toHaveLength(0)
    expect(getDiscourseBug(db, 9600, 2)?.state).toBe('fix-queued')
  })

  it('closes an ungrounded bug-fix PR and holds the report for detail', async () => {
    prBody = UNGROUNDED
    const r = await createPr({ prNumber: 1700, topic: 9600, post: 2 }, {})
    expect(r.verified).toBe(false)
    expect(r.refused).toBe(true)
    expect(closed()).toHaveLength(1)
    const bug = getDiscourseBug(db, 9600, 2)
    expect(bug?.state).toBe('needs-detail')
    expect(bug?.reason ?? '').toMatch(/1700/)
    expect(posted).toHaveLength(1)
  })

  it('closes a PR whose description looks right but has no production read on record', async () => {
    upsertDiscourseBug(db, { topic: 9601, post: 1, reporter: 'Sam', excerpt: 'Two members cannot donate', state: 'open' })
    prBody = GROUNDED.replace('9600/2', '9601/1')
    const r = await createPr({ prNumber: 1700, topic: 9601, post: 1 }, {})
    expect(r.refused).toBe(true)
    expect(getDiscourseBug(db, 9601, 1)?.state).toBe('needs-detail')
  })

  it('blanks a description that holds personal data before closing it', async () => {
    prBody = GROUNDED + '\nMember is jane.smith@gmail.com\n'
    const r = await createPr({ prNumber: 1700, topic: 9600, post: 2 }, {})
    expect(r.refused).toBe(true)
    const edit = ghCalls.find(a => a[0] === 'api' && a.includes('PATCH'))
    expect(edit).toBeDefined()
    expect(edit!.join(' ')).not.toContain('jane.smith')
    // Nothing the monitor writes back repeats it either.
    expect(ghCalls.flat().join(' ').split('jane.smith').length).toBeLessThanOrEqual(1)
    expect(closed()).toHaveLength(1)
  })

  it('leaves a PR with no Discourse bug alone (Sentry and CI fixes)', async () => {
    prBody = UNGROUNDED
    const r = await createPr({ prNumber: 1700 }, {})
    expect(r.verified).toBe(true)
    expect(closed()).toHaveLength(0)
  })
})

// #1664 was closed by a reviewer for lack of evidence, then reopened by the monitor's retry
// with no word about what had changed. A reopen is allowed, but it has to explain itself.
describe('create_pr on a reopened PR', () => {
  const timeline = (events: Array<{ event: string; created_at: string }>, comments: Array<{ created_at: string }>) => {
    return async (args: string[]) => {
      ghCalls.push(args)
      const path = args.find(a => a.startsWith('repos/')) ?? ''
      if (args[0] === 'pr' && args[1] === 'view') {
        return { code: 0, stderr: '', stdout: JSON.stringify({
          number: 1700, title: 't', url: 'u', author: {}, headRefName: 'fix/x', files: [{ path: 'a.php' }], body: prBody,
        }) }
      }
      if (path.endsWith('/events')) return { code: 0, stderr: '', stdout: JSON.stringify(events) }
      if (path.endsWith('/comments')) return { code: 0, stderr: '', stdout: JSON.stringify(comments) }
      if (path.endsWith('/commits')) return { code: 0, stderr: '', stdout: JSON.stringify([
        { commit: { message: 'fix(x): first attempt', committer: { date: '2026-10-02T10:00:00Z' } } },
        { commit: { message: 'fix(x): guard a repeat confirm\n\nbody', committer: { date: '2026-10-03T12:00:00Z' } } },
      ]) }
      return { code: 0, stderr: '', stdout: '' }
    }
  }
  const posted = () => ghCalls.filter(a => a[0] === 'pr' && a[1] === 'comment')

  it('explains a reopen nobody has commented on: what changed and the evidence', async () => {
    const mod = await import('../actions/index.js')
    vi.spyOn(mod.prGateDeps, 'gh').mockImplementation(timeline(
      [{ event: 'closed', created_at: '2026-10-02T20:00:00Z' }, { event: 'reopened', created_at: '2026-10-03T12:40:00Z' }],
      [{ created_at: '2026-10-02T20:00:00Z' }],
    ))
    await createPr({ prNumber: 1700, topic: 9600, post: 2 }, {})
    expect(posted()).toHaveLength(1)
    const body = posted()[0].join(' ')
    expect(body).toContain('guard a repeat confirm')
    expect(body).not.toContain('first attempt')
    expect(body).toMatch(/Checked against production/)
  })

  it('leaves a reopen alone once someone has explained it', async () => {
    const mod = await import('../actions/index.js')
    vi.spyOn(mod.prGateDeps, 'gh').mockImplementation(timeline(
      [{ event: 'closed', created_at: '2026-10-02T20:00:00Z' }, { event: 'reopened', created_at: '2026-10-03T12:40:00Z' }],
      [{ created_at: '2026-10-03T12:45:00Z' }],
    ))
    await createPr({ prNumber: 1700, topic: 9600, post: 2 }, {})
    expect(posted()).toHaveLength(0)
  })

  it('does nothing for a PR that was never closed', async () => {
    const mod = await import('../actions/index.js')
    vi.spyOn(mod.prGateDeps, 'gh').mockImplementation(timeline([], []))
    await createPr({ prNumber: 1700, topic: 9600, post: 2 }, {})
    expect(posted()).toHaveLength(0)
  })
})
