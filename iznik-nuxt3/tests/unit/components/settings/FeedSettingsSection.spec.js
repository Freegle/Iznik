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
  BROWSE_MINUTES_FALLBACK_MAX: 30,
  BROWSE_MINUTES_MAX: 45,
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
  // clearAllMocks wipes call history but NOT implementations, so a test that overrides
  // fetchNear (to fake a density band) would otherwise leak its response into the next.
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchNear.mockResolvedValue({ reach_radius_miles: 4 })
  })

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

  // The top of the range is the member's own density-sized reach cap, not a fixed 30: the
  // reach engine grows a post to the widest band's budget and holds each member to their
  // own, so the slider must offer exactly what it will honour for them. Until the server
  // answers, the flat cap applies.
  it('starts on the flat cap in 5-minute steps', () => {
    const wrapper = mountWith()
    const s = slider(wrapper)
    expect(Number(s.props('min'))).toBe(5)
    expect(Number(s.props('max'))).toBe(30)
    expect(Number(s.props('step'))).toBe(5)
  })

  it("opens the range to a rural member's own cap", async () => {
    mockFetchNear.mockResolvedValue({ cap_minutes: 45, density_band: 'sparse' })
    const wrapper = mountWith()
    await flushPromises()
    expect(Number(slider(wrapper).props('max'))).toBe(45)
  })

  it("closes the range to a city member's own cap", async () => {
    mockFetchNear.mockResolvedValue({ cap_minutes: 20, density_band: 'dense' })
    const wrapper = mountWith()
    await flushPromises()
    expect(Number(slider(wrapper).props('max'))).toBe(20)
  })

  // Left of max: persist the chosen MINUTES (so the slider restores) and the routing-derived
  // crow-flies mile radius as browseMaxDistance (the value the fast feed filter reads).
  it('persists the chosen travel time and routing-derived radius via saveAndGet', async () => {
    const wrapper = mountWith()
    await slider(wrapper).vm.$emit('change', 10)
    await flushPromises()
    // false = no reach outline. Feed settings has no map to shade, so it must not make the
    // routing server trace a boundary nobody draws; only browse asks for that.
    expect(mockFetchNear).toHaveBeenCalledWith(51.5, -0.1, 10, false)
    expect(saveAndGet).toHaveBeenCalledTimes(1)
    expect(me.value.settings.browseMaxMinutes).toBe(10)
    expect(me.value.settings.browseMaxDistance).toBe(4)
  })

  it('stores the unlimited sentinel at the far end when the cap is the ceiling', async () => {
    mockFetchNear.mockResolvedValue({ cap_minutes: 45, density_band: 'sparse' })
    const wrapper = mountWith({ browseMaxMinutes: 10 })
    await flushPromises()
    mockFetchNear.mockClear()
    await slider(wrapper).vm.$emit('change', 45)
    await flushPromises()
    expect(me.value.settings.browseMaxMinutes).toBe(45)
    expect(me.value.settings.browseMaxDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
    expect(mockFetchNear).not.toHaveBeenCalled()
  })
})
