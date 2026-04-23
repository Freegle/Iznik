import { describe, it, expect, vi } from 'vitest'

vi.mock('../../e2e/config.js', () => ({
  timeouts: { ui: { appearance: 1000 }, navigation: { initial: 1000 } },
  DEFAULT_TEST_PASSWORD: 'x',
  SCREENSHOTS_DIR: '/tmp',
}))
vi.mock('../../e2e/utils/ui.js', () => ({
  waitForModal: vi.fn(),
}))

const { clearSessionData } = require('../../e2e/utils/user.js')

function makePage(overrides = {}) {
  return {
    isClosed: () => false,
    evaluate: vi.fn().mockResolvedValue(undefined),
    context: () => ({ clearCookies: vi.fn().mockResolvedValue(undefined) }),
    ...overrides,
  }
}

describe('clearSessionData (e2e util)', () => {
  it('clears localStorage, sessionStorage, and cookies on a live page', async () => {
    const clearCookies = vi.fn().mockResolvedValue(undefined)
    const page = makePage({
      context: () => ({ clearCookies }),
    })

    await expect(clearSessionData(page)).resolves.toBeUndefined()
    expect(page.evaluate).toHaveBeenCalledTimes(1)
    expect(clearCookies).toHaveBeenCalledTimes(1)
  })

  it('returns early without throwing when page.isClosed() is true', async () => {
    const evaluate = vi.fn()
    const clearCookies = vi.fn()
    const page = {
      isClosed: () => true,
      evaluate,
      context: () => ({ clearCookies }),
    }

    await expect(clearSessionData(page)).resolves.toBeUndefined()
    expect(evaluate).not.toHaveBeenCalled()
    expect(clearCookies).not.toHaveBeenCalled()
  })

  it('swallows "Target page, context or browser has been closed" from evaluate (CI job 4788)', async () => {
    const page = makePage({
      evaluate: vi.fn().mockRejectedValue(
        new Error('page.apply: Target page, context or browser has been closed')
      ),
    })

    await expect(clearSessionData(page)).resolves.toBeUndefined()
  })

  it('swallows "Execution context was destroyed" from evaluate', async () => {
    const page = makePage({
      evaluate: vi.fn().mockRejectedValue(
        new Error('Execution context was destroyed, most likely because of a navigation')
      ),
    })

    await expect(clearSessionData(page)).resolves.toBeUndefined()
  })

  it('swallows "Target closed" from clearCookies', async () => {
    const page = makePage({
      context: () => ({
        clearCookies: vi
          .fn()
          .mockRejectedValue(new Error('Target closed')),
      }),
    })

    await expect(clearSessionData(page)).resolves.toBeUndefined()
  })

  it('re-throws unrelated evaluate errors (e.g. real bugs)', async () => {
    const page = makePage({
      evaluate: vi.fn().mockRejectedValue(new Error('SyntaxError: Unexpected token')),
    })

    await expect(clearSessionData(page)).rejects.toThrow('SyntaxError')
  })

  it('re-throws unrelated clearCookies errors', async () => {
    const page = makePage({
      context: () => ({
        clearCookies: vi.fn().mockRejectedValue(new Error('TypeError: bad options')),
      }),
    })

    await expect(clearSessionData(page)).rejects.toThrow('TypeError')
  })

  it('works when page has no isClosed method (defensive)', async () => {
    const page = {
      evaluate: vi.fn().mockResolvedValue(undefined),
      context: () => ({ clearCookies: vi.fn().mockResolvedValue(undefined) }),
    }

    await expect(clearSessionData(page)).resolves.toBeUndefined()
    expect(page.evaluate).toHaveBeenCalled()
  })
})
