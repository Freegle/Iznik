import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ReachPicker from '~/components/ReachPicker.vue'

const mockMe = ref({ lat: 51.5, lng: -0.1 })

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

const { mockFetchNear, mockFetchByLatLng } = vi.hoisted(() => ({
  mockFetchNear: vi.fn(),
  mockFetchByLatLng: vi.fn(),
}))
vi.mock('~/api', () => ({
  default: () => ({ town: { fetchNear: mockFetchNear } }),
}))
vi.mock('~/stores/location', () => ({
  useLocationStore: () => ({ fetchByLatLng: mockFetchByLatLng }),
}))

function mountPicker(modelValue, props = {}) {
  return mount(ReachPicker, {
    props: { modelValue, ...props },
    global: {
      stubs: {
        RangeSlider: {
          props: ['modelValue', 'min', 'max'],
          emits: ['update:modelValue', 'change'],
          template: '<input class="range" :value="modelValue" />',
        },
        NearbyTowns: {
          props: ['minutes', 'lat', 'lng', 'perspective'],
          template: '<div class="towns" />',
        },
        PostCode: {
          emits: ['selected'],
          template: '<div class="postcode" />',
        },
        'b-button': {
          emits: ['click'],
          template: '<button @click="$emit(\'click\')"><slot /></button>',
        },
      },
    },
  })
}

describe('ReachPicker', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.value = { lat: 51.5, lng: -0.1 }
    mockFetchNear.mockResolvedValue({ cap_minutes: 20 })
    mockFetchByLatLng.mockResolvedValue({ name: 'EH1 1AA' })
  })

  // A new event reaches as far as a post from there would: the cap at the centre.
  it('starts a new item at the reach cap for the poster', async () => {
    const wrapper = mountPicker({ lat: null, lng: null, reachminutes: null })
    await flushPromises()

    expect(mockFetchNear).toHaveBeenCalledWith(51.5, -0.1, 30)
    expect(wrapper.text()).toContain('Shown around your location')
    const emitted = wrapper.emitted('update:modelValue')
    expect(emitted.at(-1)[0].reachminutes).toBe(20)
  })

  it('keeps a reach already chosen', async () => {
    const wrapper = mountPicker({ lat: null, lng: null, reachminutes: 10 })
    await flushPromises()

    expect(mockFetchNear).not.toHaveBeenCalled()
    expect(wrapper.find('.range').element.value).toBe('10')
  })

  it('names a centre somewhere else', async () => {
    const wrapper = mountPicker({ lat: 55.95, lng: -3.19, reachminutes: 25 })
    await flushPromises()

    expect(mockFetchByLatLng).toHaveBeenCalledWith(55.95, -3.19)
    expect(wrapper.text()).toContain('Shown around EH1 1AA')
    expect(wrapper.find('.towns').exists()).toBe(true)
  })

  it('moves the centre to a chosen postcode', async () => {
    const wrapper = mountPicker({ lat: null, lng: null, reachminutes: 15 })
    await flushPromises()

    await wrapper.find('button').trigger('click')
    wrapper
      .findComponent('.postcode')
      .vm.$emit('selected', { name: 'CB1 1AA', lat: 52.2, lng: 0.12 })
    await flushPromises()

    const last = wrapper.emitted('update:modelValue').at(-1)[0]
    expect(last).toMatchObject({ lat: 52.2, lng: 0.12, reachminutes: 15 })
  })

  // Sent explicitly so an existing item moves back, rather than null meaning "unchanged".
  it('moves the centre back to the poster', async () => {
    const wrapper = mountPicker({ lat: 55.95, lng: -3.19, reachminutes: 15 })
    await flushPromises()

    await wrapper.find('button').trigger('click')
    const back = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Use my location'))
    await back.trigger('click')

    const last = wrapper.emitted('update:modelValue').at(-1)[0]
    expect(last).toMatchObject({ lat: 51.5, lng: -0.1 })
  })

  it('has nothing to pick for an online opportunity', () => {
    const wrapper = mountPicker(
      { lat: null, lng: null, reachminutes: null },
      { online: true }
    )

    expect(wrapper.text()).toContain('shown to freeglers everywhere')
    expect(wrapper.find('.range').exists()).toBe(false)
  })
})
