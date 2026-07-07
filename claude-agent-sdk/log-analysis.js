/**
 * Log Analysis module using Claude Agent SDK.
 *
 * Uses the Agent SDK's built-in session management and automatic compaction
 * to handle multi-turn conversations without token overflow.
 * Custom MCP tools query Loki via the pseudonymizer pipeline.
 */

const { query, tool, createSdkMcpServer } = require('@anthropic-ai/claude-agent-sdk')
const { z } = require('zod')

// MCP Interface URL (for Loki queries via pseudonymizer pipeline)
const MCP_INTERFACE_URL = process.env.MCP_INTERFACE_URL || 'http://freegle-mcp-interface:8080'

// Pseudonymizer URL (for direct DB queries — same container handles both Loki and DB)
const PSEUDONYMIZER_URL = process.env.PSEUDONYMIZER_URL || 'http://freegle-mcp-pseudonymizer:8080'

// Go API URL for database queries (via apiv2-live which connects to production DB)
const API_URL = process.env.API_URL || 'http://freegle-apiv2-live:8192'

// Track sanitizer session IDs per agent session (for token translation)
const sessionSanitizerMap = new Map()

/**
 * Create the Loki query tool.
 * The sanitizerSessionId is passed via closure so the pseudonymizer
 * can translate tokens (USER_xxx) to real values in queries.
 */
function createLokiTool(getSanitizerSessionId) {
  return tool(
    'loki_query',
    'Query application logs from Loki using LogQL. Returns pseudonymized log entries. ' +
      'Use this to investigate API errors, user activity, slow requests, etc. ' +
      'USER_xxx and EMAIL_xxx tokens in your query are automatically translated to real values.',
    {
      query: z
        .string()
        .describe(
          'LogQL query. Examples: {app="freegle"} |= "error", ' +
            '{app="freegle", source="api"} | json | user_id = "USER_abc12345", ' +
            '{app="freegle"} | json | status_code >= 400'
        ),
      start: z
        .string()
        .optional()
        .describe('Start time - relative (1h, 24h, 7d) or ISO 8601. Default: 24h'),
      limit: z
        .number()
        .optional()
        .describe('Maximum results. Default: 20, max: 50'),
    },
    async (args) => {
      try {
        const sessionId = getSanitizerSessionId() || 'anonymous'
        const response = await fetch(`${MCP_INTERFACE_URL}/tools/loki_query`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            sessionId,
            query: args.query,
            start: args.start || '24h',
            limit: Math.min(args.limit || 20, 50),
          }),
        })

        if (!response.ok) {
          return {
            content: [
              {
                type: 'text',
                text: `Loki query failed with status ${response.status}`,
              },
            ],
            isError: true,
          }
        }

        const lokiData = await response.json()

        // Extract log entries (not the full Loki stats envelope)
        const entries = []
        for (const stream of lokiData.data?.result || []) {
          for (const [ts, line] of stream.values || []) {
            entries.push(line)
          }
        }

        const summary = {
          entryCount: entries.length,
          entries: entries.slice(0, 20),
          labels: lokiData.data?.result?.[0]?.stream || {},
        }

        return {
          content: [{ type: 'text', text: JSON.stringify(summary) }],
        }
      } catch (error) {
        return {
          content: [
            {
              type: 'text',
              text: `MCP pipeline error: ${error.message}`,
            },
          ],
          isError: true,
        }
      }
    },
    { annotations: { readOnlyHint: true } }
  )
}

/**
 * Create the database query tool.
 * Executes validated, pseudonymized SQL queries against the Freegle database.
 */
function createDbQueryTool(getSanitizerSessionId) {
  return tool(
    'db_query',
    'Query the Freegle database with SQL. Only SELECT queries on whitelisted tables/columns are allowed. ' +
      'Sensitive data (emails, names, IPs) is automatically pseudonymized in results. ' +
      'Use USER_xxx tokens in WHERE clauses — they are translated to real values automatically. ' +
      'LIKE filtering on sensitive columns is blocked. Max 500 rows.',
    {
      query: z
        .string()
        .describe(
          'SQL SELECT query. Use USER_xxx tokens for user IDs. ' +
            'Examples: SELECT id, subject, type, arrival FROM messages WHERE fromuser = USER_xxx ORDER BY arrival DESC LIMIT 20'
        ),
    },
    async (args) => {
      try {
        const sessionId = getSanitizerSessionId() || 'anonymous'
        const response = await fetch(`${PSEUDONYMIZER_URL}/api/db/query`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ sessionId, query: args.query }),
        })

        if (!response.ok) {
          const err = await response.json().catch(() => ({ error: 'Unknown error' }))
          return {
            content: [{ type: 'text', text: `DB query error: ${err.error || response.status}` }],
            isError: true,
          }
        }

        const data = await response.json()
        return {
          content: [{ type: 'text', text: JSON.stringify(data) }],
        }
      } catch (error) {
        return {
          content: [{ type: 'text', text: `DB query error: ${error.message}` }],
          isError: true,
        }
      }
    },
    { annotations: { readOnlyHint: true } }
  )
}

/**
 * Create the database schema discovery tool.
 * Returns available tables, columns, joins, and example queries.
 */
function createDbSchemaTool() {
  return tool(
    'db_schema',
    'Get the database schema showing available tables, columns (with privacy classification), joins, and example queries. ' +
      'Use this first to understand what data is available before writing SQL queries.',
    {},
    async () => {
      try {
        const response = await fetch(`${PSEUDONYMIZER_URL}/api/db/schema`)
        if (!response.ok) {
          return { content: [{ type: 'text', text: 'Failed to fetch schema' }], isError: true }
        }
        const schema = await response.json()
        return { content: [{ type: 'text', text: JSON.stringify(schema) }] }
      } catch (error) {
        return { content: [{ type: 'text', text: `Schema error: ${error.message}` }], isError: true }
      }
    },
    { annotations: { readOnlyHint: true } }
  )
}

/**
 * Build the system prompt with optional user context.
 */
function buildSystemPrompt(userQuery) {
  const userTokens = userQuery.match(/USER_[a-f0-9]{8}/g) || []
  let userContext = ''
  if (userTokens.length > 0) {
    userContext =
      `\n\n## Current Investigation\n` +
      `The moderator has selected a user: **${userTokens[0]}**. ` +
      `When they say "they", "this user", "them", etc., they mean ${userTokens[0]}. ` +
      `To find this user's activity, use: {app="freegle", source="api"} |= "${userTokens[0]}" ` +
      `(the system will translate the token to the real user ID automatically). ` +
      `Do NOT ask the moderator to identify the user — they already have.\n`
  }

  return (
    'You are a Freegle support assistant with access to application logs via Loki. ' +
    'You help moderators investigate user issues by querying real log data.\n\n' +
    '## Two data sources\n' +
    '**Loki** (recent API logs): transient, shows what happened in the last few days/weeks\n' +
    '**Database** (persistent): long-lived data — user profiles, messages, memberships, chat history, mod logs\n\n' +
    '## When to use each tool\n' +
    '- **db_schema** — Call first to discover available tables, columns, and joins\n' +
    '- **db_query** — For user profiles, posting history, memberships, chat messages, mod logs, login history\n' +
    '- **loki_query** — For recent API request patterns, errors, performance issues, browser events\n\n' +
    '## How to use db_query\n' +
    '- Only SELECT queries on whitelisted tables/columns\n' +
    '- Sensitive columns (emails, names, IPs) are automatically pseudonymized in results\n' +
    '- Use USER_xxx tokens in WHERE clauses — they are translated automatically\n' +
    '- LIKE filtering on sensitive columns is blocked (anti-inference protection)\n' +
    '- Max 500 rows per query\n\n' +
    '## How to use loki_query\n' +
    '- Logs are JSON with fields: endpoint, user_id, ip, duration_ms, status_code, trace_id\n' +
    '- Label filters: {app="freegle", source="api"} for API logs, {source="client"} for browser events\n' +
    '- Line filter: |= "text" for substring match\n' +
    '- JSON pipeline: | json | field = "value" for structured field queries\n' +
    '- User data in results is pseudonymized (USER_xxx, EMAIL_xxx, NAME_xxx, IP_xxx)\n' +
    '- To find a specific user, use their USER_xxx token — the system translates it automatically\n' +
    '- Keep queries focused — request 20 results max per query, refine if needed\n' +
    userContext +
    '\n## Response style\n' +
    '- Be concise and use markdown\n' +
    '- Summarize findings clearly\n' +
    '- If you find errors, explain what they mean\n' +
    '- Suggest possible causes and next steps\n' +
    '- Use pseudonymized tokens (USER_xxx, EMAIL_xxx) when referring to people'
  )
}

/**
 * Run a log analysis query using the Claude Agent SDK.
 *
 * @param {string} userQuery - The pseudonymized query from the frontend
 * @param {string|null} sanitizerSessionId - Session ID for token translation
 * @param {string|null} agentSessionId - Session ID for conversation continuity
 * @param {function|null} onProgress - Optional callback for progress updates: (type, message) => void
 * @returns {Promise<{analysis: string, costUsd: number, usage: object, claudeSessionId: string, isNewSession: boolean}>}
 */
async function runLogAnalysis(
  userQuery,
  sanitizerSessionId,
  agentSessionId,
  onProgress
) {
  const progress = onProgress || (() => {})
  const isNewSession = !agentSessionId

  // Store sanitizer session for the loki tool closure
  const effectiveSessionId = agentSessionId || `agent_${Date.now()}`
  if (sanitizerSessionId) {
    sessionSanitizerMap.set(effectiveSessionId, sanitizerSessionId)
  }

  // Create MCP server with Loki tool
  const getSessionId = () => sessionSanitizerMap.get(effectiveSessionId) || sanitizerSessionId

  const lokiTool = createLokiTool(getSessionId)
  const dbQueryTool = createDbQueryTool(getSessionId)
  const dbSchemaTool = createDbSchemaTool()

  const mcpServer = createSdkMcpServer({
    name: 'freegle',
    version: '1.0.0',
    tools: [lokiTool, dbQueryTool, dbSchemaTool],
  })

  const systemPrompt = buildSystemPrompt(userQuery)

  let analysis = ''
  let costUsd = 0
  let usage = {}
  let resultSessionId = effectiveSessionId

  const queryOptions = {
    model: 'claude-sonnet-4-20250514',
    systemPrompt,
    mcpServers: { freegle: mcpServer },
    allowedTools: ['mcp__freegle__loki_query', 'mcp__freegle__db_query', 'mcp__freegle__db_schema'],
    permissionMode: 'bypassPermissions',
    maxTurns: 15,
  }

  // Resume existing session or start new
  if (agentSessionId) {
    queryOptions.resume = agentSessionId
  }

  progress('status', 'Starting analysis...')

  for await (const message of query({
    prompt: userQuery,
    options: queryOptions,
  })) {
    if (message.type === 'assistant') {
      // Report tool calls as progress
      for (const block of message.message?.content || []) {
        if (block.type === 'tool_use') {
          const toolName = block.name.replace('mcp__freegle_logs__', '')
          const input = block.input || {}
          const desc = toolName === 'loki_query'
            ? `Querying logs: ${(input.query || '').substring(0, 80)}`
            : `${toolName}: ${JSON.stringify(input).substring(0, 80)}`
          progress('tool', desc)
          console.log(
            `[LogAnalysis] Tool: ${block.name}`,
            JSON.stringify(block.input).substring(0, 120)
          )
        }
        if (block.type === 'text' && block.text) {
          // Partial thinking/text from Claude
          progress('thinking', block.text.substring(0, 100))
        }
      }
    }

    if (message.type === 'result') {
      if (message.subtype === 'success') {
        analysis = message.result || ''
        costUsd = message.total_cost_usd || 0
        usage = {
          inputTokens: message.usage?.input_tokens || 0,
          outputTokens: message.usage?.output_tokens || 0,
          cacheCreation: message.usage?.cache_creation_input_tokens || 0,
          cacheRead: message.usage?.cache_read_input_tokens || 0,
          durationMs: message.duration_ms || 0,
        }
        resultSessionId = message.sessionId || effectiveSessionId
      } else {
        // Error result
        const errors = message.errors?.map((e) => e.message).join('; ') || 'Unknown error'
        analysis = `Log analysis error: ${errors}`
        console.error(`[LogAnalysis] Error:`, errors)
      }
    }
  }

  console.log(
    `[LogAnalysis] Done. Cost: $${costUsd.toFixed(4)}, tokens: ${usage.inputTokens}+${usage.outputTokens}`
  )

  return {
    analysis,
    costUsd,
    usage,
    claudeSessionId: resultSessionId,
    isNewSession,
  }
}

module.exports = { runLogAnalysis }
