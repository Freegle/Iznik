import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createSSRApp, h, ref } from 'vue'
import { renderToString } from 'vue/server-renderer'

import PhotosPage from '~/pages/ask/mobile/photos.vue'

// ── Store mocks ───────────────────────────────────────────────────────────────

const mockRouterPush = vi.fn()
// useRouter is auto-imported (#imports); the test harness resolves it via the
// globalThis.__testUseRouter hook (see tests/unit/setup.ts), so override that.
globalThis.__testUseRouter = () => ({
  push: mockRouterPush,
  currentRoute: { value: { path: '/ask/mobile/photos' } },
})

const mockComposeAdd = vi.fn().mockReturnValue(42)
const mockSetType = vi.fn()
const mockSetAttachments = vi.fn()
let mockComposeMessages = {}
let mockComposeAll = []
let mockComposeAttachments = []
const mockAttachmentsTick = ref(0)

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({
    add: mockComposeAdd,
    setType: mockSetType,
    setAttachmentsForMessage: mockSetAttachments,
    get messages() {
      return mockComposeMessages
    },
    get all() {
      return mockComposeAll
    },
    // Reads the tick so a test can change the photos and have the page see it, as the store would.
    attachments: vi.fn(() => {
      mockAttachmentsTick.value
      return mockComposeAttachments
    }),
  }),
}))

function setPhotos(list) {
  mockComposeAttachments = list
  mockAttachmentsTick.value++
}

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ stickyAdRendered: false }),
}))

// The experiment arm. The page asks for it with flowArm(FlowName.ASK, 'ask-photos-carry-on').
let mockArm = null
const mockFlowArm = vi.fn((flow, name) => (name ? mockArm : null))
vi.mock('~/composables/useFlowTelemetry', () => ({
  FlowName: { GIVE: 'give', ASK: 'ask' },
  flowArm: (...args) => mockFlowArm(...args),
}))

// ── Component stubs ───────────────────────────────────────────────────────────

const PhotoUploaderStub = {
  name: 'PhotoUploader',
  template: '<div class="photo-uploader-stub" />',
  props: ['modelValue', 'type', 'recognise', 'carryOn'],
  emits: ['update:modelValue', 'photo-processed', 'skip'],
}

function mountPage() {
  return mount(PhotosPage, {
    global: {
      stubs: {
        PhotoUploader: PhotoUploaderStub,
        'b-button': {
          template:
            '<button class="b-btn" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size'],
          emits: ['click'],
        },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

const carryOnProp = (wrapper) =>
  wrapper.findComponent(PhotoUploaderStub).props('carryOn')

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('pages/ask/mobile/photos.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockComposeMessages = { 42: { id: 42, type: 'Wanted' } }
    mockComposeAll = []
    setPhotos([])
    mockComposeAdd.mockReturnValue(42)
    mockArm = null
    mockFlowArm.mockClear()
  })

  it('mounts and renders the PhotoUploader', () => {
    const wrapper = mountPage()
    expect(wrapper.find('.photo-uploader-stub').exists()).toBe(true)
  })

  it('goes to the details step when the member skips', async () => {
    const wrapper = mountPage()
    wrapper.findComponent(PhotoUploaderStub).vm.$emit('skip')
    await flushPromises()
    expect(mockRouterPush).toHaveBeenCalledWith('/ask/mobile/details')
  })

  describe('ask-photos-carry-on experiment', () => {
    it('reads the arm for the ask flow by its switch name, once the page is on screen', async () => {
      mountPage()
      await flushPromises()
      expect(mockFlowArm).toHaveBeenCalledWith('ask', 'ask-photos-carry-on')
    })

    it('control: does not turn the carry-on variant on', async () => {
      mockArm = null
      const wrapper = mountPage()
      await flushPromises()
      expect(carryOnProp(wrapper)).toBe(false)
    })

    it('control arm named control: does not turn the variant on', async () => {
      mockArm = 'control'
      const wrapper = mountPage()
      await flushPromises()
      expect(carryOnProp(wrapper)).toBe(false)
    })

    it('variant arm b: turns the carry-on variant on', async () => {
      mockArm = 'b'
      const wrapper = mountPage()
      await flushPromises()
      expect(carryOnProp(wrapper)).toBe(true)
    })

    it('the server render never reads the arm and shows control, so a client in arm b hydrates the same markup', async () => {
      mockArm = 'b'
      const app = createSSRApp(PhotosPage)
      app.component('BButton', {
        render() {
          return h('button', this.$slots.default?.())
        },
      })
      app.component('VIcon', { render: () => h('i') })
      app.component('PhotoUploader', {
        props: ['modelValue', 'type', 'recognise', 'carryOn'],
        render() {
          return h('div', { 'data-carry-on': String(this.carryOn) })
        },
      })
      const html = await renderToString(app)
      expect(html).toContain('data-carry-on="false"')
      expect(mockFlowArm).not.toHaveBeenCalled()
    })

    it('does not count a member who arrives with a photo already, who never sees the button', async () => {
      mockArm = 'b'
      setPhotos([{ id: 1, paththumb: 'https://example.com/1.jpg' }])
      const wrapper = mountPage()
      await flushPromises()
      // Reading the arm counts the member as having seen the variant, so it is not read at all.
      expect(mockFlowArm).not.toHaveBeenCalled()
      expect(carryOnProp(wrapper)).toBe(false)
    })

    it('counts a member who arrives with a photo and removes it, once the button can show', async () => {
      mockArm = 'b'
      setPhotos([{ id: 1, paththumb: 'https://example.com/1.jpg' }])
      const wrapper = mountPage()
      await flushPromises()
      expect(carryOnProp(wrapper)).toBe(false)

      setPhotos([])
      await flushPromises()

      expect(mockFlowArm).toHaveBeenCalledTimes(1)
      expect(carryOnProp(wrapper)).toBe(true)
    })

    it('hides the button while a photo is there, shows it again when removed, and counts once', async () => {
      mockArm = 'b'
      const wrapper = mountPage()
      await flushPromises()
      expect(carryOnProp(wrapper)).toBe(true)

      setPhotos([{ id: 1, paththumb: 'https://example.com/1.jpg' }])
      await flushPromises()
      expect(carryOnProp(wrapper)).toBe(false)

      setPhotos([])
      await flushPromises()
      expect(carryOnProp(wrapper)).toBe(true)
      expect(mockFlowArm).toHaveBeenCalledTimes(1)
    })

    it('variant: the carry-on button goes to the details step like Skip', async () => {
      mockArm = 'b'
      const wrapper = mountPage()
      wrapper.findComponent(PhotoUploaderStub).vm.$emit('skip')
      await flushPromises()
      expect(mockRouterPush).toHaveBeenCalledWith('/ask/mobile/details')
    })
  })
})
