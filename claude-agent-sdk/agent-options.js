'use strict'

/**
 * How the support agent is started: the same read-only tools, limits, environment and file-read
 * confinement whether a volunteer is asking a question (support-agent.js) or a referral is getting its
 * first pass (first-pass.js). Only Node built-ins, so CI's bare `node --test` can load it.
 */

const fs = require('node:fs')
const path = require('node:path')

// The agent's CLI is a child process. It gets only what it needs to run and to reach Claude: not the
// database password, the error tracker and forum tokens, the internal secret or the dump keys, which
// the tools use from this process and the agent has no business reading.
const ENV_ALLOW = /^(PATH|HOME|USER|LOGNAME|SHELL|LANG|LANGUAGE|TZ|TMPDIR|TERM|XDG_[A-Z_]+|LC_[A-Z_]+|NODE_[A-Z_]+|ANTHROPIC_[A-Z_]+|CLAUDE_[A-Z_]+|HTTPS?_PROXY|NO_PROXY|https?_proxy|no_proxy)$/
// Named so a test can prove they never get through, whatever the pattern above says.
const SECRET_ENV = ['USERDUMP_INTERNAL_KEY', 'USERDUMP_API_KEY']

function agentEnv(env = process.env) {
  const out = {}
  for (const [k, v] of Object.entries(env)) {
    if (v !== undefined && ENV_ALLOW.test(k) && !SECRET_ENV.includes(k)) out[k] = v
  }
  return out
}

const READ_ONLY_BUILTINS = ['Read', 'Grep', 'Glob']
const NEVER = ['Write', 'Edit', 'Bash', 'NotebookEdit']

// The real path of p (symlinks resolved), or of its nearest existing parent with the rest appended,
// so a path that does not exist yet cannot be used to step outside through a link.
function realish(p) {
  let dir = path.resolve(p)
  const rest = []
  for (;;) {
    try {
      return path.join(fs.realpathSync(dir), ...rest)
    } catch (_) {
      const parent = path.dirname(dir)
      if (parent === dir) return path.resolve(p)
      rest.unshift(path.basename(dir))
      dir = parent
    }
  }
}

/**
 * Whether a Read, Grep or Glob call stays inside the codebase checkout. Read names a file_path; Grep
 * and Glob may name a path (their root, which defaults to the working directory, the checkout), and a
 * Glob pattern may itself be absolute or climb with "..".
 *
 * @returns {string|null} why it is refused, or null when it is allowed.
 */
function outsideCodebase(toolName, input, codebase) {
  const root = realish(codebase)
  const inside = (p) => {
    const r = realish(path.isAbsolute(p) ? p : path.join(codebase, p))
    return r === root || r.startsWith(root + path.sep)
  }
  const i = input && typeof input === 'object' ? input : {}
  const paths = []
  if (toolName === 'Read') paths.push(i.file_path || '')
  if ((toolName === 'Grep' || toolName === 'Glob') && i.path) paths.push(i.path)
  if (toolName === 'Glob' && typeof i.pattern === 'string' && (path.isAbsolute(i.pattern) || i.pattern.split(/[\\/]/).includes('..'))) {
    paths.push(i.pattern.split(/[*?[{]/)[0] || '/')
  }
  for (const p of paths) {
    if (typeof p !== 'string' || !p || !inside(p)) return `${toolName} is limited to the code checkout`
  }
  return null
}

// A PreToolUse hook that refuses any file read outside the codebase. Hooks run whatever the permission
// mode, so this holds although the agent runs with permissions bypassed.
function confineReadsHook(codebase) {
  return async (input) => {
    const why = outsideCodebase(input && input.tool_name, input && input.tool_input, codebase)
    if (!why) return { continue: true }
    return { hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: why } }
  }
}

/**
 * The query() options for a support agent run.
 *
 * @param {object} o
 * @param {string} o.model
 * @param {string} o.systemPrompt
 * @param {string[]} o.toolNames - the freegle MCP tools, by name.
 * @param {object} o.mcpServer - from createSdkMcpServer.
 * @param {string} o.codebase - the read-only code checkout; file reads are confined to it.
 * @param {number} o.maxTurns
 * @param {string[]} [o.alsoDisallow] - more built-in tools to refuse.
 * @param {object} [o.extra] - anything else for this run (resume, outputFormat, abortController...).
 */
function agentOptions({ model, systemPrompt, toolNames, mcpServer, codebase, maxTurns, alsoDisallow = [], extra = {} }) {
  return {
    model,
    systemPrompt,
    mcpServers: { freegle: mcpServer },
    allowedTools: [...toolNames.map((n) => `mcp__freegle__${n}`), ...READ_ONLY_BUILTINS],
    // Read-only investigation; never let it try to edit or run anything.
    disallowedTools: [...NEVER, ...alsoDisallow],
    permissionMode: 'bypassPermissions',
    additionalDirectories: [codebase],
    maxTurns,
    cwd: codebase,
    env: agentEnv(),
    ...extra,
    // After extra, so nothing a caller passes can take the confinement away.
    hooks: { PreToolUse: [{ matcher: 'Read|Grep|Glob', hooks: [confineReadsHook(codebase)] }] },
  }
}

module.exports = { agentEnv, agentOptions, outsideCodebase, confineReadsHook, SECRET_ENV }
