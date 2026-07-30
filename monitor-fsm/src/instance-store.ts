import { existsSync, readFileSync, writeFileSync, renameSync } from 'node:fs'

/**
 * ai-flower's JSONFileStorage handles a MISSING store file (fresh start) but not
 * an empty or corrupt one: existsSync passes, then JSON.parse('') throws
 * "Unexpected end of JSON input" and the whole iteration dies before the brain
 * is ever consulted. Its persist() is a plain writeFile, so a crash or kill
 * mid-write truncates the file to zero bytes.
 *
 * That combination cost two silent days on 2026-07-19: iteration 3832 died
 * mid-write, and every run afterwards aborted at startup. The FSM logged a
 * one-line fatal and exited 0, so nothing looked alarming from outside.
 *
 * The store is disposable - monitor.db holds the durable history, this file
 * only holds in-flight ai-flower instances - so resetting it is cheap and far
 * better than refusing to start. Keep the old file so a corrupt one can still
 * be examined.
 */

export interface StoreRepair {
  repaired: boolean
  reason?: string
  backupPath?: string
}

const EMPTY_STORE = { instances: {}, workflows: {} }

/** Describes why a store file is unusable, or null if it is fine. */
export function describeUnusableStore(raw: string): string | null {
  if (raw.trim() === '') return 'file is empty (truncated by an interrupted write)'

  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch (err) {
    return `invalid JSON: ${(err as Error).message}`
  }

  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return 'top level is not an object'
  }

  const store = parsed as Record<string, unknown>
  if (typeof store.instances !== 'object' || store.instances === null) {
    return 'missing "instances" object'
  }
  if (typeof store.workflows !== 'object' || store.workflows === null) {
    return 'missing "workflows" object'
  }

  return null
}

/**
 * Reset the instance store if it cannot be loaded. Returns what was done so the
 * caller can log it loudly - a silent reset would hide a recurring crash.
 */
export function ensureUsableInstanceStore(filePath: string, now: Date = new Date()): StoreRepair {
  if (!existsSync(filePath)) return { repaired: false }

  const reason = describeUnusableStore(readFileSync(filePath, 'utf8'))
  if (!reason) return { repaired: false }

  const backupPath = `${filePath}.corrupt-${now.toISOString().replace(/[:.]/g, '-')}`
  renameSync(filePath, backupPath)
  writeFileSync(filePath, `${JSON.stringify(EMPTY_STORE, null, 2)}\n`, 'utf8')

  return { repaired: true, reason, backupPath }
}
