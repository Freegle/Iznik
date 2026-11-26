// The model, called through the Claude Code command line instead of an API key.
//
// Same question, same form, same checks afterwards. It exists because the monitor
// runs on the machine's Claude login today, and this lets the two share it. The call
// is made with every tool switched off and no settings, hooks or MCP servers loaded,
// so the model can still only read and fill in the form.
//
// The command and its flags are fixed. The forum text reaches it on standard input
// only, never as an argument.

import { spawn } from 'node:child_process'
import { tmpdir } from 'node:os'
import { OUTPUT_SCHEMA, SYSTEM } from './prompt.mjs'

export function createClaudeCli(cfg, { bin = 'claude', timeoutMs = 120000 } = {}) {
  const args = [
    '-p', '--model', cfg.model, '--system-prompt', SYSTEM, '--tools', '', '--strict-mcp-config', '--disable-slash-commands',
    '--setting-sources', '', '--effort', 'low', '--output-format', 'json', '--json-schema', JSON.stringify(OUTPUT_SCHEMA),
  ]
  return function ask(userMessage) {
    return new Promise((resolve, reject) => {
      const child = spawn(bin, args, {
        // Started outside the repository. From inside it the command line loads the
        // project's own instructions into every call, about twelve thousand tokens a post when measured.
        cwd: tmpdir(), stdio: ['pipe', 'pipe', 'pipe'], timeout: timeoutMs, killSignal: 'SIGKILL',
      })
      let out = ''
      let err = ''
      child.stdout.on('data', (d) => (out += d))
      child.stderr.on('data', (d) => (err += d))
      child.on('error', (e) => reject(new Error(`model: ${e.message}`)))
      child.on('close', (code) => {
        let r
        try {
          r = JSON.parse(out)
        } catch {
          return reject(new Error(`model: exit ${code}, ${(err || out).slice(0, 200)}`))
        }
        if (r.is_error || !r.structured_output) return reject(new Error(`model: ${r.subtype ?? 'no form returned'}`))
        const u = r.usage ?? {}
        resolve({
          form: r.structured_output,
          usage: {
            model: Object.keys(r.modelUsage ?? {})[0] ?? cfg.model,
            input: u.input_tokens ?? 0,
            cacheRead: u.cache_read_input_tokens ?? 0,
            cacheWrite: u.cache_creation_input_tokens ?? 0,
            output: u.output_tokens ?? 0,
          },
        })
      })
      child.stdin.end(userMessage)
    })
  }
}
