import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'

import { useLoginTelemetry } from '~/composables/useLoginTelemetry'
import { _resetFlowTelemetryForTests } from '~/composables/useFlowTelemetry'

const log = vi.hoisted(() => ({ action: vi.fn(), flush: vi.fn() }))
vi.mock('~/composables/useClientLog', () => ({
  action: log.action,
  flush: log.flush,
}))
vi.mock('~/composables/useExperiment', () => ({ experimentVariant: () => '' }))

const events = () =>
  log.action.mock.calls.map(([name, ctx]) => ({ name, ...ctx }))
const names = () => events().map((e) => e.name)

describe('useLoginTelemetry', () => {
  let showModal
  let forceLogin
  let signUp
  let loggedIn
  let nativeLoginError
  let socialLoginError
  let tele
  let scope

  afterEach(() => {
    vi.useRealTimers()
  })

  beforeEach(() => {
    vi.useFakeTimers()
    _resetFlowTelemetryForTests()
    log.action.mockClear()
    showModal = ref(false)
    forceLogin = ref(false)
    signUp = ref(true)
    loggedIn = ref(false)
    nativeLoginError = ref(null)
    socialLoginError = ref(null)
    scope = effectScope()
    tele = scope.run(() =>
      useLoginTelemetry({
        showModal,
        forceLogin,
        signUp,
        loggedIn,
        nativeLoginError,
        socialLoginError,
      })
    )
  })

  async function open(forced = false) {
    forceLogin.value = forced
    showModal.value = true
    await nextTick()
  }

  it('starts the login flow when the modal opens, entering the pane', async () => {
    await open()
    expect(names()).toEqual(['flow_start', 'step_enter'])
    expect(events()[0]).toMatchObject({ flow: 'login', entry: 'navbar' })
    expect(events()[1].step).toBe('signup')
  })

  it('says the entry was forced when the app forced the login', async () => {
    await open(true)
    expect(events()[0].entry).toBe('forced')
  })

  it('uses the signin pane when not signing up', async () => {
    signUp.value = false
    await open()
    expect(events()[1].step).toBe('signin')
  })

  it('moves between the sign up and sign in panes as steps', async () => {
    await open()
    log.action.mockClear()
    signUp.value = false
    await nextTick()
    expect(names()).toEqual(['step_complete', 'step_enter'])
    expect(events()[0].step).toBe('signup')
    expect(events()[1].step).toBe('signin')
  })

  it('records the forgot pane when the member goes to forgot password', async () => {
    await open()
    log.action.mockClear()
    tele.forgot()
    expect(events().at(-1)).toMatchObject({
      name: 'step_enter',
      step: 'forgot',
    })
  })

  it('records a submit', async () => {
    await open()
    log.action.mockClear()
    tele.submit()
    expect(names()).toEqual(['flow_submit'])
  })

  it('reports an incomplete form as a validation failure', async () => {
    await open()
    log.action.mockClear()
    nativeLoginError.value = 'Please fill out the form.'
    await nextTick()
    expect(events()[0]).toMatchObject({
      name: 'validation_failed',
      field: 'form',
      rule: 'incomplete',
    })
  })

  it('reports a rejected login as a failed result, with no server text', async () => {
    await open()
    log.action.mockClear()
    nativeLoginError.value = 'Wrong password for me@example.com'
    await nextTick()
    expect(events()[0]).toMatchObject({
      name: 'flow_submit_result',
      ok: false,
      error_code: 'rejected',
    })
    expect(JSON.stringify(events())).not.toContain('example.com')
  })

  it('reports a social login failure', async () => {
    await open()
    log.action.mockClear()
    socialLoginError.value = 'Google login failed: popup_closed'
    await nextTick()
    expect(events()[0]).toMatchObject({
      name: 'flow_submit_result',
      ok: false,
      error_code: 'social_failed',
    })
  })

  it('completes with an ok result when the member becomes logged in', async () => {
    await open()
    log.action.mockClear()
    loggedIn.value = true
    await nextTick()
    expect(names()).toEqual([
      'flow_submit_result',
      'step_complete',
      'flow_complete',
    ])
    expect(events()[0].ok).toBe(true)
  })

  it('abandons as closed, on the pane it was on, when dismissed', async () => {
    await open()
    signUp.value = false
    await nextTick()
    log.action.mockClear()
    showModal.value = false
    await nextTick()
    expect(events()[0]).toMatchObject({
      name: 'flow_abandon',
      reason: 'closed',
      last_step: 'signin',
    })
  })

  it('does not abandon after a successful login closes the modal', async () => {
    await open()
    loggedIn.value = true
    await nextTick()
    log.action.mockClear()
    showModal.value = false
    await nextTick()
    expect(log.action).not.toHaveBeenCalled()
  })

  it('does nothing for errors cleared or when the modal is closed', async () => {
    nativeLoginError.value = 'x'
    await nextTick()
    nativeLoginError.value = null
    await nextTick()
    expect(log.action).not.toHaveBeenCalled()
  })

  describe('login that closes the modal before the member is known', () => {
    it('completes when the member appears within five seconds of the modal closing after a submit', async () => {
      await open()
      tele.submit()
      showModal.value = false
      await nextTick()
      log.action.mockClear()
      vi.advanceTimersByTime(2000)
      loggedIn.value = true
      await nextTick()
      expect(names()).toEqual([
        'flow_submit_result',
        'step_complete',
        'flow_complete',
      ])
      vi.advanceTimersByTime(10000)
      expect(names()).not.toContain('flow_abandon')
    })

    it('abandons as closed if nobody is logged in five seconds after a submit and close', async () => {
      await open()
      tele.submit()
      showModal.value = false
      await nextTick()
      log.action.mockClear()
      vi.advanceTimersByTime(5001)
      expect(events()[0]).toMatchObject({
        name: 'flow_abandon',
        reason: 'closed',
      })
    })

    it('still abandons at once when closed with no submit', async () => {
      await open()
      log.action.mockClear()
      showModal.value = false
      await nextTick()
      expect(names()).toEqual(['flow_abandon'])
    })

    it('completes if the modal is torn down by the login and the member appears after', async () => {
      await open()
      tele.submit()
      log.action.mockClear()
      scope.stop()
      loggedIn.value = true
      vi.advanceTimersByTime(1000)
      expect(names()).toEqual([
        'flow_submit_result',
        'step_complete',
        'flow_complete',
      ])
    })

    it('abandons if torn down with no login within five seconds', async () => {
      await open()
      log.action.mockClear()
      scope.stop()
      vi.advanceTimersByTime(5001)
      expect(names()).toEqual(['flow_abandon'])
    })
  })
})
