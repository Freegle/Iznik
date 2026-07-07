/**
 * MCP Interface Service (Container 2)
 *
 * Stateless proxy between Claude (Container 1) and Pseudonymizer (Container 3).
 * This container has NO access to:
 * - The pseudonymization key
 * - The real PII values
 * - Direct Loki access
 *
 * It simply forwards MCP tool calls and returns pseudonymized results.
 * If compromised, an attacker can only issue pseudonymized queries.
 */

const express = require('express')

const app = express()
app.use(express.json())

const PSEUDONYMIZER_URL = process.env.PSEUDONYMIZER_URL || 'http://pseudonymizer:8080'

// Health check
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'mcp-interface',
    pseudonymizerUrl: PSEUDONYMIZER_URL,
  })
})

/**
 * MCP tool: loki_query
 * Query application logs from Loki.
 *
 * POST /tools/loki_query
 * Body: { sessionId, query, start?, end?, limit? }
 */
app.post('/tools/loki_query', async (req, res) => {
  return handleLokiQuery(req.body, res)
})

/**
 * List available MCP tools.
 * GET /tools
 */
app.get('/tools', (req, res) => {
  res.json({
    tools: [
      {
        name: 'loki_query',
        description: 'Query application logs from Loki. Returns log entries matching your query. Email addresses, user IDs, and IP addresses in results are replaced with consistent tokens (e.g., EMAIL_a8f3c2) for privacy.',
        inputSchema: {
          type: 'object',
          properties: {
            sessionId: {
              type: 'string',
              description: 'Session ID from query sanitization. Required for all queries.',
            },
            query: {
              type: 'string',
              description: 'LogQL query. Examples: {app="freegle"} |= "error", {app="freegle"} | json | level="error"',
            },
            start: {
              type: 'string',
              description: 'Start time - relative (1h, 24h, 7d) or ISO 8601. Default: 1h',
            },
            end: {
              type: 'string',
              description: 'End time - relative or ISO 8601. Default: now',
            },
            limit: {
              type: 'integer',
              description: 'Maximum results. Default: 100',
            },
          },
          required: ['sessionId', 'query'],
        },
      },
    ],
  })
})

/**
 * MCP tool call handler (generic endpoint).
 * POST /mcp/call
 * Body: { tool, params }
 */
/**
 * Forward a loki_query to the pseudonymizer.
 * Shared logic used by both /tools/loki_query and /mcp/call.
 */
async function handleLokiQuery(params, res) {
  const { sessionId, query, start, end, limit } = params

  if (!sessionId) {
    return res.status(400).json({
      error: 'SESSION_REQUIRED',
      message: 'sessionId is required for all queries',
    })
  }

  if (!query) {
    return res.status(400).json({
      error: 'QUERY_REQUIRED',
      message: 'LogQL query is required',
    })
  }

  try {
    const response = await fetch(`${PSEUDONYMIZER_URL}/query`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        sessionId,
        query,
        start: start || '1h',
        end,
        limit: limit || 100,
      }),
    })

    if (!response.ok) {
      const error = await response.json().catch(() => ({ error: 'Unknown error' }))
      return res.status(response.status).json(error)
    }

    const data = await response.json()
    res.json(data)
  } catch (error) {
    console.error('Pseudonymizer error:', error.message)
    res.status(503).json({
      error: 'PSEUDONYMIZER_UNAVAILABLE',
      message: 'Unable to connect to pseudonymizer service',
    })
  }
}

app.post('/mcp/call', async (req, res) => {
  const { tool, params, name, arguments: args } = req.body

  // Support both {tool, params} and {name, arguments} formats
  const toolName = tool || name
  const toolParams = params || args

  if (!toolName) {
    return res.status(400).json({ error: 'Tool name is required' })
  }

  switch (toolName) {
    case 'loki_query':
      return handleLokiQuery(toolParams || {}, res)

    default:
      return res.status(404).json({
        error: 'UNKNOWN_TOOL',
        message: `Tool "${toolName}" not found`,
        availableTools: ['loki_query'],
      })
  }
})

const PORT = process.env.PORT || 8080
app.listen(PORT, () => {
  console.log(`MCP Interface service listening on port ${PORT}`)
  console.log(`Pseudonymizer URL: ${PSEUDONYMIZER_URL}`)
  console.log(`Available tools: loki_query`)
})

module.exports = { app }
