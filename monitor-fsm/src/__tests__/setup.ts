import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

/**
 * Vitest setup, run before any test module is imported.
 *
 * The FSM's state lives in two files on a developer's machine: monitor-fsm/monitor.db
 * and /tmp/freegle-monitor/debug.log. Both are in use whenever a lap is running. The
 * suite drives the same code as a lap, so without this it writes to both: getDb() with
 * no argument opens and migrates the live database, and out()/outWarn() append fixture
 * failures to the live log.
 *
 * Each test file gets its own database here. src/log.ts diverts the log on process.env
 * .VITEST, and getDb() refuses the live path under the same flag, so a test that
 * bypasses this file still cannot reach live state.
 */
const dir = mkdtempSync(join(tmpdir(), 'monitor-fsm-test-'))
process.env.MONITOR_FSM_DB_PATH = join(dir, 'monitor.db')
