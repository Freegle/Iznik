import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'
import { describe, it, expect } from 'vitest'

/**
 * Verifies the pinned add-to-calendar-button version used by
 * pages/calendar.client.vue (topic 9927 post 1).
 *
 * On Android, add-to-calendar-button < 2.13.0 opens the Google Calendar
 * option via an `intent://...#Intent;scheme=https;package=com.google.android.calendar;end`
 * URL with no `S.browser_fallback_url` fallback, and does not skip that
 * intent scheme when running inside a WebView. If the intent can't be
 * resolved (app not installed, or opened from a context that can't handle
 * custom URL schemes, e.g. an in-app browser tab), the OS/browser shows an
 * error instead of falling back to the normal Google Calendar web page.
 * 2.13.0 added both the fallback URL and an isWebView() check that avoids
 * the intent scheme entirely in that case.
 */
const __dirname = dirname(fileURLToPath(import.meta.url))
const lockPath = resolve(__dirname, '../../../package-lock.json')
const lock = JSON.parse(readFileSync(lockPath, 'utf-8'))

describe('add-to-calendar-button pinned version', () => {
  it('is at least 2.13.0, which fixes the Android Google Calendar intent:// error', () => {
    const resolvedVersion =
      lock.packages['node_modules/add-to-calendar-button'].version
    const [major, minor] = resolvedVersion.split('.').map(Number)

    expect(major > 2 || (major === 2 && minor >= 13)).toBe(true)
  })
})
