import { describe, it, expect } from 'vitest'
import { existsSync, statSync } from 'node:fs'
import { DEBUG_LOG_PATH, dbg, out, outWarn } from '../log'

/**
 * The test suite must not write into the live monitor's log.
 *
 * out(), outWarn() and dbg() append to /tmp/freegle-monitor/debug.log, and the
 * tests drive the same code paths as a real lap, failure branches included. A
 * test run therefore used to leave lines like "FAILED to post reply for bug
 * 9001.3 (PR #500)" in the record of a live run, where they are indistinguishable
 * from a genuine failure.
 */

const LIVE_LOG = '/tmp/freegle-monitor/debug.log'

describe('log isolation under test', () => {
  it('writes somewhere other than the live log', () => {
    expect(DEBUG_LOG_PATH).not.toBe(LIVE_LOG)
  })

  it('leaves the live log untouched', () => {
    const before = existsSync(LIVE_LOG) ? statSync(LIVE_LOG).size : null

    dbg('test line: dbg')
    out('test line: out')
    outWarn('test line: outWarn')

    const after = existsSync(LIVE_LOG) ? statSync(LIVE_LOG).size : null
    expect(after).toBe(before)
  })
})
