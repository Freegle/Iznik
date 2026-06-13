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
