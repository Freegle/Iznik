'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { billableCostUsd } = require('./auth')

// The SDK reports a notional total_cost_usd on every query, worked out from the
// model's list rates. Only 'api' mode is actually metered - 'subscription' (a
// `claude setup-token` OAuth token) and 'session' (a mounted ~/.claude login)
// both run against a monthly Claude subscription where no per-query charge
// exists. Reporting one there is meaningless and alarming.
//
// The check used to be `mode === 'session' ? 0 : cost`, which reported a cost on
// a subscription - the very case it meant to exclude.

test('a metered API key is billed the SDK cost', () => {
  assert.strictEqual(billableCostUsd('api', 0.0123), 0.0123)
})

test('a subscription is never billed per query', () => {
  assert.strictEqual(billableCostUsd('subscription', 0.0123), 0)
})

test('a mounted login session is never billed per query', () => {
  assert.strictEqual(billableCostUsd('session', 0.0123), 0)
})

test('a missing or zero SDK cost reports 0 rather than undefined or NaN', () => {
  for (const mode of ['api', 'subscription', 'session']) {
    assert.strictEqual(billableCostUsd(mode, undefined), 0, mode)
    assert.strictEqual(billableCostUsd(mode, null), 0, mode)
    assert.strictEqual(billableCostUsd(mode, 0), 0, mode)
  }
})

test('an unrecognised mode is treated as not billable, not as metered', () => {
  // Fail safe: a new driver mode should not start showing dollar figures until
  // someone decides it genuinely is metered.
  assert.strictEqual(billableCostUsd('something-new', 0.5), 0)
  assert.strictEqual(billableCostUsd(undefined, 0.5), 0)
})
