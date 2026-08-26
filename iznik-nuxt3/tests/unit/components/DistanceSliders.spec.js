import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import DistanceSliders from '~/components/DistanceSliders.vue'

// The "How far away" control after the inbound/outbound split. The behaviour that matters most here
// is what does NOT happen: a member who never separates the two axes must be indistinguishable from
// before the split, and even one who opens the split control and thinks better of it must leave no
// trace. Both are asserted on saveAndGet, which is the only way a setting can reach the server.

vi.mock('~/constants', () => ({
  BROWSE_DISTANCE_UNLIMITED: Number.MAX_SAFE_INTEGER,
  BROWSE_MINUTES_MIN: 5,
  BROWSE_MINUTES_FALLBACK_MAX: 30,
  BROWSE_MINUTES_MAX: 45,
  BROWSE_MINUTES_STEP: 5,
  DISTANCE_AXES: {
    browse: {
      minutesKey: 'browseMaxMinutes',
      milesKey: 'browseMaxDistance',
      bandCapped: true,
    },
    myPosts: {
      minutesKey: 'myPostsMaxMinutes',
      milesKey: 'myPostsMaxDistance',
      bandCapped: false,
    },
  },
}))

const saveAndGet = vi.fn().mockResolvedValue({})
const me = ref({ lat: 51.5, lng: -0.1, settings: {} })

const { mockFetchNear } = vi.hoisted(() => ({
  mockFetchNear: vi.fn(),
}))
vi.mock('~/api', () => ({
  default: () => ({ town: { fetchNear: mockFetchNear } }),
}))
vi.mock('~/stores/auth', () => ({ useAuthStore: () => ({ saveAndGet }) }))
vi.mock('~/composables/useMe', () => ({ useMe: () => ({ me }) }))

vi.mock('~/components/RangeSlider.vue', () => ({
  default: {
    name: 'RangeSlider',
    props: ['modelValue', 'min', 'max', 'step', 'axisMax', 'ariaLabel'],
    emits: ['update:modelValue', 'change'],
    template: '<div class="range-slider" />',
  },
}))

function mountWith(settings = {}) {
  me.value = { lat: 51.5, lng: -0.1, settings: { ...settings } }
  return mount(DistanceSliders, {
    global: {
      stubs: {
        NearbyTowns: true,
        'b-button': {
          template: '<button @click="$emit(\'click\')"><slot /></button>',
        },
      },
    },
  })
}

const sliders = (wrapper) => wrapper.findAllComponents({ name: 'RangeSlider' })
const buttonWithText = (wrapper, text) =>
  wrapper.findAll('button').find((b) => b.text().includes(text))

describe('DistanceSliders', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchNear.mockResolvedValue({
      reach_radius_miles: 4,
      cap_minutes: 30,
      density_band: 'medium',
    })
  })

  it('shows one slider and the linked wording by default', async () => {
    const wrapper = mountWith({ browseMaxMinutes: 20, browseMaxDistance: 12 })
    await flushPromises()

    expect(sliders(wrapper)).toHaveLength(1)
    expect(wrapper.text()).toContain('Also limits who sees your posts')
    expect(wrapper.text()).not.toContain('Who sees my posts')
  })

  it('carries the agreed geography wording', async () => {
    const wrapper = mountWith()
    await flushPromises()

    expect(wrapper.text()).toContain(
      'We use road distance and travel time to take account of geography in deciding which posts you see'
    )
  })

  it('reveals the second slider when asked to set them separately', async () => {
    const wrapper = mountWith({ browseMaxMinutes: 20, browseMaxDistance: 12 })
    await flushPromises()

    await buttonWithText(wrapper, 'Set separately').trigger('click')
    await flushPromises()

    expect(sliders(wrapper)).toHaveLength(2)
    expect(wrapper.text()).toContain('Who sees my posts')
    expect(wrapper.text()).toContain('Posts I see')
  })

  // The guarantee the whole design rests on. Revealing the second slider is a UI state, not a
  // setting: the outbound keys appear only when the member actually drags it. Otherwise a member
  // who opened the control out of curiosity would have their outbound reach narrowed from
  // "unlimited" to their inbound choice without ever asking for it.
  it('writes NOTHING when the sliders are merely split apart', async () => {
    const wrapper = mountWith({ browseMaxMinutes: 20, browseMaxDistance: 12 })
    await flushPromises()

    await buttonWithText(wrapper, 'Set separately').trigger('click')
    await flushPromises()

    expect(saveAndGet).not.toHaveBeenCalled()
    expect(me.value.settings.myPostsMaxMinutes).toBeUndefined()
    expect(me.value.settings.myPostsMaxDistance).toBeUndefined()
  })

  it('persists only the outbound keys when the outbound slider is dragged', async () => {
    const wrapper = mountWith({ browseMaxMinutes: 20, browseMaxDistance: 12 })
    await flushPromises()
    await buttonWithText(wrapper, 'Set separately').trigger('click')
    await flushPromises()
    saveAndGet.mockClear()

    // The second slider is the outbound one.
    await sliders(wrapper)[1].vm.$emit('change', 25)
    await flushPromises()

    expect(saveAndGet).toHaveBeenCalledTimes(1)
    const saved = saveAndGet.mock.calls[0][0].settings
    expect(saved.myPostsMaxMinutes).toBe(25)
    expect(saved.browseMaxMinutes).toBe(20)
    expect(saved.browseMaxDistance).toBe(12)
  })

  // While the outbound axis is unset it follows the inbound one, because that IS what linked
  // means. But after asking for the two to be set separately, seeing the second slider move when
  // you drag the first reads as the control being broken - so the first drag in split mode pins
  // the outbound where it sat.
  it('pins the outbound value when the inbound slider is dragged while split', async () => {
    const wrapper = mountWith({ browseMaxMinutes: 20, browseMaxDistance: 12 })
    await flushPromises()
    await buttonWithText(wrapper, 'Set separately').trigger('click')
    await flushPromises()
    saveAndGet.mockClear()

    await sliders(wrapper)[0].vm.$emit('change', 10)
    await flushPromises()

    // Two saves, in this order: the outbound pinned at the value it was showing (20 - what it
    // inherited while linked), then the inbound's own new value. Pinning must come FIRST, or it
    // would read 10 - the value it had already followed to - and the pin would be a no-op.
    expect(saveAndGet).toHaveBeenCalledTimes(2)
    expect(saveAndGet.mock.calls[0][0].settings.myPostsMaxMinutes).toBe(20)
    expect(saveAndGet.mock.calls[1][0].settings.browseMaxMinutes).toBe(10)
  })

  it('does not re-pin once the outbound holds its own choice', async () => {
    const wrapper = mountWith({
      browseMaxMinutes: 20,
      browseMaxDistance: 12,
      myPostsMaxMinutes: 35,
      myPostsMaxDistance: 21,
    })
    await flushPromises()
    saveAndGet.mockClear()

    await sliders(wrapper)[0].vm.$emit('change', 10)
    await flushPromises()

    // Just the inbound save - the outbound already has a value of its own to keep.
    expect(saveAndGet).toHaveBeenCalledTimes(1)
    expect(saveAndGet.mock.calls[0][0].settings.browseMaxMinutes).toBe(10)
    expect(saveAndGet.mock.calls[0][0].settings.myPostsMaxMinutes).toBe(35)
  })

  it('does not pin while the two are still linked', async () => {
    // One slider on screen: there is no second value to pin, and writing one would split the
    // member's axes apart without them asking.
    const wrapper = mountWith({ browseMaxMinutes: 20, browseMaxDistance: 12 })
    await flushPromises()
    saveAndGet.mockClear()

    await sliders(wrapper)[0].vm.$emit('change', 10)
    await flushPromises()

    expect(saveAndGet).toHaveBeenCalledTimes(1)
    expect(
      saveAndGet.mock.calls[0][0].settings.myPostsMaxMinutes
    ).toBeUndefined()
  })

  it('starts split when the member already holds an outbound choice', async () => {
    const wrapper = mountWith({
      browseMaxMinutes: 20,
      browseMaxDistance: 12,
      myPostsMaxMinutes: 30,
      myPostsMaxDistance: 18,
    })
    await flushPromises()

    expect(sliders(wrapper)).toHaveLength(2)
    expect(wrapper.text()).toContain('Link them again')
  })

  it('nulls both outbound keys when linked again, so the readers fall back', async () => {
    const wrapper = mountWith({
      browseMaxMinutes: 20,
      browseMaxDistance: 12,
      myPostsMaxMinutes: 30,
      myPostsMaxDistance: 18,
    })
    await flushPromises()
    saveAndGet.mockClear()

    await buttonWithText(wrapper, 'Link them again').trigger('click')
    await flushPromises()

    const saved = saveAndGet.mock.calls[0][0].settings
    // null rather than absent. PATCH /session replaces the settings blob wholesale, so these are
    // stored as JSON null, which every outbound reader treats as unset. Omitting the properties
    // would be the fragile choice: under a merge patch (as PatchUser uses) an absent key leaves
    // the OLD value in place.
    expect(saved.myPostsMaxMinutes).toBeNull()
    expect(saved.myPostsMaxDistance).toBeNull()
    expect(saved.browseMaxDistance).toBe(12)
    expect(sliders(wrapper)).toHaveLength(1)
  })

  // Only the inbound half changes what this member is looking at, so only it should make the page
  // refetch. An outbound change alters who sees their posts and nothing on their own screen.
  it('emits persisted for an inbound change but not an outbound one', async () => {
    const wrapper = mountWith({ browseMaxMinutes: 20, browseMaxDistance: 12 })
    await flushPromises()
    await buttonWithText(wrapper, 'Set separately').trigger('click')
    await flushPromises()

    await sliders(wrapper)[0].vm.$emit('change', 15)
    await flushPromises()
    expect(wrapper.emitted('persisted')).toHaveLength(1)

    await sliders(wrapper)[1].vm.$emit('change', 25)
    await flushPromises()
    expect(wrapper.emitted('persisted')).toHaveLength(1)
  })

  // The single linked slider must look exactly as it did before the split - no dead zone - or every
  // member in a band below the ceiling would see the control change without touching anything.
  it('only draws against the shared axis once split', async () => {
    const wrapper = mountWith({ browseMaxMinutes: 20, browseMaxDistance: 12 })
    await flushPromises()

    expect(sliders(wrapper)[0].props('axisMax')).toBeNull()

    await buttonWithText(wrapper, 'Set separately').trigger('click')
    await flushPromises()

    expect(sliders(wrapper)[0].props('axisMax')).toBe(45)
    expect(sliders(wrapper)[1].props('axisMax')).toBe(45)
  })
})
