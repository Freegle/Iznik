import { describe, it, expect } from 'vitest'
import { applyClaudeAuth } from '../claude-auth.js'

// The brain and every delegate spawn inherit the driver's environment, so these
// few lines decide which account does all the work — and a wrong answer is
// silent: iterations still complete, they just achieve nothing.

const TOKEN = 'sk-ant-oat01-EXAMPLE-not-a-real-token'
const OTHER = 'sk-ant-oat01-EXAMPLE-inherited'

describe('applyClaudeAuth — ANTHROPIC_API_KEY is never left in place', () => {
  it('drops the key and reports having done so', () => {
    const env: NodeJS.ProcessEnv = { ANTHROPIC_API_KEY: 'sk-ant-api-EXAMPLE' }
    const result = applyClaudeAuth(env)

    expect('ANTHROPIC_API_KEY' in env).toBe(false)
    expect(result.droppedApiKey).toBe(true)
  })

  it('drops it even when an OAuth token is pinned', () => {
    const env: NodeJS.ProcessEnv = {
      ANTHROPIC_API_KEY: 'sk-ant-api-EXAMPLE',
      MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN: TOKEN,
    }
    applyClaudeAuth(env)

    expect('ANTHROPIC_API_KEY' in env).toBe(false)
    expect(env.CLAUDE_CODE_OAUTH_TOKEN).toBe(TOKEN)
  })

  it('reports no drop when there was no key', () => {
    expect(applyClaudeAuth({}).droppedApiKey).toBe(false)
  })
})

describe('applyClaudeAuth — pinning the FSM to a chosen account', () => {
  it('promotes MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN to CLAUDE_CODE_OAUTH_TOKEN', () => {
    const env: NodeJS.ProcessEnv = { MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN: TOKEN }
    const result = applyClaudeAuth(env)

    expect(env.CLAUDE_CODE_OAUTH_TOKEN).toBe(TOKEN)
    expect(result.mode).toBe('pinned-token')
  })

  it('overrides a token already in the environment', () => {
    const env: NodeJS.ProcessEnv = {
      MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN: TOKEN,
      CLAUDE_CODE_OAUTH_TOKEN: OTHER,
    }
    applyClaudeAuth(env)

    expect(env.CLAUDE_CODE_OAUTH_TOKEN).toBe(TOKEN)
  })

  it('trims surrounding whitespace, which .env values pick up easily', () => {
    const env: NodeJS.ProcessEnv = {
      MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN: `  ${TOKEN}\n`,
    }
    applyClaudeAuth(env)

    expect(env.CLAUDE_CODE_OAUTH_TOKEN).toBe(TOKEN)
  })
})

describe('applyClaudeAuth — inherited token and session fallback', () => {
  it('honours a token already in the environment', () => {
    const env: NodeJS.ProcessEnv = { CLAUDE_CODE_OAUTH_TOKEN: OTHER }
    const result = applyClaudeAuth(env)

    expect(env.CLAUDE_CODE_OAUTH_TOKEN).toBe(OTHER)
    expect(result.mode).toBe('inherited-token')
  })

  it('falls back to the session login when nothing is set', () => {
    const env: NodeJS.ProcessEnv = {}
    const result = applyClaudeAuth(env)

    expect(result.mode).toBe('session')
    expect('CLAUDE_CODE_OAUTH_TOKEN' in env).toBe(false)
  })

  // A blank value is worse than an absent one: `claude` tries to authenticate
  // with it and fails, instead of using the session login. An empty assignment
  // in .env (CLAUDE_CODE_OAUTH_TOKEN=) produces exactly this.
  it('clears a blank token rather than letting claude fail on it', () => {
    const env: NodeJS.ProcessEnv = { CLAUDE_CODE_OAUTH_TOKEN: '   ' }
    const result = applyClaudeAuth(env)

    expect('CLAUDE_CODE_OAUTH_TOKEN' in env).toBe(false)
    expect(result.mode).toBe('session')
  })

  it('ignores a blank pin and falls through to the inherited token', () => {
    const env: NodeJS.ProcessEnv = {
      MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN: '',
      CLAUDE_CODE_OAUTH_TOKEN: OTHER,
    }
    const result = applyClaudeAuth(env)

    expect(env.CLAUDE_CODE_OAUTH_TOKEN).toBe(OTHER)
    expect(result.mode).toBe('inherited-token')
  })
})

describe('applyClaudeAuth — the description is safe to log', () => {
  it('never contains the credential', () => {
    for (const env of [
      { MONITOR_FSM_CLAUDE_CODE_OAUTH_TOKEN: TOKEN },
      { CLAUDE_CODE_OAUTH_TOKEN: OTHER },
      {},
    ] as NodeJS.ProcessEnv[]) {
      const { description } = applyClaudeAuth(env)
      expect(description).not.toContain(TOKEN)
      expect(description).not.toContain(OTHER)
      expect(description.length).toBeGreaterThan(0)
    }
  })
})
