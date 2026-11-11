import { describe, it, expect } from 'vitest'
import { isPageNotFound } from '~/composables/useErrorPage'

describe('isPageNotFound', () => {
  it('is true for the router finding no page', () => {
    expect(
      isPageNotFound({ statusCode: 404, message: 'Page not found: /groups' })
    ).toBe(true)
    expect(
      isPageNotFound({
        statusCode: '404',
        statusMessage: 'Page not found: /x',
      })
    ).toBe(true)
  })

  it('is false for a page that throws its own not-found error', () => {
    expect(
      isPageNotFound({ statusCode: 404, message: 'Message not found' })
    ).toBe(false)
    expect(isPageNotFound({ statusCode: 404 })).toBe(false)
  })

  it.each([
    { statusCode: 500, message: 'Page not found: /x' },
    { statusCode: 403 },
    {},
    null,
    undefined,
  ])('is false for %j', (error) => {
    expect(isPageNotFound(error)).toBe(false)
  })
})
