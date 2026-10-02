import { describe, it, expect, afterEach } from 'vitest'
import { existsSync, statSync } from 'node:fs'
import { DEFAULT_DB_PATH, LIVE_DB_PATH, getDb, resetDbForTests } from '../db/index'

/**
 * The suite must not touch the database a running lap is using.
 *
 * getDb() with no argument opens monitor-fsm/monitor.db and applies schema
 * migrations to it. One missing in-memory setup in one test file is enough to
 * migrate and then write to live state, and the FSM gives no sign it happened.
 */

describe('live database isolation', () => {
  afterEach(() => resetDbForTests())

  it('points the default path away from the live database', () => {
    expect(process.env.MONITOR_FSM_DB_PATH).toBeTruthy()
    expect(DEFAULT_DB_PATH).not.toBe(LIVE_DB_PATH)
  })

  it('refuses the live path even when it is passed explicitly', () => {
    expect(() => getDb(LIVE_DB_PATH)).toThrow(/refusing to open the live database/)
  })

  it('leaves the live database file alone', () => {
    const before = existsSync(LIVE_DB_PATH) ? statSync(LIVE_DB_PATH).mtimeMs : null

    // The path a forgetful test would land on.
    const db = getDb()
    db.prepare("INSERT INTO kv (key, value) VALUES ('isolation-probe', '1')").run()

    const after = existsSync(LIVE_DB_PATH) ? statSync(LIVE_DB_PATH).mtimeMs : null
    expect(after).toBe(before)
  })
})
