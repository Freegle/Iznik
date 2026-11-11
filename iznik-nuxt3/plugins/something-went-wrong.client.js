import * as Sentry from '@sentry/browser'
import { APIError, MaintenanceError } from '@/api/BaseAPI'
import { useMiscStore } from '~/stores/misc'
import { useRouter } from '#imports'
import { suppressException } from '~/composables/useSuppressException'
import { warn } from '~/composables/useClientLog'
import {
  apiFailureDisposition,
  describeNonError,
  logDroppedApiFailure,
} from '~/composables/useApiFailurePolicy'

// An API error that nothing caught. BaseAPI has already decided whether the call is a Sentry issue
// and says so on the error; this only sends it if BaseAPI did not and it is one (a caller that
// handles failures itself passed logError=false but then let this one escape). The fingerprint and
// the per-session cap are applied in beforeSend, which sees every API error however it was sent.
function reportApiError(err) {
  const status = err.response?.status ?? null

  if (err.sentryReported) {
    return
  }

  if (apiFailureDisposition(status) === 'error') {
    Sentry.captureException(err)
  } else if (!err.failureHandled) {
    logDroppedApiFailure({
      status,
      method: err.request?.method,
      path: err.request?.path,
      reason: 'unhandled_expected_status',
    })
  }
}

// A signed-out visitor's reads of a member's own data (chats, notification count) answer 401, and
// nothing on screen is wrong. A write that is refused (Reply, Save, Join) is different: the visitor
// asked for something and it did not happen, so they are still told.
function isSignedOutRefusal(err) {
  return (
    err.response?.status === 401 &&
    String(err.request?.method || '').toUpperCase() === 'GET' &&
    !String(err.request?.path || '').includes('loggedInAs=')
  )
}

export default defineNuxtPlugin((nuxtApp) => {
  const originalErrorHandler = nuxtApp.vueApp.config.errorHandler

  nuxtApp.vueApp.config.errorHandler = (err, vm, info, ...rest) => {
    console.log('Vue errorHandler', err?.message)
    if (!err) {
      return
    }
    if (err instanceof MaintenanceError) {
      // This is thrown in response to API return codes from the server.
      throw err
    } else if (err instanceof APIError) {
      const { request, response } = err
      try {
        // We have seen Sentry issues which look as though our console.error log here is itself causing an exception,
        // on older devices which might be less good at using console on complex objects.
        //
        // So log more carefully.
        console.log('Vue errorHandler')
        console.log('Err', err)
        console.log('Request', request)
        console.error('Response', response)
      } catch (e) {
        console.error('Error in toast logging', e)
      }

      // Trigger popup, unless this is only a signed-out member being refused.
      if (!isSignedOutRefusal(err)) {
        const miscStore = useMiscStore()
        miscStore.setErrorDetails(err)
      }

      reportApiError(err)

      return true
    } else if (suppressException(err)) {
      return true
    }

    // The original handler ends in Nuxt's, which turns any error into the error page. Nuxt means it for hydration
    // only and removes it on app:suspense:resolve, but only while it is still the installed handler, and ours
    // has replaced it. So only pass errors on while hydrating, or every later error would replace the page.
    if (originalErrorHandler && nuxtApp.isHydrating) {
      return originalErrorHandler(err, vm, info, ...rest)
    }

    // Report it here rather than drop it. Where Sentry starts before this plugin (the app, dev), the original
    // handler is Sentry's wrapper round Nuxt's, so nothing else would report it. Where Sentry starts later, it
    // wraps this plugin and reports the error too, and its Dedupe integration drops the repeat.
    console.error(err)
    Sentry.captureException(err)
  }

  window.addEventListener('unhandledrejection', function (event) {
    const reason = event?.reason

    if (reason?.message?.includes('Maintenance error')) {
      // The API may throw this, and it may not get caught.
      const router = useRouter()
      router.push('/maintenance')
    } else if (reason instanceof APIError) {
      reportApiError(reason)
    } else if (reason instanceof Error) {
      // Use captureException with the actual Error object so Sentry gets
      // the full stack trace. The previous captureMessage('Unhandled promise')
      // discarded all diagnostic information.
      Sentry.captureException(reason)
    } else if (reason) {
      // Something that is not an Error (an empty object, an Event) has no stack and no message, so
      // Sentry can only show it as "Unhandled promise" with nothing to group or explain it. Say
      // what it is in the client log instead.
      warn('Unhandled rejection that is not an Error', {
        event_type: 'unhandled_rejection_non_error',
        ...describeNonError(reason),
      })
    }
  })
})
