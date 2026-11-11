/**
 * Wires flow telemetry to the router and to the places members go for help.
 *
 * The route -> step map and the logic are in composables/useFlowRoutes.js; this only connects
 * them. See docs/developers/reference/ux-telemetry.md section 2.
 */
import { useRouter } from '#imports'
import {
  createFlowRouteTracker,
  draftAgeFromStore,
} from '~/composables/useFlowRoutes'
import { noteHelpSought } from '~/composables/useFlowTelemetry'
import { getRecentClickComponent } from '~/composables/useClientLog'
import { useComposeStore } from '~/stores/compose'

export default defineNuxtPlugin(() => {
  if (import.meta.server) return

  const router = useRouter()

  const tracker = createFlowRouteTracker({
    draftAge: (type) => draftAgeFromStore(useComposeStore(), type),
    recentClick: getRecentClickComponent,
  })

  router.afterEach((to, from, failure) => {
    if (failure) return

    tracker.onRoute(to, from)

    if (to.name === 'help') {
      noteHelpSought()
    }
  })

  // Bootstrap modal events bubble to the document. This modal is the "contact us" for deleting an
  // account; its class is set on the modal element.
  document.addEventListener('show.bs.modal', (e) => {
    if (e.target?.classList?.contains('contact-support-modal')) {
      noteHelpSought()
    }
  })
})
