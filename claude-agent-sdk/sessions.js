'use strict'

/**
 * The agent's saved sessions, which let a follow-up question carry on the same investigation. The CLI
 * writes each one to ~/.claude/projects/<working directory>/<session id>.jsonl, sometimes with a folder of
 * the same name beside it. They live on a volume so a restart does not lose them.
 *
 * Only Node built-ins, so CI's bare `node --test` can load it.
 */

const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')

const PROJECTS_DIR = path.join(os.homedir(), '.claude', 'projects')

// The page holds a session's id only while it stays open, so a day is more than a follow-up needs. A
// session holds what the tools returned about a member, so it is not kept longer than that.
const KEEP_MS = 24 * 60 * 60 * 1000

const SESSION_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

function projectFolders(dir) {
  try {
    return fs
      .readdirSync(dir, { withFileTypes: true })
      .filter((d) => d.isDirectory())
      .map((d) => path.join(dir, d.name))
  } catch (_) {
    return []
  }
}

/** Whether the session can still be resumed. Anything but a session id is refused, so no other file can be named. */
function sessionExists(id, dir = PROJECTS_DIR) {
  if (typeof id !== 'string' || !SESSION_ID.test(id)) return false
  return projectFolders(dir).some((p) => fs.existsSync(path.join(p, `${id}.jsonl`)))
}

/**
 * Delete sessions, and their folders, last written more than keepMs ago.
 *
 * @returns {number} how many were removed.
 */
function pruneSessions({ dir = PROJECTS_DIR, keepMs = KEEP_MS, now = Date.now() } = {}) {
  let removed = 0
  for (const project of projectFolders(dir)) {
    let entries
    try {
      entries = fs.readdirSync(project)
    } catch (_) {
      continue
    }
    for (const name of entries) {
      const p = path.join(project, name)
      try {
        if (now - fs.statSync(p).mtimeMs > keepMs) {
          fs.rmSync(p, { recursive: true, force: true })
          removed++
        }
      } catch (e) {
        console.error(`[Sessions] could not prune ${name}: ${e.message}`)
      }
    }
  }
  return removed
}

module.exports = { sessionExists, pruneSessions, PROJECTS_DIR, KEEP_MS }
