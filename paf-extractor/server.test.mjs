// node --test paf-extractor/server.test.mjs
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { parseRelease } from './server.mjs'

const now = new Date('2026-10-20T09:00:00Z')

test('a recent release has a time, not a year, and is this year', () => {
  const listing = 'drwxr-xr-x 2 afd afd 4096 Sep  1  2026 Archive\n-rw-r--r-- 1 afd afd 440401920 Oct  3 09:12 SetupRM.exe\n'
  assert.deepEqual(parseRelease(listing, now), { size: 440401920, published: '2026-10-03' })
})

test('an older release has its year', () => {
  assert.deepEqual(parseRelease('-rw-r--r-- 1 afd afd 5 Mar 30  2025 SetupRM.exe', now), { size: 5, published: '2025-03-30' })
})

test('a time-stamped date later than today was last year', () => {
  assert.deepEqual(parseRelease('-rw-r--r-- 1 afd afd 5 Dec 28 10:00 SetupRM.exe', now), { size: 5, published: '2025-12-28' })
})

test('no SetupRM.exe is no release', () => {
  assert.equal(parseRelease('drwxr-xr-x 2 afd afd 4096 Sep  1  2026 Archive\n', now), null)
})
