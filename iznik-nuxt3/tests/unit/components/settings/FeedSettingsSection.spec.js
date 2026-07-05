import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import FeedSettingsSection from '~/components/settings/FeedSettingsSection.vue'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'

const saveAndGet = vi.fn().mockResolvedValue({})
const me = ref({ settings: { browseMaxDistance: BROWSE_DISTANCE_UNLIMITED } })

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ saveAndGet }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me }),
}))

vi.mock('~/components/RangeSlider.vue', () => ({
  default: {
    name: 'RangeSlider',
    props: ['modelValue', 'min', 'max', 'step'],
    emits: ['update:modelValue', 'change'],
    template: '<div class="range-slider" />',
  },
}))

function mountWith(browseMaxDistance) {
  me.value = { settings: { browseMaxDistance } }
  return mount(FeedSettingsSection, {
    global: { stubs: { 'v-icon': true, 'nuxt-link': true } },
  })
}

const slider = (wrapper) => wrapper.findComponent({ name: 'RangeSlider' })

describe('FeedSettingsSection', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders the Feed section with a slider', () => {
    const wrapper = mountWith(BROWSE_DISTANCE_UNLIMITED)
    expect(wrapper.text()).toContain('Feed')
    expect(wrapper.find('.range-slider').exists()).toBe(true)
  })

  it('explains it applies to both browse and emails', () => {
    const wrapper = mountWith(BROWSE_DISTANCE_UNLIMITED)
    expect(wrapper.text()).toContain('browse')
    expect(wrapper.text().toLowerCase()).toContain('emails')
  })

  it('reads a saved mile value into the readout', () => {
    const wrapper = mountWith(3)
    expect(wrapper.text()).toContain('Showing posts within 3 miles.')
  })

  it('shows "any distance" when unlimited', () => {
    const wrapper = mountWith(BROWSE_DISTANCE_UNLIMITED)
    expect(wrapper.text()).toContain('Showing posts at any distance.')
  })

  it('persists a chosen mile value via saveAndGet', async () => {
    const wrapper = mountWith(BROWSE_DISTANCE_UNLIMITED)
    await slider(wrapper).vm.$emit('change', 5)
    expect(saveAndGet).toHaveBeenCalledTimes(1)
    expect(me.value.settings.browseMaxDistance).toBe(5)
  })

  it('stores the unlimited sentinel when dragged to the far end', async () => {
    const wrapper = mountWith(2)
    await slider(wrapper).vm.$emit('change', 30)
    expect(me.value.settings.browseMaxDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
  })
})
