import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import Database from 'better-sqlite3'
import {
  getDb,
  resetDbForTests,
  kvGet,
  kvSet,
  upsertDiscourseBug,
  getDiscourseBug,
  queueDiscourseDraft,
  listPendingDrafts,
  reopenBugAfterRejection,
  markDiscourseBugFixed,
} from '../db/index'
import { SCHEMA_SQL, MIGRATION_V2_SQL } from '../db/schema'

describe('Database Helpers', () => {
  let testDb: Database.Database

  beforeEach(() => {
    // Create an in-memory database for tests, applying all migrations
    testDb = new Database(':memory:')
    testDb.pragma('foreign_keys = ON')
    testDb.exec(SCHEMA_SQL)
    testDb.exec(MIGRATION_V2_SQL)
  })

  afterEach(() => {
    if (testDb) {
      testDb.close()
    }
    resetDbForTests()
  })

  describe('kvGet/kvSet', () => {
    it('should store and retrieve a key-value pair', () => {
      kvSet(testDb, 'test_key', 'test_value')
      const result = kvGet(testDb, 'test_key')
      expect(result).toBe('test_value')
    })

    it('should return null for non-existent key', () => {
      const result = kvGet(testDb, 'non_existent_key')
      expect(result).toBeNull()
    })

    it('should update an existing key', () => {
      kvSet(testDb, 'my_key', 'original')
      kvSet(testDb, 'my_key', 'updated')
      const result = kvGet(testDb, 'my_key')
      expect(result).toBe('updated')
    })

    it('should handle null values', () => {
      kvSet(testDb, 'nullable_key', 'initial')
      kvSet(testDb, 'nullable_key', null)
      const result = kvGet(testDb, 'nullable_key')
      expect(result).toBeNull()
    })

    it('should be idempotent', () => {
      kvSet(testDb, 'idempotent_key', 'value1')
      kvSet(testDb, 'idempotent_key', 'value1')
      const result = kvGet(testDb, 'idempotent_key')
      expect(result).toBe('value1')
    })
  })

  describe('upsertDiscourseBug', () => {
    it('should insert a new bug', () => {
      upsertDiscourseBug(testDb, {
        topic: 123,
        post: 1,
        topicTitle: 'Test Topic',
        reporter: 'john_doe',
        excerpt: 'Something is broken',
        state: 'open',
        featureArea: 'chat',
      })

      const bug = getDiscourseBug(testDb, 123, 1)
      expect(bug).not.toBeNull()
      expect(bug!.topic).toBe(123)
      expect(bug!.post).toBe(1)
      expect(bug!.topic_title).toBe('Test Topic')
      expect(bug!.reporter).toBe('john_doe')
      expect(bug!.state).toBe('open')
    })

    it('should update an existing bug', () => {
      upsertDiscourseBug(testDb, {
        topic: 456,
        post: 2,
        topicTitle: 'Original Title',
        reporter: 'jane_doe',
        state: 'open',
      })

      upsertDiscourseBug(testDb, {
        topic: 456,
        post: 2,
        topicTitle: 'Updated Title',
        state: 'investigating',
      })

      const bug = getDiscourseBug(testDb, 456, 2)
      expect(bug!.topic_title).toBe('Updated Title')
      expect(bug!.state).toBe('investigating')
      expect(bug!.reporter).toBe('jane_doe') // should preserve old value
    })

    it('should accept both topic and post fields', () => {
      upsertDiscourseBug(testDb, {
        topic: 999,
        post: 5,
        reporter: 'test_user',
      })

      const bug = getDiscourseBug(testDb, 999, 5)
      expect(bug).not.toBeNull()
      expect(bug!.post).toBe(5)
    })
  })

  describe('queueDiscourseDraft', () => {
    it('should insert a new draft', () => {
      const draftId = queueDiscourseDraft(testDb, {
        topic: 100,
        post: 1,
        username: 'edward',
        quote: 'Original problem description',
        body: 'I fixed this by doing X',
      })

      expect(draftId).toBeGreaterThan(0)

      const drafts = listPendingDrafts(testDb)
      expect(drafts.length).toBe(1)
      expect(drafts[0].username).toBe('edward')
      expect(drafts[0].body).toContain('fixed')
    })

    it('should not create duplicate drafts for same topic/post', () => {
      const id1 = queueDiscourseDraft(testDb, {
        topic: 200,
        post: 2,
        username: 'alice',
        quote: 'test',
        body: 'first draft',
      })

      const id2 = queueDiscourseDraft(testDb, {
        topic: 200,
        post: 2,
        username: 'bob',
        quote: 'test',
        body: 'second draft',
      })

      expect(id1).toBe(id2) // should return the same ID
      const drafts = listPendingDrafts(testDb)
      expect(drafts.length).toBe(1)
    })

    it('should allow drafts for different posts in same topic', () => {
      const id1 = queueDiscourseDraft(testDb, {
        topic: 300,
        post: 1,
        username: 'user1',
        quote: 'post 1',
        body: 'draft 1',
      })

      const id2 = queueDiscourseDraft(testDb, {
        topic: 300,
        post: 2,
        username: 'user2',
        quote: 'post 2',
        body: 'draft 2',
      })

      expect(id1).not.toBe(id2)
      const drafts = listPendingDrafts(testDb)
      expect(drafts.length).toBe(2)
    })

    it('should allow new draft after old one is posted', () => {
      const id1 = queueDiscourseDraft(testDb, {
        topic: 400,
        post: 3,
        username: 'alice',
        quote: 'old',
        body: 'old draft',
      })

      // Mark as posted
      testDb.prepare(`
        UPDATE discourse_draft
        SET posted_at = datetime('now')
        WHERE id = ?
      `).run(id1)

      // Should allow a new draft now
      const id2 = queueDiscourseDraft(testDb, {
        topic: 400,
        post: 3,
        username: 'bob',
        quote: 'new',
        body: 'new draft',
      })

      expect(id2).not.toBe(id1)
      const pendingCount = (testDb.prepare('SELECT COUNT(*) AS c FROM discourse_draft WHERE posted_at IS NULL AND rejected_at IS NULL').get() as { c: number }).c
      expect(pendingCount).toBe(1)
    })
  })

  describe('reopenBugAfterRejection', () => {
    it('should reopen a bug and increment rejection count', () => {
      upsertDiscourseBug(testDb, {
        topic: 500,
        post: 4,
        state: 'fix-queued',
        prNumber: 100,
      })

      reopenBugAfterRejection(testDb, 500, 4, 100)

      const bug = getDiscourseBug(testDb, 500, 4)
      expect(bug!.state).toBe('open')
      expect(bug!.pr_number).toBeNull() // should clear the PR link
      expect(bug!.pr_rejections).toBe(1)
    })

    it('should track multiple rejections', () => {
      upsertDiscourseBug(testDb, {
        topic: 501,
        post: 5,
        state: 'fix-queued',
        prNumber: 101,
      })

      reopenBugAfterRejection(testDb, 501, 5, 101)
      reopenBugAfterRejection(testDb, 501, 5, 102)

      const bug = getDiscourseBug(testDb, 501, 5)
      expect(bug!.pr_rejections).toBe(2)
    })

    it('should set custom rejection reason', () => {
      upsertDiscourseBug(testDb, {
        topic: 502,
        post: 6,
        state: 'fix-queued',
        prNumber: 103,
      })

      const customReason = 'Wrong approach — needs architectural change'
      reopenBugAfterRejection(testDb, 502, 6, 103, customReason)

      const bug = getDiscourseBug(testDb, 502, 6)
      expect(bug!.reason).toBe(customReason)
    })
  })

  describe('markDiscourseBugFixed', () => {
    it('should mark a bug as fixed with PR number', () => {
      upsertDiscourseBug(testDb, {
        topic: 600,
        post: 7,
        state: 'fix-queued',
        prNumber: 200,
      })

      markDiscourseBugFixed(testDb, 600, 7, 200)

      const bug = getDiscourseBug(testDb, 600, 7)
      expect(bug!.state).toBe('fixed')
      expect(bug!.pr_number).toBe(200)
      expect(bug!.fixed_at).not.toBeNull()
    })

    it('should update fixed_at timestamp', () => {
      upsertDiscourseBug(testDb, {
        topic: 601,
        post: 8,
        state: 'fix-queued',
        prNumber: 201,
      })

      const beforeFix = new Date().toISOString()
      markDiscourseBugFixed(testDb, 601, 8, 201)
      const afterFix = new Date().toISOString()

      const bug = getDiscourseBug(testDb, 601, 8)
      expect(bug!.fixed_at).toBeTruthy()
      // SQLite datetime('now') is second-precision UTC without timezone suffix.
      // Append 'Z' to parse as UTC, and allow 1s rounding below beforeFix.
      const fixedTime = new Date(bug!.fixed_at! + 'Z')
      expect(fixedTime.getTime()).toBeGreaterThanOrEqual(new Date(beforeFix).getTime() - 1000)
      expect(fixedTime.getTime()).toBeLessThanOrEqual(new Date(afterFix).getTime())
    })
  })
})
