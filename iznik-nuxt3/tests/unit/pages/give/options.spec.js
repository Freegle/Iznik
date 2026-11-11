import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import DesktopOptions from '~/pages/give/options.vue'
import MobileOptions from '~/pages/give/mobile/options.vue'

const mockPush = vi.fn()
const mockReplace = vi.fn()
globalThis.__testUseRouter = () => ({
  push: mockPush,
  replace: mockReplace,
  currentRoute: { value: { path: '/give/options' } },
})

const mockSetDeliveryPossible = vi.fn()
const mockSetDeadline = vi.fn()
let mockAll = []
vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({
    get all() {
      return mockAll
    },
    setDeliveryPossible: mockSetDeliveryPossible,
    setDeadline: mockSetDeadline,
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ stickyAdRendered: false }),
}))

// What the telemetry watcher saw when it started: if it starts before the earlier choices are put
// back, putting them back would be reported as the member changing them.
const watchedAt = []
vi.mock('~/composables/useComposeTelemetry', () => ({
  watchOptionChoices: (type, { delivery, deadline }) =>
    watchedAt.push({ delivery: delivery.value, deadline: deadline.value }),
  reportValidationFailure: vi.fn(),
}))

const stubs = {
  'client-only': { template: '<div><slot /></div>' },
  WizardProgressCompact: { template: '<div />' },
  'b-form-input': {
    template:
      '<input class="date" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'type', 'size', 'min', 'max'],
    emits: ['update:modelValue', 'input'],
  },
  'b-button': {
    template:
      '<button class="next" @click="$emit(\'click\')"><slot /></button>',
    emits: ['click'],
  },
  'v-icon': { template: '<i />' },
}

// A date a few days ahead, so the page's "not in the past" check passes whenever this runs.
const inThreeDays = new Date(Date.now() + 3 * 24 * 60 * 60 * 1000)
  .toISOString()
  .substring(0, 10)

function toggle(w, label) {
  return w.findAll('.toggle-btn').find((b) => b.text() === label)
}

describe.each([
  ['desktop', DesktopOptions, '/give/whoami'],
  ['mobile', MobileOptions, '/give/mobile/whereami'],
])('the %s options step', (name, Page, next) => {
  beforeEach(() => {
    mockPush.mockReset()
    mockReplace.mockReset()
    mockSetDeliveryPossible.mockReset()
    mockSetDeadline.mockReset()
    watchedAt.length = 0
  })

  it('shows the choices made earlier when the member comes back to it', async () => {
    mockAll = [
      { id: 7, type: 'Offer', deliveryPossible: true, deadline: inThreeDays },
    ]
    const w = mount(Page, { global: { stubs } })
    await flushPromises()

    expect(toggle(w, 'Maybe').classes()).toContain('active')
    expect(toggle(w, 'Collection only').classes()).not.toContain('active')
    expect(toggle(w, 'Set deadline').classes()).toContain('active')
    expect(w.find('input.date').element.value).toBe(inThreeDays)
  })

  it('keeps those choices when Next is pressed without touching anything', async () => {
    mockAll = [
      { id: 7, type: 'Offer', deliveryPossible: true, deadline: inThreeDays },
    ]
    const w = mount(Page, { global: { stubs } })
    await flushPromises()

    await w.find('button.next').trigger('click')

    expect(mockSetDeliveryPossible).toHaveBeenCalledWith(7, true)
    expect(mockSetDeadline).toHaveBeenCalledWith(7, inThreeDays)
    expect(mockPush).toHaveBeenCalledWith(next)
  })

  it('shows the defaults for a post with no choices yet', async () => {
    mockAll = [{ id: 8, type: 'Offer' }]
    const w = mount(Page, { global: { stubs } })
    await flushPromises()

    expect(toggle(w, 'Collection only').classes()).toContain('active')
    expect(toggle(w, 'No deadline').classes()).toContain('active')
  })

  it('starts watching the choices for telemetry only after putting them back', async () => {
    mockAll = [
      { id: 7, type: 'Offer', deliveryPossible: true, deadline: inThreeDays },
    ]
    mount(Page, { global: { stubs } })
    await flushPromises()

    expect(watchedAt).toEqual([{ delivery: true, deadline: true }])
  })
})
