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
const mockLoading = ref(false)

vi.mock('~/modtools/composables/useAIImages', () => ({
  useAIImages: () => ({
    images: mockImages,
    loading: mockLoading,
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
  mockLoading.value = false
  mockFetchReview.mockReset()
  mockRegenerate.mockReset()
  mockAccept.mockReset()
  // Dynamic import so mocks are applied first.
  // Vite's import-analysis doesn't support dynamic imports with ~ alias.
  // Using a relative path from the vitest working directory.
  const mod = await import('./../../../../modtools/pages/images/index.vue')
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
    mockRegenerate.mockResolvedValue({ preview_url: 'https://delivery.ilovefreegle.org?url=test' })
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    const regenBtn = wrapper.find('[data-testid="regenerate-btn"]')
    expect(regenBtn.exists()).toBe(true)
    await regenBtn.trigger('click')
    await flushPromises()
    expect(mockRegenerate).toHaveBeenCalledWith(42, expect.any(String))
  })

  it('shows preview image after regeneration', async () => {
    const previewURL = 'https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/abc123'
    mockRegenerate.mockResolvedValue({ preview_url: previewURL })
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    const regenBtn = wrapper.find('[data-testid="regenerate-btn"]')
    await regenBtn.trigger('click')
    await flushPromises()

    // Preview image should now appear with the delivery URL.
    const imgs = wrapper.findAll('img')
    const found = imgs.some((img) => img.attributes('src') === previewURL)
    expect(found).toBe(true)
  })

  it('shows pending_image_url from API on initial load without regenerating', async () => {
    // Simulate an image that already has a pending preview from a prior regeneration.
    mockImages.value = [
      {
        id: 42,
        name: 'Bicycle',
        externaluid: 'freegletusd-bike',
        image_url: 'https://example.com/bike.jpg',
        status: 'regenerating',
        regeneration_notes: null,
        pending_externaluid: 'freegletusd-new-preview',
        pending_image_url: 'https://delivery.ilovefreegle.org?url=freegletusd-new-preview',
        votes: [],
        reject_count: 5,
        approve_count: 0,
      },
    ]
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    // The pending_image_url should be shown without clicking Regenerate.
    const imgs = wrapper.findAll('img')
    const found = imgs.some((img) => img.attributes('src')?.includes('freegletusd-new-preview'))
    expect(found).toBe(true)

    // Accept button should be visible since there's already a preview.
    expect(wrapper.find('[data-testid="accept-btn"]').exists()).toBe(true)
  })

  it('calls accept when Accept button is clicked after regeneration', async () => {
    const previewURL = 'https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/abc123'
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
    const previewURL = 'https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/abc123'
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

describe('AIImageReview loading and edge cases', () => {
  it('shows loading spinner when loading is true', async () => {
    mockLoading.value = true
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    expect(wrapper.find('.spinner').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('No images')
  })

  it('shows "No image" placeholder when image_url is absent', async () => {
    mockImages.value = [
      {
        id: 1,
        name: 'Orphaned AI image',
        externaluid: 'freegletusd-no-image',
        image_url: null,
        status: 'rejected',
        regeneration_notes: null,
        pending_externaluid: null,
        pending_image_url: null,
        votes: [],
        reject_count: 1,
        approve_count: 0,
      },
    ]
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    expect(wrapper.text()).toContain('No image')
  })

  it('shows "contains people" badge for vote with containspeople flag', async () => {
    mockImages.value = [
      {
        id: 1,
        name: 'Portrait',
        externaluid: 'freegletusd-portrait',
        image_url: 'https://example.com/portrait.jpg',
        status: 'rejected',
        regeneration_notes: null,
        pending_externaluid: null,
        pending_image_url: null,
        votes: [
          { userid: 10, displayname: 'Alice Smith', result: 'Reject', containspeople: 1 },
        ],
        reject_count: 1,
        approve_count: 0,
      },
    ]
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()
    expect(wrapper.text()).toContain('contains people')
  })
})

describe('AIImageReview error handling', () => {
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
        votes: [],
        reject_count: 1,
        approve_count: 0,
      },
    ]
  })

  it('shows error when regenerate returns no preview_url', async () => {
    mockRegenerate.mockResolvedValue({})
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    await wrapper.find('[data-testid="regenerate-btn"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Generation returned no image')
  })

  it('shows error when regenerate throws', async () => {
    mockRegenerate.mockRejectedValue(new Error('Network error'))
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    await wrapper.find('[data-testid="regenerate-btn"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Generation failed')
  })

  it('shows error when accept throws', async () => {
    const previewURL = 'https://delivery.ilovefreegle.org?url=abc'
    mockRegenerate.mockResolvedValue({ preview_url: previewURL })
    mockAccept.mockRejectedValue(new Error('Accept error'))

    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    await wrapper.find('[data-testid="regenerate-btn"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="accept-btn"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Failed to accept')
  })

  it('shows spinner in image area while regeneration is in progress', async () => {
    let resolveRegen
    mockRegenerate.mockImplementation(
      () => new Promise((resolve) => { resolveRegen = resolve })
    )
    const wrapper = mount(ImagesPage, { global: { stubs: stubComponents } })
    await flushPromises()

    // await trigger() dispatches event and waits for Vue's next tick, but NOT for
    // the regeneration promise to resolve — so regenerating[id] stays true
    await wrapper.find('[data-testid="regenerate-btn"]').trigger('click')

    // previewFor(img) is null and regenerating[img.id] is true → spinner in image area
    expect(wrapper.find('.spinner').exists()).toBe(true)
    expect(wrapper.text()).toContain('Generating')

    // Clean up: resolve the promise
    resolveRegen({})
    await flushPromises()
  })
})
