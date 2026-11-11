import { describe, it, expect } from 'vitest'
import { isPageNotFound } from '~/composables/useErrorPage'

describe('isPageNotFound', () => {
  it('is true for a 404', () => {
    expect(
      isPageNotFound({ statusCode: 404, message: 'Page not found: /x' })
    ).toBe(true)
    expect(isPageNotFound({ statusCode: '404' })).toBe(true)
  })

  it.each([{ statusCode: 500 }, { statusCode: 403 }, {}, null, undefined])(
    'is false for %j',
    (error) => {
      expect(isPageNotFound(error)).toBe(false)
    }
  )
})
