/**
 * Which Claude account the FSM runs as.
 *
 * The brain and every `delegate_to_coder` / `delegate_parallel_tasks` spawn run
 * the local `claude` CLI and inherit this process's environment, so whatever
 * authenticates here authenticates all of them. That makes the environment the
 * single control point for the FSM's identity — and an easy place to get it
 * silently wrong, because a bad credential does not stop the run: iterations
 * still complete, they just do nothing.
 *
 * Three inputs matter:
 *
 * - `ANTHROPIC_API_KEY` is ALWAYS dropped. ../.env defines it for other tools
 *   and run-loop.sh exports it wholesale. If it reaches `claude` it bills that
 *   key rather than the subscription, and once its prepaid balance is gone
 *   every LLM call returns "Credit balance is too low" while the FSM keeps
 *   completing iterations with zero PRs.
 *
 * - `MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN` pins the FSM to a chosen account.
 *   It is namespaced deliberately: ../.env is read by docker-compose too, so a
 *   bare CLAUDE_CODE_OAUTH_TOKEN there would spread further than the FSM.
 *
 * - A `CLAUDE_CODE_OAUTH_TOKEN` already in the environment is honoured as-is,
 *   for callers that set it themselves.
 *
 * With none of them, `claude` falls back to the logged-in subscription session.
 * Note that a token present in an interactive Claude Code session is stripped
 * from the environment given to its Bash tool calls, so an FSM launched that
 * way inherits no token and lands on the session login.
 */

export type ClaudeAuthMode = 'pinned-token' | 'inherited-token' | 'session'

export interface ClaudeAuthResult {
  mode: ClaudeAuthMode
  /** Human-readable, safe to log — never contains the credential. */
  description: string
  /** True when an ANTHROPIC_API_KEY was found and removed. */
  droppedApiKey: boolean
}

/** Trim and treat blank as absent — an empty token is worse than none. */
function value(raw: string | undefined): string {
  return (raw ?? '').trim()
}

/**
 * Normalise Claude credentials on `env` in place and report what was chosen.
 * Call once, before the adapter is imported or any delegate is spawned.
 */
export function applyClaudeAuth(
  env: NodeJS.ProcessEnv = process.env
): ClaudeAuthResult {
  const droppedApiKey = value(env.ANTHROPIC_API_KEY) !== '' || 'ANTHROPIC_API_KEY' in env
  if ('ANTHROPIC_API_KEY' in env) delete env.ANTHROPIC_API_KEY

  const pinned = value(env.MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN)
  if (pinned) {
    env.CLAUDE_CODE_OAUTH_TOKEN = pinned
    return {
      mode: 'pinned-token',
      description: 'OAuth token from MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN',
      droppedApiKey,
    }
  }

  const inherited = value(env.CLAUDE_CODE_OAUTH_TOKEN)
  if (inherited) {
    return {
      mode: 'inherited-token',
      description: 'OAuth token inherited from the environment',
      droppedApiKey,
    }
  }

  // A blank CLAUDE_CODE_OAUTH_TOKEN is worse than an absent one: `claude` would
  // try to authenticate with it and fail, instead of using the session login.
  if ('CLAUDE_CODE_OAUTH_TOKEN' in env) delete env.CLAUDE_CODE_OAUTH_TOKEN

  return {
    mode: 'session',
    description: 'logged-in Claude Code subscription session',
    droppedApiKey,
  }
}
