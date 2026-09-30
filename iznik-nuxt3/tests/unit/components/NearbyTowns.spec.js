import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import NearbyTowns from '~/components/NearbyTowns.vue'

const mockMe = ref({ lat: 51.5, lng: -0.1 })

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

const { mockFetchNear } = vi.hoisted(() => ({
  mockFetchNear: vi.fn(),
}))
vi.mock('~/api', () => ({
  default: () => ({ town: { fetchNear: mockFetchNear } }),
}))

function mountVisible(minutes = 15) {
  return mount(NearbyTowns, {
    props: { minutes },
    global: {
      directives: {
        // Simulate the component having scrolled into view immediately, so fetchTowns fires
        // without depending on a real IntersectionObserver.
        'observe-visibility': {
          mounted(el, binding) {
            if (typeof binding.value === 'function') binding.value(true)
          },
        },
      },
    },
  })
}

describe('NearbyTowns', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.value = { lat: 51.5, lng: -0.1 }
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  // Nothing is within reach, so the API falls back to naming the single nearest town
  // (rendered as "Nearest town: X" from the server's closer_than fallback field) - the only
  // content in that message, unlike the safe-to-truncate "e.g. Town, Town" examples list.
  it('does not ellipsis-truncate the single nearest-town name (Discourse 9808)', async () => {
    mockFetchNear.mockResolvedValue({
      towns: [],
      closer_than: 'Barrow-in-Furness',
      frontier_median_miles: null,
      frontier_max_miles: null,
    })
    const wrapper = mountVisible()
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()

    const tail = wrapper.find('.nt-tail')
    expect(tail.text()).toBe('Nearest town: Barrow-in-Furness')
    // A CSS class that keeps this specific message from being ellipsis-clipped at mobile
    // widths - clipping it would hide the one piece of information ("Barrow-in-Furness")
    // the message exists to convey.
    expect(tail.classes()).toContain('nt-tail--wrap')
  })

  // "Max 1-2 miles by road. Close to Northampton" read as reaching Northampton, 12 miles away.
  // The nearest town now carries its own distance.
  it('gives the nearest town its distance, so it cannot read as the reach', async () => {
    mockFetchNear.mockResolvedValue({
      towns: [],
      closer_than: 'Northampton',
      closer_miles: 11.6,
      frontier_median_miles: 1.4,
      frontier_max_miles: 1.9,
    })
    const wrapper = mountVisible(5)
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()

    expect(wrapper.find('.nt-lead').text()).toBe('Up to about 1 mile by road')
    expect(wrapper.find('.nt-tail').text()).toBe(
      'Nearest town: Northampton, 12 miles by road'
    )
  })

  // "Max 16-19 miles" put a range after "Max". One figure, the typical reach.
  it('shows the reach as one figure, the typical distance', async () => {
    mockFetchNear.mockResolvedValue({
      towns: ['Northampton'],
      closer_than: '',
      frontier_median_miles: 17.7,
      frontier_max_miles: 20.1,
    })
    const wrapper = mountVisible(30)
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()

    expect(wrapper.text()).toContain(
      'Up to about 18 miles by road, e.g. Northampton'
    )
    expect(wrapper.text()).not.toContain('20')
  })

  it('words the outbound reach as how far posts are seen', async () => {
    mockFetchNear.mockResolvedValue({
      towns: ['Kettering'],
      closer_than: '',
      frontier_median_miles: 10.2,
      frontier_max_miles: 11.5,
    })
    const wrapper = mount(NearbyTowns, {
      props: { minutes: 20, perspective: 'outbound' },
      global: {
        directives: {
          'observe-visibility': {
            mounted(el, binding) {
              if (typeof binding.value === 'function') binding.value(true)
            },
          },
        },
      },
    })
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()

    expect(wrapper.find('.nt-lead').text()).toBe(
      'Seen up to about 10 miles away by road'
    )
  })

  it('still ellipsis-truncates the safe-to-clip "e.g. Town, Town" examples list', async () => {
    mockFetchNear.mockResolvedValue({
      towns: ['Preston', 'Lancaster', 'Blackpool'],
      closer_than: '',
      frontier_median_miles: 5,
      frontier_max_miles: 8,
    })
    const wrapper = mountVisible()
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()

    const tail = wrapper.find('.nt-tail')
    expect(tail.text()).toBe('e.g. Preston, Lancaster, Blackpool')
    expect(tail.classes()).not.toContain('nt-tail--wrap')
  })

  it('applies .nearby-towns--wrap to the container so the wrapped name is not vertically clipped at >=768px (Discourse 9808)', async () => {
    mockFetchNear.mockResolvedValue({
      towns: [],
      closer_than: 'Sutton-under-Whitestonecliffe',
      frontier_median_miles: null,
      frontier_max_miles: null,
    })
    const wrapper = mountVisible()
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()

    // The tail wraps (nt-tail--wrap); the container must also drop its fixed
    // single-line height at >=768px (nearby-towns--wrap) or the wrapped 2nd line
    // is clipped. happy-dom has no layout engine, so this only pins the class
    // wiring; the visual no-clip at >=768px is covered by the e2e test.
    expect(wrapper.find('.nt-tail').classes()).toContain('nt-tail--wrap')
    expect(wrapper.find('.nearby-towns').classes()).toContain(
      'nearby-towns--wrap'
    )
  })

  it('does not apply .nearby-towns--wrap when towns are within reach (no-wrap case)', async () => {
    mockFetchNear.mockResolvedValue({
      towns: ['Preston', 'Lancaster'],
      closer_than: '',
      frontier_median_miles: 5,
      frontier_max_miles: 8,
    })
    const wrapper = mountVisible()
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()

    expect(wrapper.find('.nearby-towns').classes()).not.toContain(
      'nearby-towns--wrap'
    )
  })
})
