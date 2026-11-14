// The model, called through Anthropic's SDK.
//
// One request per post, no tools, a fixed output schema. The SDK is loaded only when
// a call is made, so the tests and every command that does not need a model run
// without it installed.

import { OUTPUT_SCHEMA, SYSTEM } from './prompt.mjs'

export async function createAnthropic(cfg) {
  const { default: Anthropic } = await import('@anthropic-ai/sdk')
  const client = new Anthropic()

  /** @returns {Promise<{ form: object, usage: object }>} */
  return async function ask(userMessage) {
    let resp
    try {
      resp = await client.messages.create({
        model: cfg.model,
        max_tokens: 1200,
        // Reading a post and filling in a form needs no working-out, and working-out
        // is billed as output.
        thinking: { type: 'disabled' },
        // The hour-long cache costs twice the input price to write and a tenth to
        // read. Laps are minutes apart, so the five-minute one would lapse between
        // them and be paid for again each time.
        system: [{ type: 'text', text: SYSTEM, cache_control: { type: 'ephemeral', ttl: '1h' } }],
        messages: [{ role: 'user', content: userMessage }],
        output_config: { effort: 'low', format: { type: 'json_schema', schema: OUTPUT_SCHEMA } },
      })
    } catch (e) {
      if (e instanceof Anthropic.RateLimitError) throw new Error(`model: rate limited (${e.message})`)
      if (e instanceof Anthropic.AuthenticationError) throw new Error('model: no valid credential')
      if (e instanceof Anthropic.APIError) throw new Error(`model: HTTP ${e.status} ${e.message}`)
      throw e
    }
    if (resp.stop_reason !== 'end_turn') throw new Error(`model: stopped with ${resp.stop_reason}`)
    const text = resp.content.find((b) => b.type === 'text')?.text
    if (!text) throw new Error('model: no text in the response')
    return {
      form: JSON.parse(text),
      usage: {
        model: resp.model,
        input: resp.usage.input_tokens ?? 0,
        cacheRead: resp.usage.cache_read_input_tokens ?? 0,
        cacheWrite: resp.usage.cache_creation_input_tokens ?? 0,
        output: resp.usage.output_tokens ?? 0,
      },
    }
  }
}
