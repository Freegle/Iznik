// Runs commands where a suite lives. The drivers run on the host or in the status container, and the
// code under test is in another container, so a command is built from a template such as
//   ["docker","exec","-w","{cwd}","-e","DB_ENGINE=postgres","freegle-apiv2"]
// with {cwd} replaced. With no template the command runs here, in cwd.

import { spawn } from 'node:child_process'
import readline from 'node:readline'

export function makeExec(templateJson) {
  const template = templateJson ? JSON.parse(templateJson) : null
  return function build(cwd, argv, { stdin = false } = {}) {
    if (!template) return { cmd: argv[0], args: argv.slice(1), cwd }
    const t2 = stdin && template[0] === 'docker' && template[1] === 'exec' ? [template[0], template[1], '-i', ...template.slice(2)] : template
    const full = t2.map((t) => t.replaceAll('{cwd}', cwd)).concat(argv)
    return { cmd: full[0], args: full.slice(1), cwd: undefined }
  }
}

/** Runs a command and collects its output. */
export function capture(spec, { timeoutMs = 600000, env, input } = {}) {
  return new Promise((resolve) => {
    const p = spawn(spec.cmd, spec.args, { cwd: spec.cwd, env: env || process.env, stdio: [input === undefined ? 'ignore' : 'pipe', 'pipe', 'pipe'] })
    let out = ''
    if (input !== undefined) {
      p.stdin.on('error', () => {})
      p.stdin.end(input)
    }
    const t = setTimeout(() => p.kill('SIGKILL'), timeoutMs)
    p.stdout.on('data', (d) => (out += d))
    p.stderr.on('data', (d) => (out += d))
    p.on('close', (code) => {
      clearTimeout(t)
      resolve({ code, out })
    })
    p.on('error', (e) => {
      clearTimeout(t)
      resolve({ code: -1, out: String(e) })
    })
  })
}

/** Runs a command and calls onLine for each line of stdout and stderr together. */
export function stream(spec, onLine, { timeoutMs = 3600000, env } = {}) {
  return new Promise((resolve) => {
    const p = spawn(spec.cmd, spec.args, { cwd: spec.cwd, env: env || process.env, stdio: ['ignore', 'pipe', 'pipe'] })
    const t = setTimeout(() => p.kill('SIGKILL'), timeoutMs)
    for (const s of [p.stdout, p.stderr]) readline.createInterface({ input: s }).on('line', onLine)
    p.on('close', (code) => {
      clearTimeout(t)
      // Give readline a tick to flush the last lines.
      setImmediate(() => resolve(code))
    })
    p.on('error', (e) => {
      clearTimeout(t)
      onLine(`spawn error: ${e}`)
      resolve(-1)
    })
  })
}
