import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

import DetailsPage from '~/pages/ask/mobile/details.vue'

// ── Store mocks ───────────────────────────────────────────────────────────────

const mockRouterPush = vi.fn()
const mockRouterReplace = vi.fn()
globalThis.__testUseRouter = () => ({
  push: mockRouterPush,
  replace: mockRouterReplace,
  currentRoute: { value: { path: '/ask/mobile/details' } },
})

const mockSetItem = vi.fn()
const mockSetDescription = vi.fn()
const mockSetAttachments = vi.fn()
const mockSetType = vi.fn()
const mockSetAiDeclined = vi.fn()
let mockMessage = { id: 42, type: 'Wanted', item: '', description: '' }
let mockAttachments = []
const mockTick = ref(0)

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({
    setItem: mockSetItem,
    setDescription: mockSetDescription,
    setAttachmentsForMessage: mockSetAttachments,
    setType: mockSetType,
    setAiDeclined: mockSetAiDeclined,
    get messages() {
      return { 42: mockMessage }
    },
    get all() {
      return [{ id: 42, type: 'Wanted' }]
    },
    message: vi.fn(() => {
      mockTick.value
      return mockMessage
    }),
    attachments: vi.fn(() => {
      mockTick.value
      return mockAttachments
    }),
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ stickyAdRendered: false }),
}))

vi.mock('~/api', () => ({
  default: () => ({ message: { getIllustration: vi.fn() } }),
}))

// The experiment arm. The page asks for it with flowArm(FlowName.ASK, 'ask-description-optional').
let mockArm = null
const mockFlowArm = vi.fn((flow, name) => (name ? mockArm : null))
vi.mock('~/composables/useFlowTelemetry', () => ({
  FlowName: { GIVE: 'give', ASK: 'ask' },
  flowArm: (...args) => mockFlowArm(...args),
}))

function setState({ item = '', description = '', photos = [] }) {
  mockMessage = { id: 42, type: 'Wanted', item, description }
  mockAttachments = photos
  mockTick.value++
}

const REAL_PHOTO = { id: 1, paththumb: 'https://example.com/1.jpg' }
const AI_PHOTO = {
  id: 'ai-1',
  paththumb: 'https://example.com/ai.jpg',
  externalmods: { ai: true },
}

function mountPage() {
  return mount(DetailsPage, {
    global: {
      stubs: {
        'b-button': {
          template:
            '<button class="next" @click="$emit(\'click\')"><slot /></button>',
          emits: ['click'],
        },
        'b-form-input': {
          template: '<input />',
          props: ['modelValue', 'state'],
        },
        'b-form-textarea': {
          template: '<textarea id="description" />',
          props: ['modelValue', 'state'],
        },
        'v-icon': { template: '<i />' },
        OurUploadedImage: { template: '<img />' },
      },
    },
  })
}

async function pressNext(wrapper) {
  await wrapper.find('.next').trigger('click')
  await flushPromises()
}

describe('pages/ask/mobile/details.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockArm = null
    setState({})
  })

  describe('control (the rule as it was)', () => {
    it('no description and no photo: shows the describe error and stays', async () => {
      setState({ item: 'Kids bike for 8 year old' })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(wrapper.text()).toContain('Please add a description')
      expect(mockSetDescription).not.toHaveBeenCalled()
      expect(mockRouterPush).not.toHaveBeenCalled()
    })

    it('an AI picture does not count as a photo', async () => {
      setState({ item: 'Kids bike for 8 year old', photos: [AI_PHOTO] })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(wrapper.text()).toContain('Please add a description')
      expect(mockRouterPush).not.toHaveBeenCalled()
    })

    it('arm named control behaves the same', async () => {
      mockArm = 'control'
      setState({ item: 'Kids bike for 8 year old' })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(wrapper.text()).toContain('Please add a description')
      expect(mockSetDescription).not.toHaveBeenCalled()
    })

    it('a description goes on to the next step', async () => {
      setState({ item: 'Kids bike', description: 'Any colour is fine' })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(mockRouterPush).toHaveBeenCalledWith('/ask/mobile/whereami')
    })
  })

  describe('ask-description-optional experiment', () => {
    it('reads the arm for the ask flow by its switch name, once mounted', async () => {
      mountPage()
      await flushPromises()
      expect(mockFlowArm).toHaveBeenCalledTimes(1)
      expect(mockFlowArm).toHaveBeenCalledWith(
        'ask',
        'ask-description-optional'
      )
    })

    it('arm b: no description and no photo sets the body to the item name and goes on', async () => {
      mockArm = 'b'
      setState({ item: 'Kids bike for 8 year old' })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(mockSetDescription).toHaveBeenCalledWith({
        id: 42,
        description: 'Kids bike for 8 year old',
      })
      expect(mockRouterPush).toHaveBeenCalledWith('/ask/mobile/whereami')
      expect(wrapper.text()).not.toContain('Please add a description')
    })

    it('arm b: an AI picture alone still lets the title stand', async () => {
      mockArm = 'b'
      setState({ item: 'Kids bike', photos: [AI_PHOTO] })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(mockSetDescription).toHaveBeenCalledWith({
        id: 42,
        description: 'Kids bike',
      })
      expect(mockRouterPush).toHaveBeenCalledWith('/ask/mobile/whereami')
    })

    it('arm b: a description the member typed is kept', async () => {
      mockArm = 'b'
      setState({ item: 'Kids bike', description: 'Any colour is fine' })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(mockSetDescription).not.toHaveBeenCalled()
      expect(mockRouterPush).toHaveBeenCalledWith('/ask/mobile/whereami')
    })

    it('arm b: a number as the description is replaced by the item name', async () => {
      mockArm = 'b'
      setState({ item: 'Kids bike', description: '24' })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(mockSetDescription).toHaveBeenCalledWith({
        id: 42,
        description: 'Kids bike',
      })
    })

    it('arm b: an item name under 3 characters still gets the prompt', async () => {
      mockArm = 'b'
      setState({ item: 'TV' })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(mockSetDescription).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('Please add a description')
      expect(mockRouterPush).not.toHaveBeenCalled()
    })

    it('arm b: an empty item name is still refused', async () => {
      mockArm = 'b'
      setState({ item: '' })
      const wrapper = mountPage()
      await pressNext(wrapper)
      expect(mockSetDescription).not.toHaveBeenCalled()
      expect(mockRouterPush).not.toHaveBeenCalled()
    })

    it('does not count a member who arrives with a real photo, who is never under the rule', async () => {
      mockArm = 'b'
      setState({ item: 'Kids bike', photos: [REAL_PHOTO] })
      const wrapper = mountPage()
      await flushPromises()
      expect(mockFlowArm).not.toHaveBeenCalled()
      await pressNext(wrapper)
      expect(mockSetDescription).not.toHaveBeenCalled()
      expect(mockRouterPush).toHaveBeenCalledWith('/ask/mobile/whereami')
    })

    it('counts a member who arrives with a real photo and removes it, once', async () => {
      mockArm = 'b'
      setState({ item: 'Kids bike', photos: [REAL_PHOTO] })
      mountPage()
      await flushPromises()
      expect(mockFlowArm).not.toHaveBeenCalled()

      setState({ item: 'Kids bike', photos: [] })
      await flushPromises()
      setState({ item: 'Kids bike', photos: [] })
      await flushPromises()
      expect(mockFlowArm).toHaveBeenCalledTimes(1)
    })
  })
})
