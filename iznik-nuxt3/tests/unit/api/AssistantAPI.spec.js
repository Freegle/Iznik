import { describe, it, expect, vi, beforeEach } from 'vitest'
import AssistantAPI, { parseSse, ANON_KEY } from '~/api/AssistantAPI'

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ auth: { jwt: 'jwt1', persistent: null } }),
}))

function streamOf(text) {
  const enc = new TextEncoder()
  const bytes = enc.encode(text)
  let sent = false
  return {
    getReader: () => ({
      read: async () => {
        if (sent) return { done: true }
        sent = true
        return { value: bytes, done: false }
      },
    }),
  }
}

describe('AssistantAPI', () => {
  beforeEach(() => {
    localStorage.removeItem(ANON_KEY)
  })

  it('parses server-sent events', () => {
    const seen = []
    parseSse('event: delta\ndata: {"text":"hi"}', (e, d) => seen.push([e, d]))
    expect(seen).toEqual([['delta', { text: 'hi' }]])
  })

  it('streams deltas, stores the anonymous token and returns the turn', async () => {
    const api = new AssistantAPI({ public: { APIv2: 'http://api' } })
    const body =
      'event: identity\ndata: {"anon":"tok.sig"}\n\nevent: delta\ndata: {"text":"Hel"}\n\nevent: delta\ndata: {"text":"lo"}\n\nevent: turn\ndata: {"conversation":"c1","say":"Hello","state":"HUB"}\n\n'
    global.fetch = vi.fn(async (url, opts) => {
      expect(url).toBe('http://api/assistant/turn')
      expect(opts.headers.Authorization).toBe('"jwt1"')
      return {
        ok: true,
        headers: { get: () => 'tok.sig' },
        body: streamOf(body),
      }
    })
    const deltas = []
    const turn = await api.turn(
      { tap: 'give' },
      { onDelta: (t) => deltas.push(t) }
    )
    expect(deltas.join('')).toBe('Hello')
    expect(turn.conversation).toBe('c1')
    expect(localStorage.getItem(ANON_KEY)).toBe('tok.sig')
  })

  it('throws on an error event or a bad status', async () => {
    const api = new AssistantAPI({ public: { APIv2: 'http://api' } })
    global.fetch = vi.fn(async () => ({
      ok: true,
      headers: { get: () => null },
      body: streamOf('event: error\ndata: {"message":"nope"}\n\n'),
    }))
    await expect(api.turn({ text: 'x' })).rejects.toThrow('nope')
    global.fetch = vi.fn(async () => ({
      ok: false,
      status: 500,
      headers: { get: () => null },
      body: null,
    }))
    await expect(api.turn({ text: 'x' })).rejects.toThrow('500')
  })

  it('follows a stream that breaks before the turn with one resume', async () => {
    const api = new AssistantAPI({ public: { APIv2: 'http://api' } })
    const calls = []
    global.fetch = vi.fn(async (url, opts) => {
      calls.push(JSON.parse(opts.body))
      if (calls.length === 1) {
        // Only the start of a reply, then the connection dies.
        return {
          ok: true,
          headers: { get: () => null },
          body: {
            getReader: () => ({
              read: async () => {
                throw new Error('network changed')
              },
            }),
          },
        }
      }
      return {
        ok: true,
        headers: { get: () => null },
        body: streamOf(
          'event: turn\ndata: {"conversation":"c1","say":"","state":"GIVE_ITEM","chips":[]}\n\n'
        ),
      }
    })
    const turn = await api.turn({ conversation: 'c1', text: 'grey sofa' })
    expect(turn.state).toBe('GIVE_ITEM')
    expect(calls).toHaveLength(2)
    expect(calls[1]).toEqual({ conversation: 'c1', event: { type: 'resume' } })
  })

  it('does not resume a chat that has not started, and resumes only once', async () => {
    const api = new AssistantAPI({ public: { APIv2: 'http://api' } })
    let n = 0
    global.fetch = vi.fn(async () => {
      n++
      return {
        ok: true,
        headers: { get: () => null },
        body: {
          getReader: () => ({
            read: async () => {
              throw new Error('network changed')
            },
          }),
        },
      }
    })
    await expect(api.turn({ tap: 'give' })).rejects.toThrow('stream broke')
    expect(n).toBe(1)
    n = 0
    await expect(api.turn({ conversation: 'c1', tap: 'give' })).rejects.toThrow(
      'stream broke'
    )
    expect(n).toBe(2)
  })
})
