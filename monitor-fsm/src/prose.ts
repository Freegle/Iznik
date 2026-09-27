// Plain-English check for anything the monitor drafts for a human reader.
//
// A reply to a moderator is read once, on a phone, by someone who does not work
// on the code. `.claude/pr-complexity.mjs` in the repo root is the project's
// single scorer for that (reading grade, over-long sentences, coined
// hyphen-compounds); `.claude/check-discourse-post.sh` runs it over anything
// Claude itself posts. The monitor's own drafts never pass through that hook, so
// they are scored here instead, at the point they are written.

import { spawn } from 'node:child_process'
import { existsSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

// Same thresholds as check-discourse-post.sh - tighter than the PR defaults.
const DISCOURSE_MAX_GRADE = '11'
const DISCOURSE_MAX_SENTENCE_WORDS = '30'

/**
 * Locate `.claude/pr-complexity.mjs` by walking up from this module.
 *
 * This resolves from `src/` under vitest and from `dist/` in production, and
 * from a worktree or a throwaway clone as readily as from the main checkout,
 * because it is the repo layout that is fixed, not the absolute path.
 */
export function findProseProblemsScorerPath(startDir?: string): string | null {
  let dir = startDir ?? dirname(fileURLToPath(import.meta.url))
  for (let i = 0; i < 6; i++) {
    const candidate = resolve(dir, '.claude', 'pr-complexity.mjs')
    if (existsSync(candidate)) return candidate
    const parent = dirname(dir)
    if (parent === dir) break
    dir = parent
  }
  return null
}

/**
 * Problems a human reader would hit in `text`; empty means it reads fine.
 *
 * Fails OPEN: if the scorer cannot be found or cannot be run, this returns no
 * problems rather than blocking. Every draft it guards is reviewed by a person
 * before it goes anywhere, so a missing scorer must not stop the queue.
 */
export async function proseProblems(text: string): Promise<string[]> {
  const scorer = findProseProblemsScorerPath()
  if (!scorer) return []
  return new Promise((resolvePromise) => {
    let stdout = ''
    const child = spawn(process.execPath, [scorer], {
      env: {
        ...process.env,
        PROSE_MAX_GRADE: DISCOURSE_MAX_GRADE,
        PROSE_MAX_SENTENCE_WORDS: DISCOURSE_MAX_SENTENCE_WORDS,
      },
      stdio: ['pipe', 'pipe', 'ignore'],
    })
    child.stdout.on('data', (d: Buffer) => { stdout += d.toString() })
    child.on('error', () => resolvePromise([]))
    child.on('close', () => {
      resolvePromise(stdout.split('\n').map(l => l.trim()).filter(Boolean))
    })
    child.stdin.on('error', () => { /* scorer exited early - close() still fires */ })
    child.stdin.end(text)
  })
}
