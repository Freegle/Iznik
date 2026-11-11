/**
 * Flow telemetry for the login / sign up modal (FlowName.LOGIN). The modal hands over the refs it
 * already has; the panes (sign up, sign in, forgot) are the steps. Nothing a member typed is sent,
 * and server error text is replaced by a short code.
 */
import { onScopeDispose, watch } from 'vue'
import { FlowName, useFlowTelemetry } from '~/composables/useFlowTelemetry'

const INCOMPLETE = 'Please fill out the form.'

// A login closes the modal, and can tear it down, before the member record has arrived. Wait this
// long for the member before calling that a dismissal.
const SETTLE_MS = 5000
const POLL_MS = 250

export function useLoginTelemetry({
  showModal,
  forceLogin,
  signUp,
  loggedIn,
  nativeLoginError,
  socialLoginError,
}) {
  const flow = useFlowTelemetry(FlowName.LOGIN)
  const pane = () => (signUp.value ? 'signup' : 'signin')

  const enter = (step) => {
    flow.completeStep()
    flow.enterStep(step)
  }

  let submitted = false

  const succeed = () => {
    flow.submitResult(true, 200, null)
    flow.completeStep()
    flow.complete()
  }

  // Wait for the member to appear, then complete, or abandon as closed when they do not.
  const settle = () => {
    let waited = 0
    const timer = setInterval(() => {
      waited += POLL_MS
      if (!flow.isLive()) {
        clearInterval(timer)
      } else if (loggedIn.value) {
        clearInterval(timer)
        succeed()
      } else if (waited >= SETTLE_MS) {
        clearInterval(timer)
        flow.abandon('closed')
      }
    }, POLL_MS)
  }

  onScopeDispose(() => {
    if (flow.isLive()) settle()
  })

  // Becoming logged in ends the flow. This watcher comes before the modal's own so a modal closed
  // by a successful login is not counted as dismissed.
  watch(loggedIn, (now) => {
    if (now && flow.isLive()) succeed()
  })

  watch(
    showModal,
    (open) => {
      if (open) {
        flow.start(forceLogin.value ? 'forced' : 'navbar')
        flow.enterStep(pane())
      } else if (submitted) {
        settle()
      } else {
        flow.abandon('closed')
      }
    },
    { immediate: true }
  )

  watch(signUp, () => {
    if (flow.isLive()) enter(pane())
  })

  watch(nativeLoginError, (error) => {
    if (!error || !flow.isLive()) return
    if (error === INCOMPLETE) {
      flow.validationFailed('form', 'incomplete')
    } else {
      flow.submitResult(false, null, 'rejected')
    }
  })

  watch(socialLoginError, (error) => {
    if (error && flow.isLive()) {
      flow.submitResult(false, null, 'social_failed')
    }
  })

  return {
    submit: () => {
      submitted = true
      flow.submit()
    },
    forgot: () => {
      if (flow.isLive()) enter('forgot')
    },
  }
}
