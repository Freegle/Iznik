/**
 * Flow telemetry for the compose store: which fields a member went back and changed, and which
 * enumerated choices they switched. Called from useCompose's setup(), which every give and ask
 * page calls, so the pages need nothing of their own.
 *
 * Only the fact of a change is reported (a field name and a kind), never a value.
 */
import { watch } from 'vue'
import { useComposeStore } from '~/stores/compose'
import {
  ChangeKind,
  FlowName,
  useFlowTelemetry,
} from '~/composables/useFlowTelemetry'

const FLOW_FOR_TYPE = { Offer: FlowName.GIVE, Wanted: FlowName.ASK }

function isEmpty(value) {
  return value === null || value === undefined || value === ''
}

export function watchComposeFields(type) {
  const flowName = FLOW_FOR_TYPE[type]
  if (!flowName) return

  const store = useComposeStore()
  const flow = useFlowTelemetry(flowName)

  const message = () =>
    (store.messages || []).find((m) => m && m.type === type && !m.submitted)

  // Each value is read together with the draft it came from. On submit, markSubmitted flags the
  // draft and empties it, so the unsubmitted draft disappears and every field reads as cleared
  // on whichever step submitted; a new draft replacing it reads as a change too. Neither is the
  // member editing, so a change only counts while the same draft is still the live one.
  // The getter builds a new object each time, so the watcher fires on any re-read; only a real
  // change of value counts.
  const changedInSameDraft = (now, before) =>
    now !== null &&
    before !== null &&
    now.draft === before.draft &&
    now.value !== before.value
  const watchDraft = (getter, onChange) => {
    watch(
      () => {
        const m = message()
        return m ? { draft: m.id, value: getter(m) } : null
      },
      (now, before) => {
        if (changedInSameDraft(now, before)) onChange(now.value, before.value)
      }
    )
  }

  // A field that was filled in is changed after the member came back to its step.
  const watchText = (field, getter) => {
    watchDraft(getter, (now, before) => {
      if (isEmpty(before) || !flow.isRevisit()) return
      flow.fieldRevisit(
        field,
        isEmpty(now) ? ChangeKind.CLEARED : ChangeKind.REPLACED
      )
    })
  }

  watchText('title', (m) => m.item)
  watchText('description', (m) => m.description)
  watchText('availability', (m) => m.availablenow)

  // The postcode lives on the store, not the draft.
  watch(
    () => store.postcode?.id,
    (now, before) => {
      if (isEmpty(before) || !flow.isRevisit()) return
      flow.fieldRevisit(
        'postcode',
        isEmpty(now) ? ChangeKind.CLEARED : ChangeKind.REPLACED
      )
    }
  )

  // A photo removed counts wherever it happens: it had been uploaded, and the member took it out.
  watchDraft(
    (m) => m.attachments?.length || 0,
    (now, before) => {
      if (now < before) flow.fieldRevisit('photos', ChangeKind.REMOVED)
    }
  )

  watch(
    () => store.group,
    (now, before) => {
      if (!isEmpty(now) && !isEmpty(before)) {
        flow.choiceSwitched('group', before, now)
      }
    }
  )
}

/**
 * The yes/no choices on the options step (delivery, deadline) are local to that page until Next,
 * so the page hands its refs over. Values sent are 'on' and 'off'.
 */
export function watchOptionChoices(type, { delivery, deadline }) {
  const flowName = FLOW_FOR_TYPE[type]
  if (!flowName) return

  const flow = useFlowTelemetry(flowName)
  const word = (on) => (on ? 'on' : 'off')

  watch(delivery, (now, before) =>
    flow.choiceSwitched('delivery', word(before), word(now))
  )
  watch(deadline, (now, before) =>
    flow.choiceSwitched('deadline', word(before), word(now))
  )
}

/**
 * Where a wizard refuses to go on: a field name and a rule, never a value.
 */
export function reportValidationFailure(type, field, rule) {
  const flowName = FLOW_FOR_TYPE[type]

  if (flowName) {
    useFlowTelemetry(flowName).validationFailed(field, rule)
  }
}
