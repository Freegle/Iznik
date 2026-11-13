import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import DetailsPage from '~/pages/give/mobile/details.vue'

// The phone and app Offer form shows the same notes under the item box as the desktop form
// (Discourse 9939/73: the car seat note never showed in the app).

const mockPush = vi.fn()

globalThis.__testUseRouter = () => ({
  push: mockPush,
  replace: vi.fn(),
  currentRoute: { value: { path: '/give/mobile/details' } },
})

let mockItem = ''
let mockDescription = 'Good one'
let mockAttachments = []

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
    attachments: () => mockAttachments,
    message: () => ({ id: 7, item: mockItem, description: mockDescription }),
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

// The final step needs a description of at least 3 characters when there is no real photo
// (composeStore.messageValid), so this screen has to say so rather than let the member on to a
// disabled button.
describe('pages/give/mobile/details.vue short description', () => {
  beforeEach(() => {
    mockItem = 'Sofa'
    mockDescription = 'Good one'
    mockAttachments = []
    mockPush.mockClear()
  })

  async function tapNext(wrapper) {
    const buttons = wrapper.findAll('button')
    await buttons[buttons.length - 1].trigger('click')
  }

  it('stops a 2 character description with no real photo and says why', async () => {
    mockDescription = 'hi'
    const wrapper = mountPage()
    await tapNext(wrapper)
    expect(mockPush).not.toHaveBeenCalled()
    expect(wrapper.find('.invalid-feedback').exists()).toBe(true)
  })

  it('stops a 2 character description when the only photo is the AI illustration', async () => {
    mockDescription = 'hi'
    mockAttachments = [{ id: 1, externalmods: { ai: true } }]
    const wrapper = mountPage()
    await tapNext(wrapper)
    expect(mockPush).not.toHaveBeenCalled()
  })

  it('lets a 3 character description through', async () => {
    mockDescription = 'old'
    const wrapper = mountPage()
    await tapNext(wrapper)
    expect(mockPush).toHaveBeenCalled()
  })

  it('lets a short description through when there is a real photo', async () => {
    mockDescription = 'hi'
    mockAttachments = [{ id: 1 }]
    const wrapper = mountPage()
    await tapNext(wrapper)
    expect(mockPush).toHaveBeenCalled()
  })
})
