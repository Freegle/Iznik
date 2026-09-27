import { describe, it, expect } from 'vitest'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { BUG_FEEDBACK_CLASSIFY_PY, discourseFetchPy } from '../actions/index'

const exec = promisify(execFile)

/**
 * check_bug_feedback's phrase rules.
 *
 * These decide whether a reporter's reply closes a bug, and whether one of Edward's
 * replies moves it to investigating or off-topic. Getting them wrong either closes a
 * live bug or leaves a fixed one open and re-dispatched, and nothing downstream
 * checks the answer. Until now nothing tested them either, while the action they
 * live in was throwing on every run.
 *
 * The Python runs for real; posts_for is a fixture lookup, so nothing goes near
 * Discourse.
 */

const EDWARD = 'Edward_Hibbert'

type Post = { post_number: number; username: string; cooked: string }
type Result = {
  confirmations: Array<{ topic: number; post: number; confirmedBy: string; confirmPostNumber: number; confirmText: string }>
  edwardUpdates: Array<{ topic: number; post: number; action: string; postNumber: number }>
}

/** Run classify_feedback over one bug on topic 100, post 1, with the given replies. */
async function classify(posts: Post[], opts: { reporter?: string } = {}): Promise<Result> {
  const bug = { topic: 100, post: 1, reporter: opts.reporter ?? 'Jos' }
  const driver = `
import json, re
${BUG_FEEDBACK_CLASSIFY_PY}
POSTS = json.loads('''${JSON.stringify(posts)}''')
BUGS = json.loads('''${JSON.stringify([bug])}''')

def posts_for(topic, post):
    return POSTS

results, edward_updates = classify_feedback(BUGS, BUGS, posts_for)
print(json.dumps({'confirmations': results, 'edwardUpdates': edward_updates}))
`
  const { stdout } = await exec('python3', ['-c', driver])
  return JSON.parse(stdout.trim())
}

const reply = (n: number, cooked: string, username = 'Jos'): Post => ({
  post_number: n,
  username,
  cooked: `<p>${cooked}</p>`,
})

describe('reporter confirmations', () => {
  const confirming = [
    'That works now, thanks!',
    'Fixed, thank you',
    'All good now',
    'Perfect, sorted',
    'The fix worked',
    'Seems to be working',
    'No longer happening',
    'Much better now',
  ]

  it.each(confirming)('treats %j as a confirmation', async (text) => {
    const r = await classify([reply(2, text)])
    expect(r.confirmations).toHaveLength(1)
    expect(r.confirmations[0]).toMatchObject({ topic: 100, post: 1, confirmedBy: 'Jos', confirmPostNumber: 2 })
  })

  const notConfirming = [
    'Any news on this?',
    'I am seeing it on Android too',
    'Here is a screenshot',
  ]

  it.each(notConfirming)('does not read %j as a confirmation', async (text) => {
    const r = await classify([reply(2, text)])
    expect(r.confirmations).toEqual([])
  })

  it('ignores Edward when looking for a confirmation', async () => {
    const r = await classify([reply(2, 'Fixed now, thanks', EDWARD)])
    expect(r.confirmations).toEqual([])
  })

  it('takes the first confirming post, not the last', async () => {
    const r = await classify([reply(2, 'Works now thanks'), reply(3, 'Yes, all good')])
    expect(r.confirmations).toHaveLength(1)
    expect(r.confirmations[0].confirmPostNumber).toBe(2)
  })

  it('strips HTML before matching', async () => {
    const r = await classify([{ post_number: 2, username: 'Jos', cooked: '<p>That <em>works</em> <b>now</b></p>' }])
    expect(r.confirmations).toHaveLength(1)
    expect(r.confirmations[0].confirmText).toBe('That works now')
  })
})

describe('the still-broken guard (sweep 2026-05-31 rule #1)', () => {
  const stillBroken = [
    'Thanks, but it is still broken',
    'Thanks - spoke too soon',
    'Fixed? No, it is back again',
    'That worked yesterday but it is happening again',
    'Thanks, though it did not work',
    'All good except the same problem on mobile',
    'Thanks, no change here',
    'Works now on desktop, but worse on iOS',
  ]

  it.each(stillBroken)('refuses to confirm on %j', async (text) => {
    const r = await classify([reply(2, text)])
    expect(r.confirmations).toEqual([])
  })

  it('refuses a whole thread when a later post says it is still broken', async () => {
    // 9655/4 exactly: post 4 confirms, post 5 reports it is not fixed.
    const r = await classify([
      reply(4, 'The fix worked, thanks'),
      reply(5, 'Actually it still omits the pending ones'),
    ])
    expect(r.confirmations).toEqual([])
  })

  it('does not let one of Edward\'s posts veto a confirmation', async () => {
    // Edward describing a still-broken symptom is not the reporter saying so.
    const r = await classify([
      reply(2, 'I can see it is still broken for group admins', EDWARD),
      reply(3, 'Works now for me, thanks'),
    ])
    expect(r.confirmations).toHaveLength(1)
    expect(r.confirmations[0].confirmPostNumber).toBe(3)
  })
})

describe("Edward's replies", () => {
  it('reads expected-behaviour as off-topic', async () => {
    const r = await classify([reply(2, 'This is expected, it works as intended', EDWARD)])
    expect(r.edwardUpdates).toEqual([
      expect.objectContaining({ topic: 100, post: 1, action: 'off_topic', postNumber: 2 }),
    ])
  })

  it('reads a fix-applied post as investigating', async () => {
    const r = await classify([reply(2, 'Should be fixed now, please retest', EDWARD)])
    expect(r.edwardUpdates[0]).toMatchObject({ action: 'investigating', postNumber: 2 })
  })

  it('reads a fix-in-progress post as investigating', async () => {
    const r = await classify([reply(2, 'I can see the problem, fix on the way', EDWARD)])
    expect(r.edwardUpdates[0]).toMatchObject({ action: 'investigating', postNumber: 2 })
  })

  it('prefers off-topic over investigating when a post says both', async () => {
    // "not a bug" must win over "will look at": the bug is dismissed, not queued.
    const r = await classify([reply(2, 'That is not a bug, but I will look at the wording', EDWARD)])
    expect(r.edwardUpdates[0].action).toBe('off_topic')
  })

  it('ignores posts from anyone else', async () => {
    const r = await classify([reply(2, 'This is expected, by design')])
    expect(r.edwardUpdates).toEqual([])
  })

  it('takes only his first qualifying post', async () => {
    const r = await classify([
      reply(2, 'Fix on the way', EDWARD),
      reply(3, 'Not a bug after all', EDWARD),
    ])
    expect(r.edwardUpdates).toHaveLength(1)
    expect(r.edwardUpdates[0]).toMatchObject({ action: 'investigating', postNumber: 2 })
  })

  it('says nothing about a thread he has not posted in', async () => {
    const r = await classify([reply(2, 'Still seeing it')])
    expect(r.edwardUpdates).toEqual([])
  })
})

describe('empty threads', () => {
  it('returns nothing for a bug with no replies', async () => {
    const r = await classify([])
    expect(r.confirmations).toEqual([])
    expect(r.edwardUpdates).toEqual([])
  })
})

describe('the emitted script', () => {
  // These are Python inside a TypeScript template literal, so a stray backslash or
  // a mis-indented interpolation is a syntax error that only shows when a lap runs
  // it, as a tool action that threw. Compiling it here fails the build instead.
  it('compiles as Python in the order the action assembles it', async () => {
    const script = [
      'import json, urllib.request, re, sys, time',
      "headers = {'Api-Key': 'x'}",
      discourseFetchPy(4, 'skip'),
      BUG_FEEDBACK_CLASSIFY_PY,
      'results, edward_updates = classify_feedback([], [], lambda t, p: [])',
      "print(json.dumps({'confirmations': results, 'edwardUpdates': edward_updates, 'fetchFailures': FETCH_FAILURES}))",
    ].join('\n')

    const { stdout } = await exec('python3', ['-c', script])
    expect(JSON.parse(stdout.trim())).toEqual({
      confirmations: [],
      edwardUpdates: [],
      fetchFailures: [],
    })
  })
})
