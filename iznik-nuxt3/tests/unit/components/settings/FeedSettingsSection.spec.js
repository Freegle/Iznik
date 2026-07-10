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
    // Stub NearbyTowns - it fires a routing-backed API call and uses IntersectionObserver,
    // neither of which this section's tests should depend on.
    global: { stubs: { 'v-icon': true, 'nuxt-link': true, NearbyTowns: true } },
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

  it('explains the distance preference applies to browse, notifications and who sees your posts', () => {
    const wrapper = mountWith(BROWSE_DISTANCE_UNLIMITED)
    const text = wrapper.text().toLowerCase()
    expect(text).toContain('browse')
    expect(text).toContain('notifications')
    // The outbound half: the setting also caps who sees the member's own posts.
    expect(text).toContain('who sees your posts')
  })

  it('has no numeric miles readout (matches the browse slider; avoids janky drag)', () => {
    const wrapper = mountWith(3)
    expect(wrapper.text()).not.toContain('miles')
    expect(wrapper.text()).not.toContain('any distance')
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
