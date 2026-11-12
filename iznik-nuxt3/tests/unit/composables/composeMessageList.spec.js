import { describe, it, expect, vi, beforeEach } from 'vitest'
import * as Sentry from '@sentry/browser'
import {
  composeMessageList,
  _resetComposeMessageListReportForTests,
} from '~/composables/composeMessageList'

vi.mock('@sentry/browser', () => ({ captureMessage: vi.fn() }))

describe('composeMessageList', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    _resetComposeMessageListReportForTests()
  })

  it('returns the array as it is', () => {
    const messages = [{ id: 0 }]

    expect(composeMessageList({ messages })).toBe(messages)
    expect(Sentry.captureMessage).not.toHaveBeenCalled()
  })

  it.each([undefined, null])('reads %s as no drafts without reporting', (v) => {
    expect(composeMessageList({ messages: v })).toEqual([])
    expect(composeMessageList(null)).toEqual([])
    expect(Sentry.captureMessage).not.toHaveBeenCalled()
  })

  it('reads a value that is not an array as no drafts, and reports what it was once', () => {
    expect(composeMessageList({ messages: { 0: {}, 1: {} } })).toEqual([])
    expect(composeMessageList({ messages: 'x' })).toEqual([])

    expect(Sentry.captureMessage).toHaveBeenCalledTimes(1)
    expect(Sentry.captureMessage).toHaveBeenCalledWith(
      'compose store messages is not an array',
      expect.objectContaining({
        level: 'warning',
        extra: expect.objectContaining({
          valueType: 'object',
          tag: '[object Object]',
          keys: ['0', '1'],
        }),
      })
    )
  })
})
