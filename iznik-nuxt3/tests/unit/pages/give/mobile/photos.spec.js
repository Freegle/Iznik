import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import PhotosPage from '~/pages/give/mobile/photos.vue'

// ── Store mocks ───────────────────────────────────────────────────────────────

const mockRouterPush = vi.fn()
// useRouter is auto-imported (#imports); the test harness resolves it via the
// globalThis.__testUseRouter hook (see tests/unit/setup.ts), so override that —
// vi.stubGlobal('useRouter', ...) does not intercept the auto-import.
globalThis.__testUseRouter = () => ({
  push: mockRouterPush,
  currentRoute: { value: { path: '/give/mobile/photos' } },
})

const mockComposeAdd = vi.fn().mockReturnValue(42)
const mockSetType = vi.fn()
const mockSetAttachments = vi.fn()
let mockComposeMessages = {}
let mockComposeAll = []

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
    attachments: vi.fn().mockReturnValue([]),
  }),
}))

let mockAuthUser = { id: 1 }
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: mockAuthUser }),
}))

let mockStickyAdRendered = false
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get stickyAdRendered() {
      return mockStickyAdRendered
    },
  }),
}))

let mockPendingSharedImages = []
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({
    get pendingSharedImages() {
      return mockPendingSharedImages
    },
    set pendingSharedImages(v) {
      mockPendingSharedImages = v
    },
    isApp: true,
  }),
}))

// ── Component stubs ───────────────────────────────────────────────────────────

const mockProcessPhoto = vi.fn().mockResolvedValue(undefined)
const PhotoUploaderStub = {
  name: 'PhotoUploader',
  template: '<div class="photo-uploader-stub" />',
  props: ['modelValue', 'type', 'recognise'],
  emits: ['update:modelValue', 'photo-processed', 'skip'],
  methods: { processPhoto: mockProcessPhoto },
}

function mountPage() {
  return mount(PhotosPage, {
    global: {
      stubs: {
        PhotoUploader: PhotoUploaderStub,
        'b-button': {
          template:
            '<button class="b-btn" @click="$emit(\'click\')"><slot /></button>',
          emits: ['click'],
        },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('pages/give/mobile/photos.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockPendingSharedImages = []
    mockComposeMessages = { 42: { id: 42, type: 'Offer' } }
    mockComposeAll = []
    mockComposeAdd.mockReturnValue(42)
    mockAuthUser = { id: 1 }
    mockStickyAdRendered = false
  })

  it('mounts and renders the PhotoUploader', () => {
    const wrapper = mountPage()
    expect(wrapper.find('.photo-uploader-stub').exists()).toBe(true)
  })

  it('creates a new message when no existing Offer is found', () => {
    mountPage()
    expect(mockComposeAdd).toHaveBeenCalled()
    expect(mockSetType).toHaveBeenCalledWith({ id: 42, type: 'Offer' })
  })

  it('reuses an existing Offer message from the compose store', () => {
    mockComposeAll = [{ id: 99, type: 'Offer', savedBy: 1 }]
    mockComposeMessages = { 99: { id: 99 } }
    mountPage()
    expect(mockComposeAdd).not.toHaveBeenCalled()
    expect(mockSetType).toHaveBeenCalledWith({ id: 99, type: 'Offer' })
  })

  it('does not show the Next button when there are no attachments', () => {
    const wrapper = mountPage()
    expect(wrapper.find('.b-btn').exists()).toBe(false)
  })

  it('applies has-sticky-ad class when stickyAdRendered is true', () => {
    mockStickyAdRendered = true
    const wrapper = mountPage()
    expect(wrapper.find('.app-give-photos').classes()).toContain(
      'has-sticky-ad'
    )
  })

  it('goNext() navigates to /give/mobile/details', async () => {
    const wrapper = mountPage()
    // Access exposed method via component instance isn't available with setup(),
    // so trigger via skip event from PhotoUploader.
    wrapper.findComponent(PhotoUploaderStub).vm.$emit('skip')
    await flushPromises()
    expect(mockRouterPush).toHaveBeenCalledWith('/give/mobile/details')
  })

  describe('onMounted with pending shared images', () => {
    it('does nothing when pendingSharedImages is empty', async () => {
      mockPendingSharedImages = []
      mountPage()
      await flushPromises()
      expect(mockProcessPhoto).not.toHaveBeenCalled()
    })

    it('attaches shared images and clears pendingSharedImages', async () => {
      mockPendingSharedImages = [
        'capacitor://localhost/cache/img1.jpg',
        'capacitor://localhost/cache/img2.jpg',
      ]
      mountPage()
      await flushPromises()
      expect(mockPendingSharedImages).toEqual([])
    })
  })
})
