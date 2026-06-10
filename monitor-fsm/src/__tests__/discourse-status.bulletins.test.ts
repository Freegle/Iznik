/**
 * Tests that the Discourse status post and iteration summary accurately label
 * fix outcomes rather than calling them "done" before they are deployed.
 *
 * Bug: topic 9778/2 — bulletins overstate outcomes by labelling:
 *   - attempted-but-failed fixes (PR opened, not yet merged) as "Discourse bugs fixed"
 *   - not-yet-live fixes (PR merged, not yet deployed) as "Fixed in the last 7 days"
 */
import { describe, it, expect, beforeEach } from 'vitest'
import Database from 'better-sqlite3'
import { SCHEMA_SQL, MIGRATION_V2_SQL, MIGRATION_V3_SQL, MIGRATION_V4_SQL, MIGRATION_V5_SQL } from '../db/schema'
import { upsertDiscourseBug, upsertPr } from '../db/index'
import { renderStatusPostBody } from '../db/discourse-status'

function makeTestDb(): Database.Database {
  const db = new Database(':memory:')
  db.pragma('foreign_keys = ON')
  db.exec(SCHEMA_SQL)
  db.exec(MIGRATION_V2_SQL)
  for (const stmt of MIGRATION_V3_SQL.trim().split('\n').filter(s => s.trim())) {
    try { db.exec(stmt) } catch { /* column exists */ }
  }
  try { db.exec(MIGRATION_V4_SQL) } catch { /* already ok */ }
  try { db.exec(MIGRATION_V5_SQL) } catch { /* already ok */ }
  return db
}

describe('Discourse status post — bulletin accuracy', () => {
  let db: Database.Database

  beforeEach(() => {
    db = makeTestDb()
  })

  describe('not-yet-deployed merged fix should not appear as "fixed"', () => {
    beforeEach(() => {
      // Bug has a PR that is MERGED but not yet deployed (deploy_state = NULL)
      upsertPr(db, { number: 501, title: 'Fix widget overflow', state: 'MERGED', deployState: undefined })
      upsertDiscourseBug(db, {
        topic: 9700, post: 3, reporter: 'TestReporter',
        excerpt: 'Widget overflow on mobile',
        state: 'fix-queued', prNumber: 501,
      })
    })

    it('MERGED-but-undeployed bug stays in "Bugs being fixed", not "Fixed in the last 7 days"', () => {
      const { raw } = renderStatusPostBody(db)
      // The reporter should still be listed (in "Bugs being fixed")
      expect(raw).toContain('Bugs being fixed')
      expect(raw).toContain('TestReporter')
      // But NOT in the "Fixed in the last 7 days" section
      const fixedSectionIdx = raw.indexOf('Fixed in the last 7 days')
      const reporterIdx = raw.indexOf('TestReporter')
      // Either the section is absent, or the reporter appears BEFORE it
      // (i.e. in "Bugs being fixed" not in "Fixed")
      if (fixedSectionIdx === -1) {
        // Section absent entirely — acceptable, reporter must still be shown
        expect(raw).toContain('TestReporter')
      } else {
        expect(reporterIdx).toBeLessThan(fixedSectionIdx)
      }
    })
  })

  describe('deployed fix correctly appears as "fixed"', () => {
    it('CORRECT: deployed fix moves to "Fixed in the last 7 days"', () => {
      upsertPr(db, { number: 502, title: 'Fix search ranking', state: 'MERGED', deployState: 'deployed' })
      upsertDiscourseBug(db, {
        topic: 9701, post: 5, reporter: 'AnotherReporter',
        excerpt: 'Search returns wrong results',
        state: 'fix-queued', prNumber: 502,
      })

      const { raw } = renderStatusPostBody(db)
      // After the fix, a DEPLOYED merge should move the bug to "Fixed"
      expect(raw).toContain('Fixed in the last 7 days')
      expect(raw).toMatch(/Fixed in the last 7 days[\s\S]*AnotherReporter/)
    })
  })
})
