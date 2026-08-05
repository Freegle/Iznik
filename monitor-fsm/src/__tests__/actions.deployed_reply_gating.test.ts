import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { getDb, resetDbForTests, upsertDiscourseBug, upsertPr } from '../db/index.js'
import { classifyTouchedAreas, deployedReplyDeps } from '../actions/index.js'

/**
 * Reporter-facing "please retest" replies must only go out when the fix is in
 * something the reporter can actually retest — the deployed product (frontend /
 * Go / PHP). A tooling-only PR (monitor-fsm, docs, CI) has nothing to retest:
 * on 2026-08-04 the FSM told a reporter to retest a monitor-fsm-only fix
 * (topic 9982, PR #1239) and the reply had to be manually deleted.
 *
 * Also pinned here: a failed Discourse post must re-arm the retry by resetting
 * pr.deploy_state to 'pending_deploy'. The candidate query only selects
 * pending_deploy PRs, so leaving deploy_state='deployed' after a failed post
 * (as the code did before) orphaned the reply forever.
 */

describe('classifyTouchedAreas', () => {
  it('classifies each deployable area by path prefix', () => {
    expect(classifyTouchedAreas(['iznik-nuxt3/components/Foo.vue'])).toEqual(
      { frontend: true, go: false, php: false, deployable: true })
    expect(classifyTouchedAreas(['iznik-server-go/messages/messages.go'])).toEqual(
      { frontend: false, go: true, php: false, deployable: true })
    expect(classifyTouchedAreas(['iznik-batch/app/Jobs/Digest.php'])).toEqual(
      { frontend: false, go: false, php: true, deployable: true })
    expect(classifyTouchedAreas(['iznik-server/include/user/User.php'])).toEqual(
      { frontend: false, go: false, php: true, deployable: true })
  })

  it('reports tooling-only changes as not deployable', () => {
    expect(classifyTouchedAreas([
      'monitor-fsm/src/actions/index.ts',
      'monitor-fsm/src/__tests__/actions.persist_classifications.test.ts',
      'docs/developers/reference/coding-standards.md',
      '.circleci/orb/freegle-tests.yml',
    ])).toEqual({ frontend: false, go: false, php: false, deployable: false })
  })

  it('an empty path list is not deployable', () => {
    expect(classifyTouchedAreas([])).toEqual(
      { frontend: false, go: false, php: false, deployable: false })
  })
})

// --- queue_deployed_reply_drafts handler, via the deployedReplyDeps seam ---

type Handler = (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

const originalDeps = { ...deployedReplyDeps }

function fakeDeployResult(overrides: Partial<Awaited<ReturnType<typeof originalDeps.checkPrDeployed>>> = {}) {
  return {
    deployed: true,
    frontendDeployed: null,
    backendDeployed: true,
    mergeCommitSha: 'abc1234',
    productionSha: null,
    netlifyCommitSha: null,
    touched: { frontend: false, go: true, php: false },
    filesKnown: true,
    reason: 'test',
    ...overrides,
  }
}

describe('queue_deployed_reply_drafts', () => {
  let db: ReturnType<typeof getDb>
  let handler: Handler
  let postedCalls: Array<{ topic: number; raw: string; replyTo?: number }>

  beforeEach(async () => {
    resetDbForTests()
    db = getDb(':memory:')
    const { actions } = await import('../actions/index.js')
    handler = actions.find((a) => a.name === 'queue_deployed_reply_drafts')!.handler as Handler

    postedCalls = []
    deployedReplyDeps.postDiscourseReply = async (topic, raw, replyTo) => {
      postedCalls.push({ topic, raw, replyTo })
      return { ok: true }
    }
    deployedReplyDeps.fetchReporterQuote = async () => 'the reporter’s exact words'
    deployedReplyDeps.renderAllViews = async () => {}

    upsertPr(db, { number: 500, title: 'fix(x): something', state: 'MERGED', deployState: 'pending_deploy' })
    upsertDiscourseBug(db, { topic: 9001, post: 3, reporter: 'Alice', state: 'fix-queued', prNumber: 500 })
  })

  afterEach(() => {
    Object.assign(deployedReplyDeps, originalDeps)
    resetDbForTests()
  })

  it('skips the reporter reply for a tooling-only PR but still marks it deployed', async () => {
    deployedReplyDeps.checkPrDeployed = async () => fakeDeployResult({
      touched: { frontend: false, go: false, php: false },
      reason: 'no deployable (frontend/Go/PHP) files touched — nothing to verify',
    })

    const result = await handler({}, {})

    expect(result.skippedToolingOnly).toEqual([500])
    expect(result.posted).toEqual([])
    expect(postedCalls).toEqual([])
    const pr = db.prepare('SELECT deploy_state FROM pr WHERE number = 500').get() as any
    expect(pr.deploy_state).toBe('deployed')
    expect(db.prepare('SELECT id FROM discourse_draft WHERE topic = 9001 AND post = 3').get()).toBeUndefined()
  })

  it('still posts when the file list could not be fetched (never suppress on missing data)', async () => {
    deployedReplyDeps.checkPrDeployed = async () => fakeDeployResult({
      touched: { frontend: false, go: false, php: false },
      filesKnown: false,
    })

    const result = await handler({}, {})

    expect(result.posted).toEqual([500])
    expect(result.skippedToolingOnly ?? []).toEqual([])
    expect(postedCalls).toHaveLength(1)
    expect(postedCalls[0].topic).toBe(9001)
    expect(postedCalls[0].replyTo).toBe(3)
  })

  it('re-arms the retry when the Discourse post fails, then succeeds next run', async () => {
    deployedReplyDeps.checkPrDeployed = async () => fakeDeployResult()
    deployedReplyDeps.postDiscourseReply = async () => ({ ok: false, error: 'HTTP 429: rate_limit' })

    const first = await handler({}, {})
    expect(first.postFailed).toEqual([500])
    expect(first.posted).toEqual([])
    // deploy_state must be back to pending_deploy so the next iteration's
    // candidate query picks this bug up again.
    let pr = db.prepare('SELECT deploy_state FROM pr WHERE number = 500').get() as any
    expect(pr.deploy_state).toBe('pending_deploy')
    expect(db.prepare('SELECT id FROM discourse_draft WHERE topic = 9001 AND post = 3').get()).toBeUndefined()

    // Next iteration: Discourse recovered.
    deployedReplyDeps.postDiscourseReply = async (topic, raw, replyTo) => {
      postedCalls.push({ topic, raw, replyTo })
      return { ok: true }
    }
    const second = await handler({}, {})
    expect(second.posted).toEqual([500])
    expect(postedCalls).toHaveLength(1)
    pr = db.prepare('SELECT deploy_state FROM pr WHERE number = 500').get() as any
    expect(pr.deploy_state).toBe('deployed')
    expect(db.prepare('SELECT id FROM discourse_draft WHERE topic = 9001 AND post = 3').get()).toBeDefined()
  })

  it('keeps the PR pending when one of its bugs fails to post even if a later sibling succeeds', async () => {
    // Two bugs share PR 500. The first post fails, the second succeeds — the
    // PR must END the loop as pending_deploy (order must not matter), so the
    // failed reply is retried; the succeeded one is protected by draft dedup.
    upsertDiscourseBug(db, { topic: 9002, post: 1, reporter: 'Bob', state: 'fix-queued', prNumber: 500 })
    deployedReplyDeps.checkPrDeployed = async () => fakeDeployResult()
    deployedReplyDeps.postDiscourseReply = async (topic, raw, replyTo) => {
      if (topic === 9001) return { ok: false, error: 'HTTP 429: rate_limit' }
      postedCalls.push({ topic, raw, replyTo })
      return { ok: true }
    }

    const first = await handler({}, {})
    expect(first.postFailed).toEqual([500])
    expect(first.posted).toEqual([500])
    const pr = db.prepare('SELECT deploy_state FROM pr WHERE number = 500').get() as any
    expect(pr.deploy_state).toBe('pending_deploy')

    // Next iteration: both bugs reselected; 9002 dedups, 9001 posts.
    deployedReplyDeps.postDiscourseReply = async (topic, raw, replyTo) => {
      postedCalls.push({ topic, raw, replyTo })
      return { ok: true }
    }
    const second = await handler({}, {})
    expect(second.posted).toEqual([500])
    expect(second.alreadyPosted).toEqual([500])
    expect(postedCalls.map((c) => c.topic).sort()).toEqual([9001, 9002])
    const prAfter = db.prepare('SELECT deploy_state FROM pr WHERE number = 500').get() as any
    expect(prAfter.deploy_state).toBe('deployed')
  })

  it('posts normally for a deployable PR, quoting via the seam', async () => {
    deployedReplyDeps.checkPrDeployed = async () => fakeDeployResult({
      touched: { frontend: true, go: false, php: false },
    })

    const result = await handler({}, {})

    expect(result.posted).toEqual([500])
    expect(postedCalls).toHaveLength(1)
    // Frontend-touching fix → app-release caveat present.
    expect(postedCalls[0].raw).toContain('app releases may take up to one week')
    expect(postedCalls[0].raw).toContain('the reporter’s exact words')
  })
})
