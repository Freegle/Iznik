import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import DetailsPage from '~/pages/give/mobile/details.vue'

// The phone and app Offer form shows the same notes under the item box as the desktop form
// (Discourse 9939/73: the car seat note never showed in the app).

globalThis.__testUseRouter = () => ({
  push: vi.fn(),
  replace: vi.fn(),
  currentRoute: { value: { path: '/give/mobile/details' } },
})

let mockItem = ''

vi.mock('~/stores/compose', async () => {
  const { reactive } = await import('vue')
  const store = reactive({
    get all() {
      return [{ id: 7, type: 'Offer' }]
    },
    get messages() {
      return { 7: { id: 7, type: 'Offer' } }
    },
    setType: vi.fn(),
    attachments: () => [],
    message: () => ({ id: 7, item: mockItem, description: 'Good one' }),
    setItem: vi.fn(),
    setDescription: vi.fn(),
    setAvailableNow: vi.fn(),
    setAttachmentsForMessage: vi.fn(),
    setAiDeclined: vi.fn(),
  })
  return { useComposeStore: () => store }
})

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ stickyAdRendered: false }),
}))

vi.mock('~/api', () => ({ default: () => ({}) }))

vi.mock('~/composables/useAiIllustration', () => ({
  fetchAiIllustration: vi.fn(),
}))

vi.mock('~/composables/useFlowTelemetry', () => ({
  FlowName: { GIVE: 'give', ASK: 'ask' },
  flowArm: () => null,
}))

function mountPage() {
  return mount(DetailsPage, {
    global: {
      stubs: {
        'b-button': { template: '<button><slot /></button>' },
        'b-form-input': { template: '<input />', props: ['modelValue'] },
        'b-form-textarea': { template: '<textarea />', props: ['modelValue'] },
        'v-icon': { template: '<i />' },
        OurUploadedImage: { template: '<img />' },
        NumberIncrementDecrement: { template: '<div />' },
      },
    },
  })
}

describe('pages/give/mobile/details.vue item notes', () => {
  beforeEach(() => {
    mockItem = ''
  })

  it('tells someone offering a car seat what to say', () => {
    mockItem = 'Kids car seat'
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('Car seats')
    expect(wrapper.text()).toContain('Please say the make and model')
  })

  it('shows the other notes too', () => {
    mockItem = 'Cycle helmet'
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('Motorcycle and cycle helmets')
  })

  it('shows no note for a child carrier', () => {
    mockItem = 'Child carrier backpack'
    const wrapper = mountPage()
    expect(wrapper.text()).not.toContain('Car seats')
  })
})
