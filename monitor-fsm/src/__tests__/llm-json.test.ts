import { describe, it, expect } from 'vitest'
import { extractJsonBlock, sanitizeLLMDecision } from '../llm-json.js'

/**
 * The FSM brain (a reasoning model like claude-opus-4-8) frequently wraps its
 * JSON decision in prose, which used to make ai-flower's JSON.parse throw
 * "Unexpected token 'I'" and abort the whole iteration with zero PRs. These
 * tests lock in the recovery: a prose preamble/epilogue no longer breaks it.
 */
describe('extractJsonBlock', () => {
  it('pulls a JSON object out of a natural-language preamble', () => {
    const raw = 'I need to move on. Here is my decision:\n{"actions": [], "contextUpdates": {}}'
    expect(extractJsonBlock(raw)).toBe('{"actions": [], "contextUpdates": {}}')
  })

  it('ignores braces inside string values', () => {
    const raw = 'The FSM ac {"reason": "fix the {broken} thing", "actions": []} trailing'
    expect(JSON.parse(extractJsonBlock(raw)!)).toEqual({
      reason: 'fix the {broken} thing',
      actions: [],
    })
  })

  it('returns null when there is no JSON', () => {
    expect(extractJsonBlock('just prose, no json here')).toBeNull()
  })
})

describe('sanitizeLLMDecision', () => {
  it('recovers the decision from a prose-wrapped response (the real failure)', () => {
    const raw =
      'I need to route this. My decision is:\n{"transition":"MOVE_ON","contextUpdates":{"note":"x"},"actions":[]}'
    const out = JSON.parse(sanitizeLLMDecision(raw))
    expect(out.transition).toBe('MOVE_ON')
    expect(out.contextUpdates).toEqual({ note: 'x' })
    expect(out.actions).toEqual([])
  })

  it('still repairs stringified contextUpdates/actions and fills defaults', () => {
    const raw = '{"transition":"X","contextUpdates":"{\\"a\\":1}","actions":"[{\\"name\\":\\"y\\"}]"}'
    const out = JSON.parse(sanitizeLLMDecision(raw))
    expect(out.contextUpdates).toEqual({ a: 1 })
    expect(out.actions).toEqual([{ name: 'y' }])
  })

  it('fills missing contextUpdates/actions with empty defaults', () => {
    const out = JSON.parse(sanitizeLLMDecision('{"transition":"Z"}'))
    expect(out.contextUpdates).toEqual({})
    expect(out.actions).toEqual([])
  })

  it('leaves clean JSON that needs no repair untouched', () => {
    const raw = '{"transition":"Z","contextUpdates":{},"actions":[]}'
    expect(sanitizeLLMDecision(raw)).toBe(raw)
  })

  it('returns the input unchanged when there is no JSON at all', () => {
    const raw = 'you have hit your usage limit'
    expect(sanitizeLLMDecision(raw)).toBe(raw)
  })
})
