import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import FeedSettingsSection from '~/components/settings/FeedSettingsSection.vue'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'

// Mock '~/constants' explicitly (the sentinel + time-based slider bounds) so the test doesn't depend
// on the real module resolving inside the Vitest setup - matching the PostFilters spec.
vi.mock('~/constants', () => ({
  BROWSE_DISTANCE_UNLIMITED: Number.MAX_SAFE_INTEGER,
  BROWSE_MINUTES_MIN: 5,
  BROWSE_MINUTES_MAX: 30,
  BROWSE_MINUTES_STEP: 5,
}))

const saveAndGet = vi.fn().mockResolvedValue({})
const me = ref({ lat: 51.5, lng: -0.1, settings: {} })

// The time-based slider converts the chosen minutes to a crow-flies mile radius via the routing-backed
// /town/near (api().town.fetchNear). Mock it to a fixed radius so a change stores a known value.
const { mockFetchNear } = vi.hoisted(() => ({
  mockFetchNear: vi.fn().mockResolvedValue({ reach_radius_miles: 4 }),
}))
vi.mock('~/api', () => ({
  default: () => ({ town: { fetchNear: mockFetchNear } }),
}))

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

function mountWith(settings = {}) {
  me.value = { lat: 51.5, lng: -0.1, settings: { ...settings } }
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
    const wrapper = mountWith()
    expect(wrapper.text()).toContain('Feed')
    expect(wrapper.find('.range-slider').exists()).toBe(true)
  })

  it('explains the distance preference applies to browse, notifications and who sees your posts', () => {
    const wrapper = mountWith()
    const text = wrapper.text().toLowerCase()
    expect(text).toContain('browse')
    expect(text).toContain('notifications')
    // The outbound half: the setting also caps who sees the member's own posts.
    expect(text).toContain('who sees your posts')
  })

  it('has no numeric readout (matches the browse slider; avoids janky drag)', () => {
    const wrapper = mountWith({ browseMaxMinutes: 10 })
    expect(wrapper.text()).not.toContain('miles')
    expect(wrapper.text()).not.toContain('any distance')
  })

  it('is a fixed 5-30 minute travel-time range', () => {
    const wrapper = mountWith()
    const s = slider(wrapper)
    expect(Number(s.props('min'))).toBe(5)
    expect(Number(s.props('max'))).toBe(30)
    expect(Number(s.props('step'))).toBe(5)
  })

  // Left of max: persist the chosen MINUTES (so the slider restores) and the routing-derived
  // crow-flies mile radius as browseMaxDistance (the value the fast feed filter reads).
  it('persists the chosen travel time and routing-derived radius via saveAndGet', async () => {
    const wrapper = mountWith()
    await slider(wrapper).vm.$emit('change', 10)
    await flushPromises()
    expect(mockFetchNear).toHaveBeenCalledWith(51.5, -0.1, 10)
    expect(saveAndGet).toHaveBeenCalledTimes(1)
    expect(me.value.settings.browseMaxMinutes).toBe(10)
    expect(me.value.settings.browseMaxDistance).toBe(4)
  })

  it('stores the unlimited sentinel (and skips routing) when dragged to the far end', async () => {
    const wrapper = mountWith({ browseMaxMinutes: 10 })
    await slider(wrapper).vm.$emit('change', 30)
    await flushPromises()
    expect(me.value.settings.browseMaxMinutes).toBe(30)
    expect(me.value.settings.browseMaxDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
    expect(mockFetchNear).not.toHaveBeenCalled()
  })
})
