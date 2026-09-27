import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, mkdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

/**
 * The staleness banner run-loop.sh prints before each lap.
 *
 * run-loop.sh compiles the working tree, so the loop runs whatever branch the
 * checkout has out. A fix merged to master does not reach the loop until the
 * checkout has it, and nothing says so: the log shows the same "tool action ...
 * threw" as before the fix, so the fix looks ineffective rather than absent.
 *
 * Each test builds a throwaway git repository in /tmp, so nothing here touches a
 * real checkout or the network (the fetch is skipped by environment variable).
 */

const SCRIPT = resolve(__dirname, '..', '..', 'scripts', 'warn-if-stale.sh')

let repo: string
let fsmDir: string

function git(args: string[], cwd = repo) {
  execFileSync('git', args, { cwd, encoding: 'utf8', stdio: 'pipe' })
}

/** Run the script against the fixture, with the fetch and ref overridden. */
function run(): string {
  return execFileSync('bash', [SCRIPT, fsmDir], {
    encoding: 'utf8',
    env: { ...process.env, STALE_CHECK_SKIP_FETCH: '1', STALE_CHECK_REF: 'fake-master' },
  })
}

beforeEach(() => {
  repo = mkdtempSync(join(tmpdir(), 'stale-check-'))
  fsmDir = join(repo, 'monitor-fsm')
  mkdirSync(fsmDir)
  git(['init', '--initial-branch=main'])
  git(['config', 'user.email', 'test@example.com'])
  git(['config', 'user.name', 'Test'])
  writeFileSync(join(fsmDir, 'driver.ts'), 'original\n')
  writeFileSync(join(repo, 'unrelated.txt'), 'x\n')
  git(['add', '-A'])
  git(['commit', '-m', 'base'])
  // Stand in for origin/master at the base commit.
  git(['branch', 'fake-master'])
})

afterEach(() => rmSync(repo, { recursive: true, force: true }))

describe('warn-if-stale', () => {
  it('says nothing when the checkout matches the reference', () => {
    expect(run()).toBe('')
  })

  it('warns, and names the file, when the FSM code differs', () => {
    writeFileSync(join(fsmDir, 'driver.ts'), 'changed\n')
    git(['add', '-A'])
    git(['commit', '-m', 'local change'])

    const out = run()

    expect(out).toContain('WARNING')
    expect(out).toContain('NOT fake-master')
    expect(out).toContain('driver.ts')
    expect(out).toContain('differing files: 1')
    expect(out).toContain('branch:          main')
  })

  it('ignores changes outside the FSM directory', () => {
    // The loop only compiles monitor-fsm, so an unrelated change is not staleness.
    writeFileSync(join(repo, 'unrelated.txt'), 'changed\n')
    git(['add', '-A'])
    git(['commit', '-m', 'unrelated change'])

    expect(run()).toBe('')
  })

  it('says nothing when the reference does not exist', () => {
    // A checkout that has never fetched must not be told it is stale.
    const out = execFileSync('bash', [SCRIPT, fsmDir], {
      encoding: 'utf8',
      env: { ...process.env, STALE_CHECK_SKIP_FETCH: '1', STALE_CHECK_REF: 'no/such/ref' },
    })
    expect(out).toBe('')
  })

  it('says nothing outside a git repository', () => {
    const loose = mkdtempSync(join(tmpdir(), 'stale-check-loose-'))
    try {
      const out = execFileSync('bash', [SCRIPT, loose], {
        encoding: 'utf8',
        env: { ...process.env, STALE_CHECK_SKIP_FETCH: '1' },
      })
      expect(out).toBe('')
    } finally {
      rmSync(loose, { recursive: true, force: true })
    }
  })

  it('exits 0 even when it warns, so it can never stop a run', () => {
    writeFileSync(join(fsmDir, 'driver.ts'), 'changed\n')
    git(['add', '-A'])
    git(['commit', '-m', 'local change'])

    const code = execFileSync(
      'bash',
      ['-c', `bash "${SCRIPT}" "${fsmDir}" >/dev/null; echo $?`],
      { encoding: 'utf8', env: { ...process.env, STALE_CHECK_SKIP_FETCH: '1', STALE_CHECK_REF: 'fake-master' } }
    ).trim()

    expect(code).toBe('0')
  })
})
