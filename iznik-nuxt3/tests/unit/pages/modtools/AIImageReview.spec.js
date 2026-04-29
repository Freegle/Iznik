import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'

// definePageMeta is a Nuxt compiler macro not available in Vitest
globalThis.definePageMeta = vi.fn()

// Mock useMe composable
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    supportOrAdmin: ref(true),
  }),
}))

// Mock useAIImages composable — the page uses this, not $api directly
const mockFetchReview = vi.fn()
const mockRegenerate = vi.fn()
const mockAccept = vi.fn()
const mockImages = ref([])

vi.mock('~/modtools/composables/useAIImages', () => ({
  useAIImages: () => ({
    images: mockImages,
    loading: ref(false),
    fetchReview: mockFetchReview,
    regenerate: mockRegenerate,
    accept: mockAccept,
    count: ref(0),
    fetchCount: vi.fn(),
  }),
}))

// useNuxtApp mock (required for the composable internals even though we mock useAIImages)
vi.mock('#app', () => ({
  useNuxtApp: () => ({ $api: {} }),
  defineNuxtPlugin: (fn) => fn,
  useRoute: () => ({ params: {}, query: {}, path: '/' }),
  useRouter: () => ({ push: vi.fn() }),
}))

// Minimal stub for child components used on the page.
const stubComponents = {
  'b-button': { template: '<button v-bind="$attrs"><slot /></button>' },
  'b-spinner': { template: '<span class="spinner" />' },
  'b-badge': { template: '<span class="badge"><slot /></span>' },
  'b-form-textarea': {
    template: '<textarea v-bind="$attrs" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
    props: ['modelValue'],
    emits: ['update:modelValue'],
  },
  'b-img': { template: '<img v-bind="$attrs" />' },
  'b-card': { template: '<div class="card"><slot /></div>' },
  'b-alert': { template: '<div class="alert"><slot /></div>' },
}

let ImagesPage

beforeEach(async () => {
  setActivePinia(createPinia())
  mockImages.value = []
  mockFetchReview.mockReset()
  mockRegenerate.mockReset()
  mockAccept.mockReset()
  // Dynamic import so mocks are applied first.
  const mod = await import('~/modtools/pages/images/index.vue')
  ImagesPage = mod.default
})

describe('Images page', () => {
  it('calls fetchReview on mount', async () => {
    mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    expect(mockFetchReview).toHaveBeenCalledOnce()
  })

  it('shows empty state when no images need regeneration', async () => {
    mockImages.value = []
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    expect(wrapper.text()).toContain('No images')
  })

  it('renders one card per rejected image', async () => {
    mockImages.value = [
      {
        id: 1,
        name: 'Calculator',
        externaluid: 'freegletusd-old1',
        image_url: 'https://example.com/old1.jpg',
        status: 'rejected',
        regeneration_notes: null,
        pending_externaluid: null,
        votes: [
          { userid: 10, displayname: 'Alice Smith', result: 'Reject', containspeople: 0 },
          { userid: 11, displayname: 'Bob Jones', result: 'Reject', containspeople: 0 },
        ],
        reject_count: 2,
        approve_count: 0,
      },
      {
        id: 2,
        name: 'Hammer',
        externaluid: 'freegletusd-old2',
        image_url: 'https://example.com/old2.jpg',
        status: 'rejected',
        regeneration_notes: null,
        pending_externaluid: null,
        votes: [],
        reject_count: 3,
        approve_count: 1,
      },
    ]
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    // Each image should show its name.
    expect(wrapper.text()).toContain('Calculator')
    expect(wrapper.text()).toContain('Hammer')
  })

  it('displays voter names for each image', async () => {
    mockImages.value = [
      {
        id: 1,
        name: 'Sofa',
        externaluid: 'freegletusd-sofa',
        image_url: 'https://example.com/sofa.jpg',
        status: 'rejected',
        regeneration_notes: null,
        pending_externaluid: null,
        votes: [
          { userid: 20, displayname: 'Carol White', result: 'Reject', containspeople: 0 },
          { userid: 21, displayname: 'Dave Brown', result: 'Approve', containspeople: 0 },
        ],
        reject_count: 1,
        approve_count: 1,
      },
    ]
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    expect(wrapper.text()).toContain('Carol White')
    expect(wrapper.text()).toContain('Dave Brown')
  })

  it('shows reject and approve counts', async () => {
    mockImages.value = [
      {
        id: 1,
        name: 'Lamp',
        externaluid: 'freegletusd-lamp',
        image_url: 'https://example.com/lamp.jpg',
        status: 'rejected',
        regeneration_notes: null,
        pending_externaluid: null,
        votes: [],
        reject_count: 4,
        approve_count: 1,
      },
    ]
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    expect(wrapper.text()).toContain('4')
    expect(wrapper.text()).toContain('1')
  })
})

describe('AIImageReview card interactions', () => {
  beforeEach(() => {
    mockImages.value = [
      {
        id: 42,
        name: 'Bicycle',
        externaluid: 'freegletusd-bike',
        image_url: 'https://example.com/bike.jpg',
        status: 'rejected',
        regeneration_notes: null,
        pending_externaluid: null,
        pending_image_url: null,
        votes: [{ userid: 5, displayname: 'Eve Green', result: 'Reject', containspeople: 0 }],
        reject_count: 5,
        approve_count: 0,
      },
    ]
  })

  it('calls regenerate when Regenerate button is clicked', async () => {
    mockRegenerate.mockResolvedValue({ preview_url: 'https://image.pollinations.ai/prompt/test' })
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    const regenBtn = wrapper.find('[data-testid="regenerate-btn"]')
    expect(regenBtn.exists()).toBe(true)
    await regenBtn.trigger('click')
    await flushPromises()
    expect(mockRegenerate).toHaveBeenCalledWith(42, expect.any(String))
  })

  it('shows preview image after regeneration', async () => {
    const previewURL = 'https://image.pollinations.ai/prompt/bicycle'
    mockRegenerate.mockResolvedValue({ preview_url: previewURL })
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    const regenBtn = wrapper.find('[data-testid="regenerate-btn"]')
    await regenBtn.trigger('click')
    await flushPromises()

    // Preview image should now appear.
    const imgs = wrapper.findAll('img')
    const found = imgs.some((img) => img.attributes('src') === previewURL)
    expect(found).toBe(true)
  })

  it('calls accept when Accept button is clicked after regeneration', async () => {
    const previewURL = 'https://image.pollinations.ai/prompt/bicycle'
    mockRegenerate.mockResolvedValue({ preview_url: previewURL })
    mockAccept.mockResolvedValue({ ret: 0 })

    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    // Trigger regeneration first.
    await wrapper.find('[data-testid="regenerate-btn"]').trigger('click')
    await flushPromises()

    // Accept button should appear.
    const acceptBtn = wrapper.find('[data-testid="accept-btn"]')
    expect(acceptBtn.exists()).toBe(true)
    await acceptBtn.trigger('click')
    await flushPromises()

    expect(mockAccept).toHaveBeenCalledWith(42, expect.any(String))
  })

  it('removes image from list after accept', async () => {
    const previewURL = 'https://image.pollinations.ai/prompt/bicycle'
    mockRegenerate.mockResolvedValue({ preview_url: previewURL })
    mockAccept.mockResolvedValue({ ret: 0 })

    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    expect(wrapper.text()).toContain('Bicycle')

    await wrapper.find('[data-testid="regenerate-btn"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="accept-btn"]').trigger('click')
    await flushPromises()

    // Image should be removed from the list after accept.
    expect(wrapper.text()).not.toContain('Bicycle')
  })
})
