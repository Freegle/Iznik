import { describe, it, expect } from 'vitest'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { discourseFetchPy } from '../actions/index'

const exec = promisify(execFile)

/**
 * The Python side of Discourse rate-limit handling.
 *
 * check_bug_feedback, discover_active_topics and fetch_topic_updates all shell out
 * to python3, and all three used to read only the Retry-After header. Discourse
 * usually omits that header and puts the wait in the body instead
 * (`extras.wait_seconds`, routinely 40s+), so they backed off 2s, then 4s, then
 * gave up. check_bug_feedback threw on every run from 3 September 2026: for a
 * fortnight no reporter confirmation and no "fix on the way" from Edward was
 * noticed, because one 429 discarded the whole scan.
 *
 * These tests run the generated Python for real, with urlopen and sleep stubbed,
 * so no request leaves the machine and no test waits.
 */

/** Run the shared helper with a stubbed urlopen; returns what the script observed. */
async function runPy(opts: {
  retries: number
  onExhausted: 'raise' | 'skip'
  /** One entry per call: a 429 spec, 404, or 'ok'. */
  responses: Array<{ status: number; retryAfter?: string; waitSeconds?: number } | 'ok'>
}): Promise<{ result: unknown; sleeps: number[]; failures: string[]; error?: string }> {
  const driver = `
import json, urllib.request, urllib.error, sys, time, io

headers = {'Api-Key': 'test'}

RESPONSES = json.loads('''${JSON.stringify(opts.responses)}''')
SLEEPS = []
CALLS = []

def fake_sleep(s):
    SLEEPS.append(s)
time.sleep = fake_sleep

def fake_urlopen(req, timeout=None):
    spec = RESPONSES[len(CALLS)] if len(CALLS) < len(RESPONSES) else RESPONSES[-1]
    CALLS.append(1)
    if spec == 'ok':
        return io.BytesIO(b'{"ok": true}')
    body = {'errors': ['too many'], 'error_type': 'rate_limit'}
    if spec.get('waitSeconds') is not None:
        body['extras'] = {'wait_seconds': spec['waitSeconds']}
    hdrs = {}
    if spec.get('retryAfter') is not None:
        hdrs['Retry-After'] = spec['retryAfter']
    raise urllib.error.HTTPError(
        'https://example.test/t/1.json', spec['status'], 'err', hdrs,
        io.BytesIO(json.dumps(body).encode())
    )
urllib.request.urlopen = fake_urlopen
${discourseFetchPy(opts.retries, opts.onExhausted)}
out = {'sleeps': SLEEPS, 'failures': FETCH_FAILURES}
try:
    out['result'] = fetch('https://example.test/t/1.json')
except Exception as e:
    out['error'] = type(e).__name__
    out['sleeps'] = SLEEPS
    out['failures'] = FETCH_FAILURES
print(json.dumps(out))
`
  const { stdout } = await exec('python3', ['-c', driver])
  return JSON.parse(stdout.trim())
}

describe('discourseFetchPy backoff', () => {
  it('waits as long as the 429 body asks when there is no Retry-After header', async () => {
    const r = await runPy({
      retries: 4,
      onExhausted: 'skip',
      responses: [{ status: 429, waitSeconds: 44 }, 'ok'],
    })
    // The old header-only code slept the 2s default here and gave up two tries later.
    expect(r.sleeps).toEqual([44])
    expect(r.result).toEqual({ ok: true })
  })

  it('prefers the Retry-After header over the body', async () => {
    const r = await runPy({
      retries: 4,
      onExhausted: 'skip',
      responses: [{ status: 429, retryAfter: '7', waitSeconds: 44 }, 'ok'],
    })
    expect(r.sleeps).toEqual([7])
  })

  it('caps a pathological wait so one response cannot stall the iteration', async () => {
    const r = await runPy({
      retries: 4,
      onExhausted: 'skip',
      responses: [{ status: 429, waitSeconds: 9999 }, 'ok'],
    })
    expect(r.sleeps).toEqual([60])
  })

  it('falls back to a growing default when the 429 says nothing', async () => {
    const r = await runPy({
      retries: 4,
      onExhausted: 'skip',
      responses: [{ status: 429 }, { status: 429 }, { status: 429 }, 'ok'],
    })
    expect(r.sleeps).toEqual([2, 4, 8])
  })

  it('skips a rate-limited URL instead of discarding the whole scan', async () => {
    const r = await runPy({
      retries: 2,
      onExhausted: 'skip',
      responses: [{ status: 429 }, { status: 429 }],
    })
    expect(r.error).toBeUndefined()
    expect(r.result).toBeNull()
    expect(r.failures).toEqual(['429 https://example.test/t/1.json'])
  })

  it('still raises for the listing helpers, where a partial list is worse', async () => {
    const r = await runPy({
      retries: 2,
      onExhausted: 'raise',
      responses: [{ status: 429 }, { status: 429 }],
    })
    expect(r.error).toBe('HTTPError')
    expect(r.failures).toEqual(['429 https://example.test/t/1.json'])
  })

  it('treats 404 as an absent topic, not a failure', async () => {
    const r = await runPy({
      retries: 4,
      onExhausted: 'raise',
      responses: [{ status: 404 }],
    })
    expect(r.error).toBeUndefined()
    expect(r.result).toBeNull()
    expect(r.failures).toEqual([])
  })
})
