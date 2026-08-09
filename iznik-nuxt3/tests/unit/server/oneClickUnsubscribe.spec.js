import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// Nuxt auto-imports these into server middleware; provide them as globals for the test.
const sendRedirect = vi.fn((event, location, code) => ({
  redirect: location,
  code,
}))
const setResponseStatus = vi.fn()
const $fetch = vi.fn()
const useRuntimeConfig = vi.fn(() => ({
  public: { APIv2: 'https://api.ilovefreegle.org/apiv2' },
}))
const defineEventHandler = (fn) => fn

let handler

beforeEach(async () => {
  vi.stubGlobal('sendRedirect', sendRedirect)
  vi.stubGlobal('setResponseStatus', setResponseStatus)
  vi.stubGlobal('$fetch', $fetch)
  vi.stubGlobal('useRuntimeConfig', useRuntimeConfig)
  vi.stubGlobal('defineEventHandler', defineEventHandler)

  sendRedirect.mockClear()
  setResponseStatus.mockClear()
  $fetch.mockReset()
  $fetch.mockResolvedValue({ ret: 0, status: 'Success' })

  vi.resetModules()
  handler = (await import('~/server/middleware/oneClickUnsubscribe.js')).default
})

afterEach(() => {
  vi.unstubAllGlobals()
})

function event(url, method) {
  return { node: { req: { url, method } } }
}

const UID = '44500773'
const KEY = 'abc123def456'
const URL_PATH = `/one-click-unsubscribe/${UID}/${KEY}`

describe('one-click unsubscribe middleware', () => {
  it('ignores unrelated URLs', async () => {
    expect(await handler(event('/browse', 'GET'))).toBeUndefined()
    expect(await handler(event('/unsubscribe', 'POST'))).toBeUndefined()
    expect($fetch).not.toHaveBeenCalled()
  })

  it('redirects a GET rather than actioning it', async () => {
    // List-Unsubscribe-Post exists because virus scanners and link prefetchers follow
    // URLs; a GET must never change anything.
    await handler(event(URL_PATH, 'GET'))

    expect(sendRedirect).toHaveBeenCalledWith(
      expect.anything(),
      `/unsubscribe?u=${UID}&k=${KEY}`,
      302
    )
    expect($fetch).not.toHaveBeenCalled()
  })

  it('turns off all email on an RFC 8058 POST', async () => {
    const result = await handler(event(URL_PATH, 'POST'))

    expect($fetch).toHaveBeenCalledTimes(1)
    const [url, opts] = $fetch.mock.calls[0]
    expect(url).toContain('/user/unsubscribe?')
    expect(url).toContain(`u=${UID}`)
    expect(url).toContain(`k=${KEY}`)
    expect(url).toContain('t=all')
    expect(opts.method).toBe('POST')
    expect(result).toEqual({ ret: 0, status: 'Success' })
  })

  it('never falls through to a page that could delete the account', async () => {
    // This is the regression that matters. The handler used to return undefined for a
    // POST, which let the request reach the page underneath, whose setup script runs
    // during SSR and calls authStore.forget(). A single unauthenticated POST - exactly
    // what Gmail sends for one-click - deleted the member's account.
    const result = await handler(event(URL_PATH, 'POST'))

    expect(result).toBeDefined()
    expect(result.status).toBe('Success')
  })

  it('reports a rejected key instead of claiming success', async () => {
    // Claiming success on a bad key would have the mail client tell someone they are
    // unsubscribed when nothing happened.
    $fetch.mockRejectedValue({ response: { status: 403 } })

    const result = await handler(event(URL_PATH, 'POST'))

    expect(setResponseStatus).toHaveBeenCalledWith(expect.anything(), 403)
    expect(result.status).toBe('Failed')
  })

  it('does not treat a query string as part of the key', async () => {
    await handler(event(`${URL_PATH}?utm_source=email`, 'POST'))

    const [url] = $fetch.mock.calls[0]
    expect(url).toContain(`k=${KEY}`)
    expect(url).not.toContain('utm_source')
  })
})
