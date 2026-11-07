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

// The CLI retries a failed call for about three minutes by default. The broker answers 503 when no
// account is free, so a volunteer waited that long for an error; two retries ride out a blip.
const DEFAULT_MAX_RETRIES = '2'

function agentEnv(env = process.env) {
  const out = {}
  for (const [k, v] of Object.entries(env)) {
    if (v !== undefined && ENV_ALLOW.test(k) && !SECRET_ENV.includes(k)) out[k] = v
  }
  if (!out.CLAUDE_CODE_MAX_RETRIES) out.CLAUDE_CODE_MAX_RETRIES = DEFAULT_MAX_RETRIES
  return out
}

// How hard the model thinks. Nearly all of an investigation's time is the model's own thinking, so this
// is the main lever on how long a volunteer waits. Medium is the default level for the current Opus.
const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max']
const DEFAULT_EFFORT = 'medium'

function agentEffort(env = process.env) {
  const level = String(env.SUPPORT_AI_EFFORT || '').trim().toLowerCase()
  return EFFORT_LEVELS.includes(level) ? level : DEFAULT_EFFORT
}

const READ_ONLY_BUILTINS = ['Read', 'Grep', 'Glob']
// Refused by name as well as left out of the tools option, so a change to either alone does not open one.
const NEVER = [
  'Write', 'Edit', 'Bash', 'NotebookEdit', 'Agent', 'Task', 'Skill', 'ReadMcpResource', 'ListMcpResources',
  'BashOutput', 'KillShell', 'TodoWrite', 'WebFetch', 'WebSearch',
]

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
  // The CLI expands "~" to HOME and shell-style variables before it reads, so a path that starts with "~"
  // or holds "$" or "%" can name a file this check cannot see. Refused outright, before the relative test.
  const expands = (p) => typeof p === 'string' && (p.startsWith('~') || /[$%]/.test(p))
  const candidates = [i.file_path, i.path]
  if (toolName === 'Glob') candidates.push(i.pattern)
  if (candidates.some(expands)) return `${toolName} is limited to the code checkout`
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
    // Only Read, Grep and Glob exist as built-in tools; everything else is absent, not just refused.
    tools: [...READ_ONLY_BUILTINS],
    allowedTools: [...toolNames.map((n) => `mcp__freegle__${n}`), ...READ_ONLY_BUILTINS],
    // Read-only investigation; never let it try to edit or run anything.
    disallowedTools: [...NEVER, ...alsoDisallow],
    permissionMode: 'bypassPermissions',
    additionalDirectories: [codebase],
    maxTurns,
    cwd: codebase,
    env: agentEnv(),
    effort: agentEffort(),
    ...extra,
    // After extra, so nothing a caller passes can take the confinement away.
    hooks: { PreToolUse: [{ matcher: 'Read|Grep|Glob', hooks: [confineReadsHook(codebase)] }] },
  }
}

module.exports = { agentEnv, agentEffort, agentOptions, outsideCodebase, confineReadsHook, SECRET_ENV }
