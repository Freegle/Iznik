// @ts-check
/**
 * Trivial no-op "setup" project. It exists only so the 'lockdown-order'
 * project in playwright.config.js has a test to run, which lets it declare
 * `teardown: 'lockdown'`.
 *
 * That teardown relationship (not a plain `dependencies: ['chromium']` on
 * the 'lockdown' project) is what makes lockdown.spec.js run, and report its
 * result, after every project that depends on 'lockdown-order' has finished
 * - whether or not those projects passed. A plain dependency would instead
 * make Playwright report lockdown.spec.js as "did not run" (not "failed")
 * whenever anything else in the suite failed, which would silently hide the
 * one test that presses a real site-wide lockdown from ever running its
 * safety checks. See the projects comment in playwright.config.js.
 */
const { test } = require('@playwright/test')

test('lockdown ordering setup (no-op)', () => {
  // Nothing to do - this test only anchors the teardown relationship above.
})
