import { describe, it, expect, vi, beforeEach } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

import {
  watchComposeFields,
  watchOptionChoices,
  reportValidationFailure,
} from '~/composables/useComposeTelemetry'
import { useComposeStore } from '~/stores/compose'
import {
  FlowName,
  useFlowTelemetry,
  _resetFlowTelemetryForTests,
} from '~/composables/useFlowTelemetry'

const log = vi.hoisted(() => ({ action: vi.fn(), flush: vi.fn() }))
vi.mock('~/composables/useClientLog', () => ({
  action: log.action,
  flush: log.flush,
  getRecentClickComponent: vi.fn(),
}))
vi.mock('~/composables/useExperiment', () => ({ experimentVariant: () => '' }))
vi.mock('~/api', () => ({ default: () => ({}) }))
vi.mock('~/stores/message', () => ({ useMessageStore: () => ({}) }))
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: null, setAuth: vi.fn() }),
}))

function events() {
  return log.action.mock.calls.map(([name, ctx]) => ({ name, ...ctx }))
}

describe('watchComposeFields', () => {
  let store
  let flow

  beforeEach(async () => {
    setActivePinia(createPinia())
    _resetFlowTelemetryForTests()
    log.action.mockClear()
    store = useComposeStore()
    store.messages = [
      {
        id: 0,
        type: 'Offer',
        item: 'Sofa',
        description: 'Brown',
        availablenow: 1,
        attachments: [{ id: 1 }, { id: 2 }],
      },
    ]
    store.postcode = { id: 5 }
    store.group = 10

    flow = useFlowTelemetry(FlowName.GIVE)
    flow.start('navbar')
    flow.enterStep('give')

    const scope = effectScope()
    scope.run(() => watchComposeFields('Offer'))
    await nextTick()
    log.action.mockClear()
  })

  async function revisit() {
    flow.enterStep('give-whereami')
    flow.back('give')
    flow.enterStep('give')
    log.action.mockClear()
  }

  const fieldEvents = () => events().filter((e) => e.name === 'field_revisit')

  it('stays quiet while the member fills a field in on the first visit', async () => {
    store.messages[0].item = 'Sofa bed'
    await nextTick()
    expect(fieldEvents()).toHaveLength(0)
  })

  it('reports a title replaced on returning to the step', async () => {
    await revisit()
    store.messages[0].item = 'Armchair'
    await nextTick()
    expect(fieldEvents()).toHaveLength(1)
    expect(fieldEvents()[0]).toMatchObject({
      field: 'title',
      change_kind: 'replaced',
    })
  })

  it('reports a description cleared', async () => {
    await revisit()
    store.messages[0].description = ''
    await nextTick()
    expect(fieldEvents()[0]).toMatchObject({
      field: 'description',
      change_kind: 'cleared',
    })
  })

  it('does not report filling in a field that was empty', async () => {
    store.messages[0].description = ''
    await nextTick()
    await revisit()
    store.messages[0].description = 'Now filled in'
    await nextTick()
    expect(fieldEvents()).toHaveLength(0)
  })

  it('reports postcode and availability changes on a revisit', async () => {
    await revisit()
    store.postcode = { id: 6 }
    store.messages[0].availablenow = 2
    await nextTick()
    expect(
      fieldEvents()
        .map((e) => e.field)
        .sort()
    ).toEqual(['availability', 'postcode'])
  })

  it('reports a photo removed even on the first visit', async () => {
    store.messages[0].attachments = [{ id: 1 }]
    await nextTick()
    expect(fieldEvents()).toHaveLength(1)
    expect(fieldEvents()[0]).toMatchObject({
      field: 'photos',
      change_kind: 'removed',
    })
  })

  it('does not report the submit clearing the draft as removals', async () => {
    // markSubmitted flags the draft submitted and empties it on whichever step submits. The
    // member removed nothing, so neither the photos nor the cleared text may count.
    await revisit()
    store.messages[0].submitted = true
    store.messages[0].attachments = []
    store.messages[0].item = null
    store.messages[0].description = null
    await nextTick()
    expect(fieldEvents()).toHaveLength(0)
  })

  it('does not report a field that was re-read but not changed', async () => {
    await revisit()
    // Touching another draft makes the watchers re-read this one; nothing about it changed.
    store.messages.push({
      id: 9,
      type: 'Wanted',
      item: 'Desk',
      attachments: [],
    })
    await nextTick()
    expect(fieldEvents()).toHaveLength(0)
  })

  it('does not report a different draft replacing this one', async () => {
    store.messages = [{ id: 7, type: 'Offer', item: 'Lamp', attachments: [] }]
    await nextTick()
    expect(fieldEvents()).toHaveLength(0)
  })

  it('does not report a photo added', async () => {
    store.messages[0].attachments = [{ id: 1 }, { id: 2 }, { id: 3 }]
    await nextTick()
    expect(fieldEvents()).toHaveLength(0)
  })

  it('reports a change of group as a choice switch', async () => {
    store.group = 11
    await nextTick()
    const sw = events().find((e) => e.name === 'choice_switched')
    expect(sw).toMatchObject({ field: 'group', from: '10', to: '11' })
  })

  it('does not report choosing a group for the first time', async () => {
    store.group = null
    await nextTick()
    log.action.mockClear()
    store.group = 11
    await nextTick()
    expect(events().find((e) => e.name === 'choice_switched')).toBeUndefined()
  })

  it('never puts typed text in any event', async () => {
    await revisit()
    store.messages[0].item = 'My secret sofa 07700 900123'
    store.messages[0].description = ''
    await nextTick()
    expect(JSON.stringify(events())).not.toContain('secret')
    expect(JSON.stringify(events())).not.toContain('07700')
  })

  it('does nothing when no flow instance is live', async () => {
    flow.complete()
    log.action.mockClear()
    store.messages[0].attachments = []
    await nextTick()
    expect(log.action).not.toHaveBeenCalled()
  })

  it('ignores a store with no messages', async () => {
    setActivePinia(createPinia())
    const empty = useComposeStore()
    empty.messages = []
    const scope = effectScope()
    expect(() => scope.run(() => watchComposeFields('Wanted'))).not.toThrow()
  })
})

describe('watchOptionChoices', () => {
  let delivery
  let deadline
  let flow

  beforeEach(async () => {
    setActivePinia(createPinia())
    _resetFlowTelemetryForTests()
    log.action.mockClear()
    flow = useFlowTelemetry(FlowName.GIVE)
    flow.start('navbar')
    flow.enterStep('give-options')
    delivery = ref(false)
    deadline = ref(false)
    effectScope().run(() => watchOptionChoices('Offer', { delivery, deadline }))
    await nextTick()
  })

  it('reports delivery switched on and off with enumerated values', async () => {
    delivery.value = true
    await nextTick()
    delivery.value = false
    await nextTick()
    const sw = events().filter((e) => e.name === 'choice_switched')
    expect(sw.map((e) => [e.field, e.from, e.to])).toEqual([
      ['delivery', 'off', 'on'],
      ['delivery', 'on', 'off'],
    ])
  })

  it('reports the deadline choice', async () => {
    deadline.value = true
    await nextTick()
    const sw = events().find((e) => e.name === 'choice_switched')
    expect([sw.field, sw.from, sw.to]).toEqual(['deadline', 'off', 'on'])
  })
})

describe('reportValidationFailure', () => {
  beforeEach(() => {
    _resetFlowTelemetryForTests()
    log.action.mockClear()
  })

  it('reports on the flow of the message type', () => {
    useFlowTelemetry(FlowName.ASK).start('navbar')
    log.action.mockClear()
    reportValidationFailure('Wanted', 'email', 'in_use')
    expect(events()[0]).toMatchObject({
      name: 'validation_failed',
      flow: 'ask',
      field: 'email',
      rule: 'in_use',
    })
  })

  it('ignores an unknown type', () => {
    useFlowTelemetry(FlowName.GIVE).start('navbar')
    log.action.mockClear()
    reportValidationFailure('Other', 'email', 'in_use')
    expect(log.action).not.toHaveBeenCalled()
  })
})
