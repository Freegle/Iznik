import { describe, it, expect, afterEach, vi } from 'vitest'

// DISCOURSE_BASE is evaluated at import time from process.env.DISCOURSE_URL, so
// each case sets the env and re-imports the module fresh.
describe('DISCOURSE_BASE resolution', () => {
  const original = process.env.DISCOURSE_URL

  afterEach(() => {
    if (original === undefined) delete process.env.DISCOURSE_URL
    else process.env.DISCOURSE_URL = original
    vi.resetModules()
  })

  async function load() {
    vi.resetModules()
    return (await import('../discourse')).DISCOURSE_BASE
  }

  it('falls back to the live instance when DISCOURSE_URL is unset', async () => {
    delete process.env.DISCOURSE_URL
    expect(await load()).toBe('https://discourse.ilovefreegle.org')
  })

  it('falls back when DISCOURSE_URL is an empty string (the bug)', async () => {
    // The old `?? ` form left this as '' → every request became a relative URL
    // and discover_active_topics died with urllib "unknown url type".
    process.env.DISCOURSE_URL = ''
    expect(await load()).toBe('https://discourse.ilovefreegle.org')
  })

  it('uses DISCOURSE_URL when set, stripping a trailing slash', async () => {
    process.env.DISCOURSE_URL = 'https://example.test/'
    expect(await load()).toBe('https://example.test')
  })
})

describe('formatReplyRaw', () => {
  it('prepends an attributed, linked quote block when username is known', async () => {
    const { formatReplyRaw } = await import('../discourse')
    const raw = formatReplyRaw({
      username: 'Neville_Reid',
      post: 10,
      topic: 9692,
      quote: 'iOS app not showing badge count',
      body: 'AI Edward: possible fix applied, please retest and report back',
    })
    expect(raw).toBe(
      '[quote="Neville_Reid, post:10, topic:9692"]\n' +
        'iOS app not showing badge count\n' +
        '[/quote]\n\n' +
        'AI Edward: possible fix applied, please retest and report back',
    )
  })

  it('falls back to a plain [quote] when the username is missing or the "there" placeholder', async () => {
    const { formatReplyRaw } = await import('../discourse')
    for (const username of [undefined, null, '', 'there', 'There']) {
      const raw = formatReplyRaw({ username, post: 5, topic: 100, quote: 'the reported text', body: 'the reply' })
      expect(raw).toBe('[quote]\nthe reported text\n[/quote]\n\nthe reply')
    }
  })

  it('returns the body unchanged when there is no quote text', async () => {
    const { formatReplyRaw } = await import('../discourse')
    expect(formatReplyRaw({ username: 'Jos', post: 3, topic: 9, quote: '', body: 'bare reply' })).toBe('bare reply')
    expect(formatReplyRaw({ username: 'Jos', post: 3, topic: 9, quote: '   ', body: 'bare reply' })).toBe('bare reply')
    expect(formatReplyRaw({ username: 'Jos', post: 3, topic: 9, quote: null, body: 'bare reply' })).toBe('bare reply')
  })

  it('never double-wraps a body that already opens with a quote block', async () => {
    const { formatReplyRaw } = await import('../discourse')
    const baked = '[quote="Vee, post:13, topic:9737"]\nRed alert appears\n[/quote]\n\nFixed now.'
    expect(formatReplyRaw({ username: 'Someone', post: 1, topic: 2, quote: 'ignored', body: baked })).toBe(baked)
    // also tolerate leading whitespace and the bare [quote] form
    expect(formatReplyRaw({ username: 'X', post: 1, topic: 2, quote: 'q', body: '  [quote]\na\n[/quote]\n\nb' })).toBe(
      '  [quote]\na\n[/quote]\n\nb',
    )
  })

  it('trims surrounding whitespace from the quote text', async () => {
    const { formatReplyRaw } = await import('../discourse')
    const raw = formatReplyRaw({ username: 'Jos', post: 2, topic: 9, quote: '\n  padded  \n', body: 'reply' })
    expect(raw).toBe('[quote="Jos, post:2, topic:9"]\npadded\n[/quote]\n\nreply')
  })
})

describe('hasNonEmptyQuote (posting invariant)', () => {
  it('is true for an attributed quote with content', async () => {
    const { hasNonEmptyQuote } = await import('../discourse')
    expect(hasNonEmptyQuote('[quote="Jos, post:3, topic:9"]\nthe report\n[/quote]\n\nplease retest')).toBe(true)
  })

  it('is true for a plain quote with content', async () => {
    const { hasNonEmptyQuote } = await import('../discourse')
    expect(hasNonEmptyQuote('[quote]\nsomething\n[/quote]\n\nbody')).toBe(true)
  })

  it('is false for a bare body with no quote block', async () => {
    const { hasNonEmptyQuote } = await import('../discourse')
    expect(hasNonEmptyQuote('AI Edward: possible fix applied, please retest')).toBe(false)
  })

  it('is false for an empty quote block', async () => {
    const { hasNonEmptyQuote } = await import('../discourse')
    expect(hasNonEmptyQuote('[quote][/quote]\n\nbody')).toBe(false)
  })

  it('is false for a whitespace-only quote block', async () => {
    const { hasNonEmptyQuote } = await import('../discourse')
    expect(hasNonEmptyQuote('[quote="x, post:1, topic:2"]\n   \n[/quote]\n\nbody')).toBe(false)
  })

  it('agrees with formatReplyRaw: a non-empty quote always yields a postable raw', async () => {
    const { formatReplyRaw, hasNonEmptyQuote } = await import('../discourse')
    const raw = formatReplyRaw({ username: 'Jos', post: 3, topic: 9, quote: 'the report', body: 'please retest' })
    expect(hasNonEmptyQuote(raw)).toBe(true)
  })

  it('agrees with formatReplyRaw: an empty quote yields a raw the guard rejects', async () => {
    const { formatReplyRaw, hasNonEmptyQuote } = await import('../discourse')
    // This is the hole: formatReplyRaw returns the bare body, and the posting guard refuses it.
    const raw = formatReplyRaw({ username: 'Jos', post: 3, topic: 9, quote: '', body: 'please retest' })
    expect(hasNonEmptyQuote(raw)).toBe(false)
  })
})
