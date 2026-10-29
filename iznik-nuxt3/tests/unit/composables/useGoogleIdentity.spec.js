import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { initGoogleIdentity } from '~/composables/useGoogleIdentity'

// Sentry 7798233097 and 7770124604: ModTools logged "google.accounts.id.initialize()
// is called multiple times", because One Tap and the login modal each set the
// library up. The Freegle site logged One Tap's warning that its prompt status
// would stop working once FedCM is mandatory, because initialize() never opted in.
describe('initGoogleIdentity', () => {
  let id

  beforeEach(() => {
    id = { initialize: vi.fn() }
    vi.stubGlobal('google', { accounts: { id } })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('sets the library up with FedCM for the prompt', () => {
    initGoogleIdentity('client-1', vi.fn())

    expect(id.initialize).toHaveBeenCalledTimes(1)
    expect(id.initialize).toHaveBeenCalledWith(
      expect.objectContaining({
        client_id: 'client-1',
        use_fedcm_for_prompt: true,
      })
    )
  })

  it('sets it up once however many components ask', () => {
    initGoogleIdentity('client-1', vi.fn())
    initGoogleIdentity('client-1', vi.fn())

    expect(id.initialize).toHaveBeenCalledTimes(1)
  })

  it('hands a credential to the handler that asked most recently', () => {
    const oneTap = vi.fn()
    const loginModal = vi.fn()
    initGoogleIdentity('client-1', oneTap)
    initGoogleIdentity('client-1', loginModal)

    const { callback } = id.initialize.mock.calls[0][0]
    callback({ credential: 'jwt' })

    expect(loginModal).toHaveBeenCalledWith({ credential: 'jwt' })
    expect(oneTap).not.toHaveBeenCalled()
  })

  it('sets up a library that has been loaded again', () => {
    initGoogleIdentity('client-1', vi.fn())
    const reloaded = { initialize: vi.fn() }
    vi.stubGlobal('google', { accounts: { id: reloaded } })

    initGoogleIdentity('client-1', vi.fn())

    expect(reloaded.initialize).toHaveBeenCalledTimes(1)
  })
})
