import * as Sentry from '@sentry/browser'
import { addDomMutationContext } from '~/composables/useDomMutationContext'

// Marks Sentry events that look like another script rewriting the page, with whether the page had
// been translated; see composables/useDomMutationContext.js. It is an event processor on the
// global scope, so it applies whenever Sentry starts.
export default defineNuxtPlugin(() => {
  Sentry.getGlobalScope().addEventProcessor((event) =>
    addDomMutationContext(event, document)
  )
})
