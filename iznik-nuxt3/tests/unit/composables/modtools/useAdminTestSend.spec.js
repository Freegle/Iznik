import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref, nextTick } from 'vue'
import { useAdminTestSend } from '~/modtools/composables/useAdminTestSend'

const mockTest = vi.fn()
const mockMe = ref({ email: 'mod@example.com' })

vi.mock('~/stores/admins', () => ({
  useAdminsStore: () => ({ test: (...a) => mockTest(...a) }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

describe('useAdminTestSend', () => {
  let params

  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.value = { email: 'mod@example.com' }
    params = { subject: 'Hello', text: 'Body' }
  })

  function make(validate = () => null) {
    return useAdminTestSend(() => params, validate)
  }

  describe('initial state', () => {
    it('defaults the test address to the moderator email', () => {
      const t = make()
      expect(t.testEmail.value).toBe('mod@example.com')
      expect(t.testing.value).toBe(false)
      expect(t.testError.value).toBeNull()
      expect(t.testtoken.value).toBeNull()
      expect(t.tested.value).toBe(false)
    })

    it.each([[null], [undefined], [{}]])('starts empty when me is %j', (me) => {
      mockMe.value = me
      expect(make().testEmail.value).toBe('')
    })
  })

  describe('default email watcher', () => {
    it('fills the address once me loads', async () => {
      mockMe.value = null
      const t = make()
      mockMe.value = { email: 'late@example.com' }
      await nextTick()
      expect(t.testEmail.value).toBe('late@example.com')
    })

    it('does not overwrite an address already typed', async () => {
      mockMe.value = null
      const t = make()
      t.testEmail.value = 'typed@example.com'
      mockMe.value = { email: 'late@example.com' }
      await nextTick()
      expect(t.testEmail.value).toBe('typed@example.com')
    })

    it('ignores a change to an empty email', async () => {
      mockMe.value = { email: 'a@example.com' }
      const t = make()
      t.testEmail.value = ''
      mockMe.value = { email: '' }
      await nextTick()
      expect(t.testEmail.value).toBe('')
    })
  })

  describe('sendTest validation', () => {
    it('stops with the validate message, without calling the API', async () => {
      const t = make(() => 'Add a subject')
      await t.sendTest()
      expect(t.testError.value).toBe('Add a subject')
      expect(mockTest).not.toHaveBeenCalled()
    })

    it('awaits an async validate', async () => {
      const t = make(async () => 'Async problem')
      await t.sendTest()
      expect(t.testError.value).toBe('Async problem')
      expect(mockTest).not.toHaveBeenCalled()
    })

    it.each([
      [''],
      ['   '],
      ['not-an-email'],
      ['a@b'],
      ['a@b.com, c@d.com'],
      ['<a@b.com>'],
    ])('rejects the address %j', async (addr) => {
      const t = make()
      t.testEmail.value = addr
      await t.sendTest()
      expect(t.testError.value).toBe(
        'Please give one email address to send the test to.'
      )
      expect(mockTest).not.toHaveBeenCalled()
      expect(t.testing.value).toBe(false)
    })
  })

  describe('sendTest success', () => {
    it('sends content plus the trimmed address and records the token', async () => {
      mockTest.mockResolvedValue('tok1')
      const t = make()
      t.testEmail.value = '  to@example.com  '
      await t.sendTest()

      expect(mockTest).toHaveBeenCalledWith({
        subject: 'Hello',
        text: 'Body',
        email: 'to@example.com',
      })
      expect(t.testtoken.value).toBe('tok1')
      expect(t.testedTo.value).toBe('to@example.com')
      expect(t.testedKey.value).toBe(JSON.stringify(params))
      expect(t.testError.value).toBeNull()
      expect(t.testing.value).toBe(false)
      expect(t.tested.value).toBe(true)
    })

    it('clears an earlier error on a successful send', async () => {
      mockTest.mockResolvedValue('tok')
      const t = make()
      t.testError.value = 'old'
      await t.sendTest()
      expect(t.testError.value).toBeNull()
    })

    it('sets testing while the request is in flight', async () => {
      let resolve
      mockTest.mockReturnValue(new Promise((r) => (resolve = r)))
      const t = make()
      const p = t.sendTest()
      await Promise.resolve()
      await Promise.resolve()
      expect(t.testing.value).toBe(true)
      resolve('tok')
      await p
      expect(t.testing.value).toBe(false)
    })
  })

  describe('sendTest failure', () => {
    it('uses the API message when there is one', async () => {
      mockTest.mockRejectedValue({
        response: { data: { message: 'Mail server says no' } },
      })
      const t = make()
      await t.sendTest()
      expect(t.testError.value).toBe('Mail server says no')
      expect(t.testtoken.value).toBeNull()
      expect(t.testedTo.value).toBeNull()
      expect(t.testing.value).toBe(false)
      expect(t.tested.value).toBe(false)
    })

    it.each([
      ['no response', new Error('boom')],
      ['blank message', { response: { data: { message: '  ' } } }],
      ['non-string message', { response: { data: { message: 42 } } }],
    ])('falls back to the generic message for %s', async (_l, err) => {
      mockTest.mockRejectedValue(err)
      const t = make()
      await t.sendTest()
      expect(t.testError.value).toBe(
        "Couldn't send the test - please try again."
      )
      expect(t.testing.value).toBe(false)
    })
  })

  describe('clearTest', () => {
    it('forgets the token and key so the test is no longer current', async () => {
      mockTest.mockResolvedValue('tok')
      const t = make()
      await t.sendTest()
      expect(t.tested.value).toBe(true)

      t.clearTest()
      expect(t.testtoken.value).toBeNull()
      expect(t.testedKey.value).toBeNull()
      expect(t.tested.value).toBe(false)
    })
  })
})
