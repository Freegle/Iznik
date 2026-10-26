/**
 * Freegle support agent (de-anonymised, direct-access).
 *
 * Drives Claude via @anthropic-ai/claude-agent-sdk's query() — the same code
 * path as the real Claude Code CLI, so it works with an ANTHROPIC_API_KEY (api),
 * a CLAUDE_CODE_OAUTH_TOKEN from `claude setup-token` (subscription, headless), or a
 * mounted ~/.claude session - chosen purely by which credential is present (see
 * auth.js driverMode). Streams thinking + tool progress + cost via onProgress.
 */

const { query, createSdkMcpServer } = require('@anthropic-ai/claude-agent-sdk')
const { buildTools, audit } = require('./tools')
const { driverMode, billableCostUsd, FREEGLE_API_URL } = require('./auth')
const path = require('path')
const { systemPrompt, reviewPrompt, hasSuggestedReply } = require('./prompt')
const { buildPrompt } = require('./images')
const { withLinkWarning } = require('./links')
const { subscriptionToken, fetchQuota, quotaFields, transcriptEntries, serialiseTranscript, recordRun } = require('./run-record')
// Where runs are recorded: the same Go API that verifies the caller.
const API_URL = process.env.API_URL || FREEGLE_API_URL

// Use an unpinned alias, not a dated snapshot: snapshots get retired and then
// the SDK 404s on the model. On the Claude subscription (session mode) we can
// use Opus. Override with SUPPORT_AI_MODEL if needed.
const MODEL = process.env.SUPPORT_AI_MODEL || 'opus'
const CODEBASE = process.env.CODEBASE_DIR || '/app/codebase'

/**
 * @param {object} o
 * @param {string} o.query        the support question
 * @param {number} o.userId       selected member id (0 if none)
 * @param {string} o.jwt          caller JWT (used for get_user_dump against the Go API)
 * @param {string|null} o.agentSessionId  resume id for multi-turn continuity
 * @param {function} o.onProgress (type, message) => void   type: thinking|tool|status
 * @param {Array} o.images        validated screenshots (images.js), shown to the model with the question
 */
async function runSupportQuery({ query: userQuery, userId, jwt, agentSessionId, onProgress, modId, modEmail, images }) {
  const progress = onProgress || (() => {})
  const isNewSession = !agentSessionId

  // Audit trail: record who is investigating whom, and the question, at session start.
  audit({ mod: modId, modEmail, target: userId || 0, tool: 'session', question: String(userQuery || '').slice(0, 200), screenshots: (images || []).length })

  const { tools, names, cleanup } = buildTools({ jwt, userId: userId || 0, modId, modEmail, progress })
  const mcpServer = createSdkMcpServer({ name: 'freegle', version: '2.0.0', tools })

  const options = {
    model: MODEL,
    systemPrompt: systemPrompt(userId, CODEBASE),
    mcpServers: { freegle: mcpServer },
    allowedTools: [
      ...names.map((n) => `mcp__freegle__${n}`),
      'Read',
      'Grep',
      'Glob',
    ],
    // Read-only investigation; never let it try to edit/run code.
    disallowedTools: ['Write', 'Edit', 'Bash', 'NotebookEdit'],
    permissionMode: 'bypassPermissions',
    // Keep file reads inside the codebase checkout (defence in depth alongside
    // the narrowed ~/.claude mount — the container only exposes the OAuth
    // credential, not memory/transcripts).
    additionalDirectories: [CODEBASE],
    // A real investigation fans out: dump, several SQL queries, Sentry across
    // ~5 projects, Loki, Discourse. 20 ran out mid-way (error_max_turns), so
    // give it real headroom. Tunable via SUPPORT_AI_MAX_TURNS.
    maxTurns: Number(process.env.SUPPORT_AI_MAX_TURNS || 80),
    cwd: CODEBASE,
  }
  if (agentSessionId) options.resume = agentSessionId

  let analysis = ''
  let costUsd = 0
  let usage = {}
  let resultSessionId = agentSessionId || null
  let runId = null
  let model = MODEL
  let status = 'Success'
  let errorText = ''
  const transcript = []
  const started = Date.now()

  // The subscription's utilisation before and after, so each run carries what
  // it used. Fetched alongside the first model call rather than ahead of it,
  // so the volunteer does not wait for it.
  const quotaToken = subscriptionToken(driverMode())
  const quotaBefore = fetchQuota(quotaToken)

  // One model turn: stream it, collect the answer. Used for the investigation and again for the
  // fact-check, which resumes the same session so every tool result is still in front of the model.
  async function drive(promptArg, turnOptions, isReview) {
    for await (const message of query({ prompt: promptArg, options: turnOptions })) {
      transcript.push(...transcriptEntries(message))
      if (message.type === 'system' && message.subtype === 'init' && message.model) {
        model = message.model
      }
      if (message.type === 'assistant') {
        for (const block of message.message?.content || []) {
          if (block.type === 'tool_use') {
            progress('tool', `${block.name.replace(/^mcp__freegle__/, '')} ${JSON.stringify(block.input).slice(0, 100)}`)
          } else if (block.type === 'text' && block.text) {
            progress('thinking', block.text)
          }
        }
      } else if (message.type === 'result') {
        if (message.subtype === 'success') {
          analysis = withLinkWarning(message.result || '', path.join(CODEBASE, 'iznik-nuxt3', 'pages'))
          // The fact-check is extra work on top of the investigation, so its cost and tokens add to it.
          const turnUsage = {
            inputTokens: message.usage?.input_tokens || 0,
            outputTokens: message.usage?.output_tokens || 0,
            cacheCreation: message.usage?.cache_creation_input_tokens || 0,
            cacheRead: message.usage?.cache_read_input_tokens || 0,
            durationMs: message.duration_ms || 0,
          }
          const turnCost = billableCostUsd(driverMode(), message.total_cost_usd)
          if (isReview) {
            costUsd = (costUsd || 0) + (turnCost || 0)
            for (const k of Object.keys(turnUsage)) usage[k] = (usage[k] || 0) + turnUsage[k]
          } else {
            costUsd = turnCost
            usage = turnUsage
          }
          resultSessionId = message.session_id || resultSessionId
        } else {
          status = 'Error'
          errorText = (message.errors || []).map((e) => (e && e.message) || String(e)).join('; ') || message.subtype
          analysis = `Investigation error: ${errorText}`
          resultSessionId = message.session_id || resultSessionId
        }
      }
    }
  }

  progress('status', `Investigating (driver=${driverMode()})…`)
  try {
    await drive(buildPrompt(userQuery, images, agentSessionId), options, false)

    // A draft reply is read out to a member under a volunteer's name, so it gets a second look before the
    // volunteer sees it: friendly and human, and every fact traced to something that was actually looked up.
    // If the check itself fails the original draft is kept and says so, rather than losing the answer.
    if (status === 'Success' && resultSessionId && hasSuggestedReply(analysis)) {
      progress('status', 'Checking the draft reply against what was found…')
      const firstAnalysis = analysis // already link-checked
      try {
        await drive(reviewPrompt(), { ...options, resume: resultSessionId }, true)
        if (!hasSuggestedReply(analysis) || status !== 'Success') throw new Error('the check did not return a reply')
      } catch (e) {
        status = 'Success'
        errorText = ''
        analysis = firstAnalysis +
          '\n\n**Not fact-checked.** The automatic check of this draft did not complete, so check each fact in it yourself before sending.'
      }
    }
  } catch (e) {
    status = 'Error'
    errorText = e.message || String(e)
    throw e
  } finally {
    cleanup()
    // Record even a run that threw, so failures can be found as well as poor
    // answers. The run id goes back to the browser for the thumbs up/down.
    runId = await recordRun({
      apiUrl: API_URL,
      jwt,
      run: {
        userid: userId || 0,
        sessionid: resultSessionId || '',
        query: String(userQuery || ''),
        analysis,
        transcript: serialiseTranscript(transcript),
        status,
        error: errorText,
        driver: driverMode(),
        model,
        input_tokens: usage.inputTokens || 0,
        output_tokens: usage.outputTokens || 0,
        cache_creation_tokens: usage.cacheCreation || 0,
        cache_read_tokens: usage.cacheRead || 0,
        duration_ms: usage.durationMs || Date.now() - started,
        cost_usd: costUsd,
        ...quotaFields(await quotaBefore, await fetchQuota(quotaToken)),
      },
    })
  }

  return { analysis, costUsd, usage, claudeSessionId: resultSessionId, isNewSession, driver: driverMode(), runId }
}

module.exports = { runSupportQuery, driverMode }
