/**
 * Claude Agent for Freegle support queries.
 *
 * Two-tier approach:
 * 1. First use fact_query tools to get live data from the browser
 * 2. Fall back to codebase search if fact queries can't answer the question
 */

const Anthropic = require('@anthropic-ai/sdk')
const fs = require('fs')
const path = require('path')

// Initialize Anthropic client - uses ANTHROPIC_API_KEY env var
let anthropic = null

function getClient() {
  if (!anthropic) {
    anthropic = new Anthropic()
  }
  return anthropic
}

// Available fact query types (executed by browser with user's auth)
const FACT_QUERY_TYPES = {
  // User lookup
  find_user_by_email: { params: ['email'], returns: '{id, role, found}' },
  find_user_by_name: { params: ['name'], returns: '[{id, displayname, role}]' },
  get_user_profile: { params: ['userid'], returns: '{id, role, added, lastaccess, engagement, trustlevel, displayname}' },
  get_user_role: { params: ['userid'], returns: 'string' },

  // User activity
  get_last_login: { params: ['userid'], returns: 'ISO date or "never"' },
  get_last_activity: { params: ['userid'], returns: 'ISO date or "never"' },
  has_recent_activity: { params: ['userid', 'timerange'], returns: 'boolean' },
  count_user_posts: { params: ['userid', 'timerange'], returns: 'number' },
  get_user_messages: { params: ['userid', 'timerange'], returns: '[{id, type, subject, arrival, hasoutcome}]' },
  get_user_post_replies: { params: ['userid', 'timerange'], returns: 'number' },

  // User memberships
  get_user_groups: { params: ['userid'], returns: '[{groupid, groupname, role, joined}]' },
  get_user_teams: { params: ['userid'], returns: '[teamname]' },
  check_team_membership: { params: ['userid', 'teamname'], returns: 'boolean' },

  // Team/Admin queries
  list_teams: { params: [], returns: '[teamname]' },
  list_team_members: { params: ['teamname'], returns: '[{id, displayname, role}]' },

  // Group queries
  search_groups: { params: ['search'], returns: '[{id, name, region, membercount}]' },
  get_group_info: { params: ['groupid'], returns: '{id, name, region, membercount, founded, modcount}' },
  get_group_stats: { params: ['groupid', 'timerange'], returns: '{posts, taken}' },
  get_group_mods: { params: ['groupid'], returns: '[{id, displayname, role}]' },

  // Message/Post queries
  get_message_info: { params: ['messageid'], returns: '{id, type, subject, status, groupname, posted, outcome}' },
  get_message_history: { params: ['messageid'], returns: '[{action, timestamp, byuser}]' },
  search_messages: { params: ['search', 'groupid'], returns: '[{id, type, subject, status}]' },
  count_group_messages: { params: ['groupid', 'timerange'], returns: '{offers, wanteds, taken}' },

  // Error/Log queries
  count_errors: { params: ['userid', 'timerange'], returns: 'number' },
  has_errors: { params: ['userid', 'timerange'], returns: 'boolean' },
  get_error_summary: { params: ['userid', 'timerange'], returns: '[{statusCode, count}]' },
  get_error_types: { params: ['userid', 'timerange'], returns: '[{endpoint, method, count}]' },
  get_recent_errors: { params: ['userid', 'limit'], returns: '[{timestamp, statusCode, endpoint, method}]' },

  // System log queries
  count_api_calls: { params: ['userid', 'timerange'], returns: 'number' },
  count_logins: { params: ['userid', 'timerange'], returns: 'number' },
  get_user_actions: { params: ['userid', 'timerange'], returns: '[{type, subtype, timestamp, groupid}]' },
  get_login_history: { params: ['userid', 'limit'], returns: '[{timestamp, success, ip_hash}]' },

  // Moderation queries
  get_user_spam_score: { params: ['userid'], returns: '{score, reason_summary}' },
  is_user_banned: { params: ['userid'], returns: 'boolean' },
  get_user_warnings: { params: ['userid'], returns: '[{date, type, groupid}]' },
  get_pending_messages: { params: ['groupid'], returns: '[{id, subject, type, pending_since}]' },
}

// Codebase paths for search
const CODEBASE_PATHS = [
  '/app/codebase/iznik-nuxt3',
  '/app/codebase/iznik-server',
  '/app/codebase/iznik-server-go',
]

/**
 * Search codebase for a pattern (local execution).
 */
function searchCodebase(pattern, fileGlob = null) {
  const { execSync } = require('child_process')
  const results = []

  // Default extensions - must use multiple --include flags (brace expansion doesn't work in quotes)
  const defaultExtensions = ['js', 'vue', 'php', 'go', 'ts', 'json']
  let includeFlags
  if (fileGlob && fileGlob !== '*.{js,vue,php,go,ts}') {
    includeFlags = `--include="${fileGlob}"`
  } else {
    includeFlags = defaultExtensions.map(ext => `--include="*.${ext}"`).join(' ')
  }

  for (const basePath of CODEBASE_PATHS) {
    if (!fs.existsSync(basePath)) continue

    try {
      // Use grep to search
      const cmd = `grep -r -n -I ${includeFlags} "${pattern}" "${basePath}" 2>/dev/null | head -50`
      const output = execSync(cmd, { encoding: 'utf-8', timeout: 10000 })
      if (output.trim()) {
        results.push(...output.trim().split('\n').map(line => {
          const match = line.match(/^([^:]+):(\d+):(.*)$/)
          if (match) {
            return {
              file: match[1].replace('/app/codebase/', ''),
              line: parseInt(match[2]),
              content: match[3].substring(0, 200)
            }
          }
          return { raw: line.substring(0, 200) }
        }))
      }
    } catch (e) {
      // grep returns non-zero if no matches
    }
  }

  return results.slice(0, 20) // Limit results
}

/**
 * Read a file from codebase (local execution).
 */
function readCodebaseFile(filePath, startLine = 1, numLines = 50) {
  // Security: only allow reading from codebase paths
  const fullPath = filePath.startsWith('/app/codebase/')
    ? filePath
    : path.join('/app/codebase', filePath)

  const isAllowed = CODEBASE_PATHS.some(base => fullPath.startsWith(base))
  if (!isAllowed) {
    return { error: 'Access denied - can only read codebase files' }
  }

  if (!fs.existsSync(fullPath)) {
    return { error: 'File not found' }
  }

  try {
    const content = fs.readFileSync(fullPath, 'utf-8')
    const lines = content.split('\n')
    const start = Math.max(0, startLine - 1)
    const end = Math.min(lines.length, start + numLines)

    return {
      file: filePath,
      startLine: start + 1,
      endLine: end,
      totalLines: lines.length,
      content: lines.slice(start, end).map((line, i) => `${start + i + 1}: ${line}`).join('\n')
    }
  } catch (e) {
    return { error: e.message }
  }
}

/**
 * Build the tool definitions for Claude API.
 */
function buildTools() {
  return [
    // Primary tool: fact queries (executed by browser)
    {
      name: 'fact_query',
      description: `Query live Freegle data. USE THIS FIRST for any question about users, groups, teams, messages, or system status. Available queries: ${Object.keys(FACT_QUERY_TYPES).join(', ')}`,
      input_schema: {
        type: 'object',
        properties: {
          query: {
            type: 'string',
            description: 'The query type to execute',
            enum: Object.keys(FACT_QUERY_TYPES),
          },
          params: {
            type: 'object',
            description: 'Parameters for the query',
          },
        },
        required: ['query', 'params'],
      },
    },
    // Secondary tool: suggest new queries
    {
      name: 'suggest_fact_query',
      description: 'Suggest a new fact query type if none of the existing ones can answer the question.',
      input_schema: {
        type: 'object',
        properties: {
          name: { type: 'string', description: 'Proposed query name' },
          description: { type: 'string', description: 'What it would do' },
          params: { type: 'array', items: { type: 'string' }, description: 'Required parameters' },
          returns: { type: 'string', description: 'Return type' },
          rationale: { type: 'string', description: 'Why this is needed' },
        },
        required: ['name', 'description', 'params', 'returns', 'rationale'],
      },
    },
    // Fallback tool: search codebase (only if fact queries can't answer)
    {
      name: 'search_codebase',
      description: 'Search the Freegle codebase for patterns. ONLY use this if fact_query cannot answer the question (e.g., questions about how code works, implementation details).',
      input_schema: {
        type: 'object',
        properties: {
          pattern: { type: 'string', description: 'Search pattern (regex supported)' },
          file_glob: { type: 'string', description: 'File pattern e.g. "*.vue" or "*.php"', default: '*.{js,vue,php,go,ts}' },
        },
        required: ['pattern'],
      },
    },
    // Fallback tool: read codebase file
    {
      name: 'read_codebase_file',
      description: 'Read a specific file from the codebase. ONLY use this after search_codebase finds relevant files.',
      input_schema: {
        type: 'object',
        properties: {
          file_path: { type: 'string', description: 'Path to file (relative to codebase or absolute)' },
          start_line: { type: 'integer', description: 'Line to start reading from', default: 1 },
          num_lines: { type: 'integer', description: 'Number of lines to read', default: 50 },
        },
        required: ['file_path'],
      },
    },
  ]
}

/**
 * Build the system prompt.
 */
function buildSystemPrompt() {
  const queryList = Object.entries(FACT_QUERY_TYPES)
    .map(([name, def]) => `  - ${name}(${def.params.join(', ')}) → ${def.returns}`)
    .join('\n')

  return `You are a Freegle support assistant. You help moderators investigate user issues and understand the system.

## Tool Priority (IMPORTANT)

1. **ALWAYS use fact_query FIRST** for questions about:
   - Users (who they are, their activity, their groups)
   - Teams (who's on support team, mentors, etc.)
   - Groups (info, stats, moderators)
   - Messages/posts (status, history)
   - Errors and logs

2. **ONLY use search_codebase/read_codebase_file** when:
   - The question is about HOW the code works
   - You need implementation details
   - fact_query doesn't have the data you need

## Available Fact Queries
${queryList}

## Examples
- "Who's on the support team?" → Use list_team_members("Support")
- "Is Edward a mentor?" → Use find_user_by_name("Edward") to get ID, then check_team_membership(userid, "Mentors")
- "Tell me about user 12345" → Use get_user_profile(12345) directly
- "How does the spam filter work?" → Use search_codebase to find spam-related code

## IMPORTANT: Use IDs when you have them
- If you already have a user's ID from a previous query, use get_user_profile(userid) - don't search by name again
- The ID is more reliable than searching by name

## Response Style
- Be concise and helpful
- Use markdown formatting
- Don't ask for clarification - use the tools to find the answer`
}

/**
 * No-op warmup - kept for API compatibility.
 */
async function warmupSession(onStatus) {
  onStatus('Ready')
}

/**
 * Run a support query using Claude API with tools.
 * @param {string} question - The user's question
 * @param {function} requestFactQuery - Function to execute fact queries via browser
 * @param {function} onThinking - Callback for status updates
 * @param {array} conversationHistory - Previous messages in format [{role, content}]
 */
async function runAgent(question, requestFactQuery, onThinking, conversationHistory = []) {
  const suggestedQueries = []

  // Build messages array with history + new question
  const messages = [
    ...conversationHistory,
    { role: 'user', content: question }
  ]

  console.log('[Agent] Conversation history length:', conversationHistory.length)

  onThinking('Investigating...')
  console.log('[Agent] Starting query:', question.substring(0, 50))

  const client = getClient()
  const tools = buildTools()
  const systemPrompt = buildSystemPrompt()

  // Agentic loop - keep calling Claude until it gives a final answer
  let iterations = 0
  const maxIterations = 50

  while (iterations < maxIterations) {
    iterations++
    console.log(`[Agent] Iteration ${iterations}`)

    try {
      const response = await client.messages.create({
        model: 'claude-sonnet-4-20250514',
        max_tokens: 4096,
        system: systemPrompt,
        tools,
        messages,
      })

      console.log('[Agent] Response stop_reason:', response.stop_reason)

      // Check if Claude wants to use tools
      if (response.stop_reason === 'tool_use') {
        // Process tool calls
        const toolResults = []

        for (const block of response.content) {
          if (block.type === 'tool_use') {
            const toolName = block.name
            const toolInput = block.input

            console.log(`[Agent] Tool call: ${toolName}`, JSON.stringify(toolInput).substring(0, 100))
            onThinking(`→ ${toolName}(${JSON.stringify(toolInput).substring(0, 50)}...)`)

            let result

            if (toolName === 'fact_query') {
              // Execute via browser
              try {
                result = await requestFactQuery(toolInput.query, toolInput.params || {})
                console.log('[Agent] Fact query result:', JSON.stringify(result).substring(0, 100))
              } catch (e) {
                result = { error: e.message }
              }
            } else if (toolName === 'suggest_fact_query') {
              suggestedQueries.push(toolInput)
              result = { status: 'recorded', message: 'Suggestion noted' }
            } else if (toolName === 'search_codebase') {
              result = searchCodebase(toolInput.pattern, toolInput.file_glob || '*.{js,vue,php,go,ts}')
            } else if (toolName === 'read_codebase_file') {
              result = readCodebaseFile(toolInput.file_path, toolInput.start_line || 1, toolInput.num_lines || 50)
            } else {
              result = { error: 'Unknown tool' }
            }

            toolResults.push({
              type: 'tool_result',
              tool_use_id: block.id,
              content: JSON.stringify(result),
            })
          }
        }

        // Add assistant message and tool results to conversation
        messages.push({ role: 'assistant', content: response.content })
        messages.push({ role: 'user', content: toolResults })

      } else {
        // Claude is done - extract the final text answer
        let answer = ''
        for (const block of response.content) {
          if (block.type === 'text') {
            answer += block.text
          }
        }

        console.log('[Agent] Final answer length:', answer.length)
        return { answer, suggestedQueries }
      }

    } catch (error) {
      console.error('[Agent] Error:', error)
      throw error
    }
  }

  return { answer: 'This question required too many lookups to complete. Please try asking a more specific question, or break it into smaller parts.', suggestedQueries }
}

module.exports = { runAgent, warmupSession, FACT_QUERY_TYPES, searchCodebase }
