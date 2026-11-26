import { loadConfig } from '../src/config.mjs'
import { openDb } from '../src/db.mjs'
import { createPipeline } from '../src/pipeline.mjs'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
export { makePost, outcome } from '../eval/run.mjs'

export function testConfig(over = {}) {
  return { ...loadConfig({ FRONTLINE_MODE: 'live', FRONTLINE_BOT_USERNAME: 'helper', FRONTLINE_STAFF: 'dev_sam', FRONTLINE_STATE_DIR: mkdtempSync(join(tmpdir(), 'frontline-')) }), ...over }
}

/** The form a model would return, with the fields a test cares about overridden. */
export function form(over = {}) {
  return {
    kind: 'bug', summary: 'Approving a pending message spins and never finishes.', area: 'ModTools pending messages',
    has_actual: true, has_expected: true, has_steps: true, has_platform: true, has_when: true,
    extra_question: null, same_as: null, followup_type: 'none', answer: null, answer_sources: [],
    urgency: 'normal', confidence: 'high', ...over,
  }
}

/** A stand-in forum that records what was posted to it. */
export function fakeForum(over = {}) {
  const sent = []
  return {
    sent,
    reply: async (topicId, raw, replyTo) => {
      sent.push({ topicId, raw, replyTo })
      return 9000 + sent.length
    },
    post: async () => ({ version: 0 }),
    postsAfter: async () => [],
    latestPostId: async () => 0,
    ...over,
  }
}

/** A service with a scripted model: each call takes the next form (or function) in line. */
export function harness({ forms = [], cfg = testConfig(), forum = fakeForum(), now } = {}) {
  const db = openDb(':memory:')
  const calls = []
  const ask = async (message) => {
    calls.push(message)
    const next = forms.shift()
    if (next === undefined) throw new Error('the model was called more often than the test expected')
    if (next instanceof Error) throw next
    return { form: typeof next === 'function' ? next(message) : next, usage: { model: 'fake', input: 100, cacheRead: 900, cacheWrite: 0, output: 50 } }
  }
  const pipeline = createPipeline({ db, cfg, discourse: forum, ask, now })
  const lapState = () => ({ read: 0, byRule: 0, byModel: 0, errors: 0, sent: 0, replies: {} })
  return { db, cfg, forum, calls, pipeline, lapState }
}
