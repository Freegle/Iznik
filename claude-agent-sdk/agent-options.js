'use strict'

/**
 * How the support agent is started: the same read-only tools, limits and environment whether a
 * volunteer is asking a question (support-agent.js) or a referral is getting its first pass
 * (first-pass.js). No requires, so CI's bare `node --test` can load it.
 */

// The dump keys are in this process's environment for the first pass's own use. The agent's CLI is
// a child process that would inherit them, so it gets a copy without them.
const SECRET_ENV = ['USERDUMP_INTERNAL_KEY', 'USERDUMP_API_KEY']

function agentEnv(env = process.env) {
  const out = {}
  for (const [k, v] of Object.entries(env)) {
    if (!SECRET_ENV.includes(k) && v !== undefined) out[k] = v
  }
  return out
}

const READ_ONLY_BUILTINS = ['Read', 'Grep', 'Glob']
const NEVER = ['Write', 'Edit', 'Bash', 'NotebookEdit']

/**
 * The query() options for a support agent run.
 *
 * @param {object} o
 * @param {string} o.model
 * @param {string} o.systemPrompt
 * @param {string[]} o.toolNames - the freegle MCP tools, by name.
 * @param {object} o.mcpServer - from createSdkMcpServer.
 * @param {string} o.codebase - the read-only code checkout; file reads stay inside it.
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
    // Keep file reads inside the codebase checkout (defence in depth alongside the narrowed ~/.claude
    // mount: the container only exposes the OAuth credential, not memory or transcripts).
    additionalDirectories: [codebase],
    maxTurns,
    cwd: codebase,
    env: agentEnv(),
    ...extra,
  }
}

module.exports = { agentEnv, agentOptions, SECRET_ENV }
