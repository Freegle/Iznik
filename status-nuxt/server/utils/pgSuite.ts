import { spawn } from 'child_process'
import { getTestState, setTestState, appendTestLogs } from './testState'

/**
 * Runs one of the PostgreSQL test runs for the status API.
 *
 * The runs are driven by tools/pg-ratchet: a driver runs the suite on PostgreSQL and writes one
 * result per test, and ratchet.mjs compares the failures with the committed list of tests known to
 * fail there. The status stays "failed" only when a test outside the list failed, or the suite did
 * not run at all. The driver prints "PROGRESS <suite> passed=N failed=M total=T" lines, which feed
 * the progress counters the orb polls.
 *
 * `script` is a shell script run under `sh -e`. Its last command should be the ratchet check, so the
 * exit code is the verdict. The results are left under /tmp/pg-ratchet/<suite> in the status
 * container, where the orb copies them out as artifacts.
 */
export function startPgSuite(testType: string, label: string, script: string): void {
  setTestState(testType, {
    status: 'running',
    message: `Starting ${label}...`,
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    success: undefined,
    startTime: Date.now(),
    endTime: null,
  })

  const proc = spawn('sh', ['-e', '-c', script], { stdio: 'pipe' })
  let buffer = ''

  const onText = (text: string) => {
    appendTestLogs(testType, text)
    const combined = buffer + text
    const parts = combined.split('\n')
    buffer = parts.pop() || ''
    const state = getTestState(testType)
    for (const line of parts) {
      const m = line.match(/^PROGRESS \S+ passed=(\d+) failed=(\d+) total=(\d+)/)
      if (m) {
        state.progress.passed = parseInt(m[1])
        state.progress.failed = parseInt(m[2])
        state.progress.completed = parseInt(m[3])
        state.message = `Running ${label}: ${m[1]} passed, ${m[2]} failed so far`
      }
    }
    setTestState(testType, state)
  }

  proc.stdout.on('data', (d) => onText(d.toString()))
  proc.stderr.on('data', (d) => onText(d.toString()))

  proc.on('close', (code) => {
    const state = getTestState(testType)
    const logs = state.logs
    const verdict = logs.split('\n').filter((l) => l.startsWith('RATCHET ')).slice(-3).join(' | ')
    setTestState(testType, {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0 ? `${label} matches its known failures (${verdict})` : `${label} failed (${verdict || 'see the logs'})`,
    })
    console.log(`${label} finished with code ${code}`)
  })

  proc.on('error', (error) => {
    setTestState(testType, {
      status: 'failed',
      success: false,
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })
}

/** Shell-quotes one argument. */
export function shq(s: string): string {
  return `'${s.replace(/'/g, `'\\''`)}'`
}
