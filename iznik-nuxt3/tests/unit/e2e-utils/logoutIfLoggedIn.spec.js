import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('../../e2e/config.js', () => ({
  timeouts: { ui: { appearance: 1000, interaction: 1000 }, navigation: { initial: 5000 } },
  DEFAULT_TEST_PASSWORD: 'x',
  SCREENSHOTS_DIR: '/tmp',
}))
vi.mock('../../e2e/utils/ui.js', () => ({
  waitForModal: vi.fn(),
}))

const { logoutIfLoggedIn } = require('../../e2e/utils/user.js')

function makeLocator(visible = false) {
  return {
    waitFor: vi.fn().mockResolvedValue(undefined),
    isVisible: vi.fn().mockResolvedValue(visible),
    click: vi.fn().mockResolvedValue(undefined),
    evaluate: vi.fn().mockResolvedValue(undefined),
    filter: function () {
      return this
    },
    first: function () {
      return this
    },
  }
}

function makePage(url = 'http://example.com/', overrides = {}) {
  const gotoMock = vi.fn().mockResolvedValue(undefined)
  const waitForLoadStateMock = vi.fn().mockResolvedValue(undefined)
  const waitForURLMock = vi.fn().mockResolvedValue(undefined)
  return {
    _url: url,
    isClosed: () => false,
    url: function () {
      return this._url
    },
    goto: gotoMock,
    waitForLoadState: waitForLoadStateMock,
    waitForURL: waitForURLMock,
    evaluate: vi.fn().mockResolvedValue(undefined),
    context: () => ({ clearCookies: vi.fn().mockResolvedValue(undefined) }),
    locator: vi.fn().mockImplementation((selector) => {
      if (selector === '#menu-option-logout') {
        return makeLocator(false)
      }
      if (selector === 'text=Logout') {
        return makeLocator(false)
      }
      return makeLocator(false)
    }),
    addAllowedErrorPattern: vi.fn(),
    ...overrides,
  }
}

describe('logoutIfLoggedIn (e2e util)', () => {
  it('skips page.goto("/") when logout redirect already landed at home page', async () => {
    // The bug: after logout redirects to '/', calling page.goto('/') while the
    // page is still hydrating causes a double-navigation race that freezes the
    // Chromium renderer. Fix: skip goto('/') if already at '/'.
    const page = makePage('http://freegle-prod-local.localhost:9080/')
    await logoutIfLoggedIn(page)
    expect(page.goto).not.toHaveBeenCalled()
  })

  it('waits for sign-in button when logout redirect already landed at home page', async () => {
    // After skipping goto('/'), Nuxt's post-navigation JavaScript is still running.
    // If the caller immediately navigates to another URL (e.g. /give via postMessage),
    // two concurrent navigations race and the V8 renderer can hang for 35+ seconds.
    // Fix: wait for .test-signinbutton visible, confirming Nuxt has fully hydrated.
    const signinLocator = makeLocator(false)
    const page = makePage('http://freegle-prod-local.localhost:9080/', {
      locator: vi.fn().mockImplementation((selector) => {
        if (selector === '.test-signinbutton') {
          return signinLocator
        }
        return makeLocator(false)
      }),
    })
    await logoutIfLoggedIn(page)
    expect(page.locator).toHaveBeenCalledWith('.test-signinbutton')
    expect(signinLocator.waitFor).toHaveBeenCalledWith(
      expect.objectContaining({ state: 'visible', timeout: 15000 })
    )
  })

  it('calls page.goto("/") when logout did not redirect to home page', async () => {
    // When logout leaves the user on a non-home page, we still need to navigate.
    const page = makePage('http://freegle-prod-local.localhost:9080/explore')
    await logoutIfLoggedIn(page)
    expect(page.goto).toHaveBeenCalledWith(
      '/',
      expect.objectContaining({ waitUntil: 'domcontentloaded' })
    )
  })

  it('skips page.goto("/") when already at root with query string (e.g. ?t=1)', async () => {
    const page = makePage('http://freegle-prod-local.localhost:9080/?t=1')
    await logoutIfLoggedIn(page)
    expect(page.goto).not.toHaveBeenCalled()
  })

  it('calls page.goto("/") when at /browse even with trailing slash', async () => {
    const page = makePage('http://freegle-prod-local.localhost:9080/browse')
    await logoutIfLoggedIn(page)
    expect(page.goto).toHaveBeenCalledWith(
      '/',
      expect.objectContaining({ waitUntil: 'domcontentloaded' })
    )
  })

  it('waits for domcontentloaded before clicking logout to settle any in-flight redirect', async () => {
    // After signUpViaHomepage the page may be at /browse which immediately redirects
    // to /explore for new users. If the logout button is clicked while /browse→/explore
    // is still committing, two simultaneous hard navigations freeze the V8 renderer.
    // Fix: waitForLoadState('domcontentloaded') at the start of logoutIfLoggedIn ensures
    // the page is stable before we look for and click the logout button.
    const page = makePage('http://freegle-prod-local.localhost:9080/browse')
    await logoutIfLoggedIn(page)
    expect(page.waitForLoadState).toHaveBeenCalledWith(
      'domcontentloaded',
      expect.objectContaining({ timeout: 5000 })
    )
  })

  it('waits for /browse→/explore redirect via waitForURL when at /browse', async () => {
    // The production CI failure: /browse auto-redirects new users to /explore via
    // JS AFTER DOMContentLoaded fires. waitForLoadState('domcontentloaded') resolves
    // immediately because /browse's DOMContentLoaded has already fired, leaving the
    // JS-initiated redirect in flight. Clicking logout while /browse→/explore is
    // committing causes two simultaneous hard navigations that freeze the V8 renderer
    // for 35+ seconds (observed in production CI job 16194 at sha 83d85abb9).
    // Fix: explicitly call waitForURL(/explore|myposts/) when the entry URL is /browse.
    const page = makePage('http://freegle-prod-local.localhost:9080/browse')
    await logoutIfLoggedIn(page)
    expect(page.waitForURL).toHaveBeenCalledWith(
      expect.any(RegExp),
      expect.objectContaining({ timeout: 3000 })
    )
  })
})
