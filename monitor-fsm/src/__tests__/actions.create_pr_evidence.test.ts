import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { getDb, resetDbForTests, upsertDiscourseBug, getDiscourseBug } from '../db/index.js'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Handler = (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

let createPr: Handler
let db: ReturnType<typeof getDb>
let ghCalls: string[][]
let posted: Array<{ topic: number; raw: string }>
let prBody: string

const GROUNDED = '## Root Cause\nx\n\n## Live evidence\nQuery: SELECT COUNT(*) FROM messages WHERE id > 1\nResult: 12 rows.\n\n## Evidence\ntest\n'
const UNGROUNDED = '## Root Cause\nx\n\n## Evidence\nA test reproduces it. I did not check live data in this run.\n'

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
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
