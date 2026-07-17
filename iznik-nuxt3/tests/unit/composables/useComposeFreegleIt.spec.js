import { describe, it, expect, vi, beforeEach } from 'vitest'

import { freegleIt } from '~/composables/useCompose.js'

// freegleIt() is the true completion point of the Give/Find wizards: the
// conversion events must fire only after composeStore.submit() succeeds,
// never on wizard page mount (people who open the wizard and leave are not
// conversions).

const mockTrackConversion = vi.fn()
vi.mock('~/composables/useTrackConversion', () => ({
  trackConversion: (...args) => mockTrackConversion(...args),
}))

let mockSubmitResults

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({
    submit: vi.fn(() => mockSubmitResults()),
    email: 'test@example.com',
    postcode: null,
  }),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    get: vi.fn(),
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: vi.fn().mockResolvedValue({}),
    fetchMessages: vi.fn(),
    all: [],
  }),
}))

function makeRouter() {
  return { push: vi.fn().mockResolvedValue(undefined) }
}

describe('freegleIt conversion events', () => {
  beforeEach(() => {
    mockTrackConversion.mockReset()
    // ~/stores/auth resolves to tests/unit/mocks/auth-store.js in vitest.
    globalThis.__mockAuthStore = {
      user: null,
      login: vi.fn().mockResolvedValue(undefined),
      saveAndGet: vi.fn().mockResolvedValue(undefined),
    }
    mockSubmitResults = () => Promise.resolve([{ id: 123, groupid: null }])
  })

  it('fires Give an Item only after an Offer submit succeeds', async () => {
    await freegleIt('Offer', makeRouter())

    expect(mockTrackConversion).toHaveBeenCalledWith('Give an Item')
    expect(mockTrackConversion).not.toHaveBeenCalledWith('Find an Item')
  })

  it('fires Find an Item only after a Wanted submit succeeds', async () => {
    await freegleIt('Wanted', makeRouter())

    expect(mockTrackConversion).toHaveBeenCalledWith('Find an Item')
    expect(mockTrackConversion).not.toHaveBeenCalledWith('Give an Item')
  })

  it('fires Register with Website too when posting created a new account', async () => {
    mockSubmitResults = () =>
      Promise.resolve([
        { id: 123, groupid: null, newuser: 456, newpassword: 'pw' },
      ])

    await freegleIt('Offer', makeRouter())

    expect(mockTrackConversion).toHaveBeenCalledWith('Give an Item')
    expect(mockTrackConversion).toHaveBeenCalledWith('Register with Website')
  })

  it('does not fire Register with Website for an existing user', async () => {
    await freegleIt('Offer', makeRouter())

    expect(mockTrackConversion).not.toHaveBeenCalledWith(
      'Register with Website'
    )
  })

  it('fires nothing when submit fails', async () => {
    mockSubmitResults = () => Promise.reject(new Error('server error'))

    await freegleIt('Offer', makeRouter())

    expect(mockTrackConversion).not.toHaveBeenCalled()
  })
})

// myposts identifies what you just posted from the ids in history state - that's
// how it knows to show the matching-offers panel after a WANTED. ids used to be
// populated for Offers only, so the panel could never appear for a real post;
// the component test passed because it stubbed a state the app never produced.
describe('freegleIt posted ids in history state', () => {
  beforeEach(() => {
    mockTrackConversion.mockReset()
    globalThis.__mockAuthStore = {
      user: null,
      login: vi.fn().mockResolvedValue(undefined),
      saveAndGet: vi.fn().mockResolvedValue(undefined),
    }
    mockSubmitResults = () => Promise.resolve([{ id: 123, groupid: null }])
  })

  it.each(['Offer', 'Wanted'])(
    'passes the posted ids to myposts for a %s',
    async (type) => {
      const router = makeRouter()

      await freegleIt(type, router)

      expect(router.push).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'myposts',
          state: expect.objectContaining({ ids: [123], type }),
        })
      )
    }
  )

  it('passes every posted id when several messages are submitted', async () => {
    mockSubmitResults = () =>
      Promise.resolve([
        { id: 123, groupid: null },
        { id: 456, groupid: null },
      ])
    const router = makeRouter()

    await freegleIt('Wanted', router)

    expect(router.push).toHaveBeenCalledWith(
      expect.objectContaining({
        state: expect.objectContaining({ ids: [123, 456] }),
      })
    )
  })
})
