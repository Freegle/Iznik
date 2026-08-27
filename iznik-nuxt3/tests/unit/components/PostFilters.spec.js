import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import PostFilters from '~/components/PostFilters.vue'

const mockMiscStore = {
  set: vi.fn(),
}

const mockMessageStore = {
  fetchCount: vi.fn(),
  // The "all my communities" feed the slider scales to on the mygroups view.
  get myGroupsList() {
    return mockMyGroupsList.value
  },
}

const mockAuthStore = {
  saveAndGet: vi.fn().mockResolvedValue({}),
}

const mockMe = ref({
  id: 1,
  settings: {
    browseView: 'nearby',
    browseSort: 'Unseen',
  },
})

const mockMyGroups = ref([{ id: 1, nameshort: 'TestGroup' }])

// Rippling-out relevance ordering + distance slider (#D): the slider's max is scaled
// to the farthest `distance` in the loaded nearby feed. Declared via vi.hoisted so it
// exists before the (hoisted) vi.mock factory below references it.
const { mockNearbyMessageList, mockMyGroupsList, mockWhichPostsShow } =
  vi.hoisted(() => {
    const { ref: hoistedRef } = require('vue')
    return {
      mockNearbyMessageList: hoistedRef([]),
      mockMyGroupsList: hoistedRef([]),
      mockWhichPostsShow: vi.fn(),
    }
  })

// PostFilters.vue + useReachDistance need the distance-slider sentinel and the time-based slider
// bounds from '~/constants' - mock them explicitly (matching the plain-factory style other spec files
// use for this module) rather than via importOriginal, which does not reliably re-resolve aliased
// modules from inside a vi.mock factory in this project's Vitest setup.
vi.mock('~/constants', () => ({
  BROWSE_DISTANCE_UNLIMITED: Number.MAX_SAFE_INTEGER,
  BROWSE_MINUTES_MIN: 5,
  BROWSE_MINUTES_FALLBACK_MAX: 30,
  BROWSE_MINUTES_MAX: 45,
  BROWSE_MINUTES_STEP: 5,
  // The mock replaces the whole module, so the two distance axes have to be spelled out here too -
  // DistanceSliders reads them to tell "linked" from "split".
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

// The time-based slider converts the chosen minutes to a crow-flies mile radius via the routing-backed
// /town/near (api().town.fetchNear). Mock it to a fixed radius so a change stores a known value.
const { mockFetchNear } = vi.hoisted(() => ({
  mockFetchNear: vi.fn().mockResolvedValue({
    reach_radius_miles: 4,
    towns: [],
    frontier_median_miles: 3,
    frontier_max_miles: 5,
  }),
}))
vi.mock('~/api', () => ({
  default: () => ({ town: { fetchNear: mockFetchNear } }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/stores/nearby', () => ({
  useNearbyStore: () => ({
    get messageList() {
      return mockNearbyMessageList.value
    },
  }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myGroups: mockMyGroups,
  }),
}))

vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('vue')
  return {
    ...actual,
    ref: actual.ref,
    watch: actual.watch,
    computed: actual.computed,
  }
})

describe('PostFilters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.value = {
      id: 1,
      settings: {
        browseView: 'nearby',
        browseSort: 'Unseen',
      },
    }
    mockMyGroups.value = [{ id: 1, nameshort: 'TestGroup' }]
    mockNearbyMessageList.value = []
    mockMyGroupsList.value = []
  })

  function createWrapper(props = {}) {
    return mount(PostFilters, {
      props: {
        selectedGroup: 0,
        selectedType: 'All',
        selectedSort: 'Unseen',
        forceShowFilters: false,
        ...props,
      },
      global: {
        stubs: {
          // NearbyTowns fires a routing-backed API call and uses IntersectionObserver; stub it
          // out so these filter tests don't depend on either.
          NearbyTowns: true,
          'b-collapse': {
            template:
              '<div class="b-collapse" :class="{ show: modelValue }"><slot /></div>',
            props: ['modelValue'],
          },
          GroupSelect: {
            template: '<select class="group-select" />',
            props: [
              'modelValue',
              'label',
              'all',
              'allMy',
              'customName',
              'customVal',
            ],
            emits: ['update:modelValue'],
          },
          'b-form-select': {
            template:
              '<select class="b-form-select" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.text }}</option></select>',
            props: ['id', 'modelValue', 'options'],
            emits: ['update:modelValue'],
          },
          'b-form-input': {
            template:
              '<input class="b-form-input" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" @keyup.enter="$emit(\'keyup\')" />',
            props: [
              'modelValue',
              'type',
              'placeholder',
              'autocomplete',
              'size',
            ],
            emits: ['update:modelValue', 'keyup'],
          },
          'b-input-group': {
            template:
              '<div class="b-input-group"><slot /><slot name="append" /></div>',
          },
          'b-button': {
            template:
              '<button class="b-button" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'title'],
            emits: ['click'],
          },
          'v-icon': {
            template: '<span class="v-icon" />',
            props: ['icon'],
          },
          'nuxt-link': {
            template: '<a class="nuxt-link"><slot /></a>',
            props: ['to', 'noPrefetch'],
          },
          'b-badge': {
            template: '<span class="b-badge"><slot /></span>',
            props: ['variant'],
          },
          RangeSlider: {
            template:
              '<div><input type="range" class="range-slider-stub" :min="min" :max="max" :step="step" :value="modelValue" :aria-label="ariaLabel" @input="$emit(\'update:modelValue\', Number($event.target.value))" @change="$emit(\'change\', Number($event.target.value))" /><span>{{ leftLabel }}</span><span>{{ rightLabel }}</span></div>',
            props: [
              'modelValue',
              'min',
              'max',
              'step',
              'leftLabel',
              'rightLabel',
              'variant',
              'ariaLabel',
              'id',
            ],
            emits: ['update:modelValue', 'change'],
          },
          WhichPostsModal: {
            template: '<div class="which-posts-modal-stub" />',
            methods: {
              show: mockWhichPostsShow,
              hide: () => {},
            },
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders component', () => {
      const wrapper = createWrapper()
      expect(wrapper.exists()).toBe(true)
    })

    it('renders search input', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-form-input').exists()).toBe(true)
    })

    it('renders search button', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-button').exists()).toBe(true)
    })

    it('renders filters toggle button when filters hidden', () => {
      const wrapper = createWrapper({ forceShowFilters: false })
      const buttons = wrapper.findAll('.b-button')
      expect(buttons.length).toBeGreaterThan(0)
    })

    it('renders collapse when forceShowFilters is true', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      await flushPromises()
      expect(wrapper.find('.b-collapse').exists()).toBe(true)
    })
  })

  describe('props', () => {
    it('has selectedGroup prop with 0 default', () => {
      const props = PostFilters.props || {}
      expect(props.selectedGroup.default).toBe(0)
    })

    it('has selectedType prop with All default', () => {
      const props = PostFilters.props || {}
      expect(props.selectedType.default).toBe('All')
    })

    it('has selectedSort prop with Unseen default', () => {
      const props = PostFilters.props || {}
      expect(props.selectedSort.default).toBe('Unseen')
    })

    it('has forceShowFilters prop with false default', () => {
      const props = PostFilters.props || {}
      expect(props.forceShowFilters.default).toBe(false)
    })

    it('has selectedMaxDistance prop defaulting to the unlimited sentinel', () => {
      const props = PostFilters.props || {}
      expect(props.selectedMaxDistance.default).toBe(Number.MAX_SAFE_INTEGER)
    })
  })

  describe('emits', () => {
    it('defines update:search emit', () => {
      const emits = PostFilters.emits || []
      expect(emits).toContain('update:search')
    })

    it('defines update:selectedGroup emit', () => {
      const emits = PostFilters.emits || []
      expect(emits).toContain('update:selectedGroup')
    })

    it('defines update:selectedType emit', () => {
      const emits = PostFilters.emits || []
      expect(emits).toContain('update:selectedType')
    })

    it('defines update:selectedSort emit', () => {
      const emits = PostFilters.emits || []
      expect(emits).toContain('update:selectedSort')
    })

    it('defines update:selectedMaxDistance emit', () => {
      const emits = PostFilters.emits || []
      expect(emits).toContain('update:selectedMaxDistance')
    })
  })

  describe('filters expanded state', () => {
    it('filters collapsed by default', () => {
      const wrapper = createWrapper()
      const collapse = wrapper.find('.b-collapse')
      expect(collapse.classes()).not.toContain('show')
    })

    it('filters expanded when forceShowFilters is true', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      await flushPromises()
      const collapse = wrapper.find('.b-collapse')
      expect(collapse.classes()).toContain('show')
    })

    it('toggles filters on button click', async () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('.b-button')
      const toggleBtn = buttons.find((btn) => btn.text().includes('Map'))
      if (toggleBtn) {
        await toggleBtn.trigger('click')
        await flushPromises()
        expect(wrapper.find('.b-collapse').classes()).toContain('show')
      }
    })
  })

  describe('misc store integration', () => {
    it('sets hidepostmap on showFilters change', async () => {
      createWrapper({ forceShowFilters: true })
      await flushPromises()
      expect(mockMiscStore.set).toHaveBeenCalledWith({
        key: 'hidepostmap',
        value: false,
      })
    })
  })

  describe('type options', () => {
    it('has All type option', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.text()).toContain('OFFERs & WANTEDs')
    })

    it('has Offer type option', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.text()).toContain('Just OFFERs')
    })

    it('has Wanted type option', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.text()).toContain('Just WANTEDs')
    })
  })

  describe('sort options', () => {
    it('has the rippling-out sort options: New to you / Newest posted / Closest (#1, #H)', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const text = wrapper.text()
      expect(text).toContain('New to you')
      expect(text).toContain('Newest posted')
      // The "Nearby" sort option's display label was renamed to "Closest" (#H); the
      // internal value stays 'Nearby' so sortMessages still matches it.
      expect(text).toContain('Closest')
      expect(text).not.toContain('Nearby')
      // The legacy labels are gone. The distance slider is a real feature now (#D),
      // tested separately, but it isn't the old "adjust to show posts nearer" wording.
      expect(text).not.toContain('Unseen posts first')
      expect(text).not.toContain('Adjust the slider to show posts from nearer')
    })

    it('keeps the internal value for the Closest option as Nearby', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const selects = wrapper.findAll('.b-form-select')
      const sortSelect = selects[1]
      const closestOption = sortSelect
        .findAll('option')
        .find((o) => o.text() === 'Closest')
      expect(closestOption.attributes('value')).toBe('Nearby')
    })
  })

  describe('group select', () => {
    it('shows GroupSelect when user logged in', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.find('.group-select').exists()).toBe(true)
    })

    it('hides GroupSelect when no user', () => {
      mockMe.value = null
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.find('.group-select').exists()).toBe(false)
    })
  })

  describe('nearby reach text (#1)', () => {
    it('shows the automatic-reach help text in Nearby', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const text = wrapper.text()
      expect(text).toContain('We show posts near you first')
      expect(wrapper.find('.nearby-help').exists()).toBe(true)
      // No known location in this test's mock me, so the distance slider (a real
      // feature, #D) doesn't render here - see the "distance slider" describe block.
      expect(wrapper.find('.range-slider-stub').exists()).toBe(false)
    })

    it('links to change postcode and opens the which-posts modal (#K)', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.text()).toContain('Change postcode')
      expect(wrapper.text()).toContain('How does this work?')

      // "How does this work?" no longer navigates away - it opens a modal (#K)
      // reusing the same explainer the /help page renders for this topic.
      const link = wrapper
        .findAll('a')
        .find((a) => a.text().includes('How does this work?'))
      expect(link.exists()).toBe(true)
      await link.trigger('click')
      expect(mockWhichPostsShow).toHaveBeenCalled()
    })

    it('keeps the "How does this work?" / "Change postcode" links reachable at small (mobile) widths (Discourse 9808)', () => {
      const wrapper = createWrapper({ forceShowFilters: true })

      // Bootstrap's `d-none d-md-block` hides an element below the md breakpoint - the whole
      // paragraph (including the only links that open the rippling explainer and change
      // postcode) carried that class, so mobile users had no way to reach either.
      const helpText = wrapper.find('.help-text')
      expect(helpText.exists()).toBe(true)
      expect(helpText.classes()).not.toContain('d-none')
    })
  })

  describe('search functionality', () => {
    it('renders search placeholder', () => {
      const wrapper = createWrapper()
      const input = wrapper.find('.b-form-input')
      expect(input.attributes('placeholder')).toBe('Search posts')
    })
  })

  describe('labels', () => {
    it('shows type label', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.text()).toContain('Show these posts')
    })

    it('shows sort label', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.text()).toContain('Sort by')
    })

    it('shows group label', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.find('.group-select').exists()).toBe(true)
    })
  })

  describe('distance slider (#D)', () => {
    function meWithLocation(overrides = {}) {
      return {
        id: 1,
        lat: 51.5,
        lng: -0.1,
        settings: {
          browseView: 'nearby',
          browseSort: 'Unseen',
          ...overrides,
        },
      }
    }

    beforeEach(() => {
      mockMe.value = meWithLocation()
      mockNearbyMessageList.value = [
        { id: 1, distance: 1.2 },
        { id: 2, distance: 6.7 },
      ]
    })

    it('renders when browseView is nearby and the viewer has a location', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.find('.range-slider-stub').exists()).toBe(true)
    })

    it('hides the slider when the viewer has no known location', () => {
      mockMe.value = { id: 1, settings: { browseView: 'nearby' } }
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.find('.range-slider-stub').exists()).toBe(false)
    })

    it('shows the slider in the mygroups view when the viewer has a location', () => {
      // The mygroups feed now carries a per-post distance (server-side), so the slider
      // narrows any "Show posts from" view - not just Nearby - as long as we know where
      // the viewer is.
      mockMe.value = meWithLocation({ browseView: 'mygroups' })
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.find('.range-slider-stub').exists()).toBe(true)
    })

    // The slider is a TRAVEL-TIME range in MINUTES, not a miles scale tied to the feed - so the
    // "Max X-Y miles by road" reach hint stays stable instead of jumping as the feed reloads
    // (Discourse 9808). Its top is the member's own density-sized reach cap; until the server
    // answers, the flat cap applies.
    it('starts on the flat travel-time cap with 5-minute steps', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.attributes('min'))).toBe(5)
      expect(Number(input.attributes('max'))).toBe(30)
      expect(Number(input.attributes('step'))).toBe(5)
    })

    it('keeps the fixed range regardless of the loaded feed', () => {
      mockMe.value = meWithLocation({ browseView: 'mygroups' })
      mockNearbyMessageList.value = []
      mockMyGroupsList.value = [
        { id: 1, distance: 2.1 },
        { id: 2, distance: 8.4 },
      ]
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.attributes('max'))).toBe(30)
    })

    it('labels the ends Nearer/Further with no numeric readout', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.text()).toContain('Nearer')
      expect(wrapper.text()).toContain('Further')
    })

    it('renders the thumb at the far right by default (no limit)', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.element.value)).toBe(30) // max minutes = "no limit"
    })

    it('renders the thumb at the saved travel time when one is stored', () => {
      mockMe.value = meWithLocation({ browseMaxMinutes: 10 })
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.element.value)).toBe(10)
    })

    // The sentinel defers to the server's own reach, which grows every post to the
    // widest band's budget - so it only means "as far as I would go" for a member whose
    // own band earns that ceiling. A sparse member at their top stop is exactly that case.
    it('stores BROWSE_DISTANCE_UNLIMITED (and derives no radius) at the top stop when the band is the ceiling', async () => {
      mockFetchNear.mockResolvedValue({
        cap_minutes: 45,
        density_band: 'sparse',
        reach_radius_miles: 4,
        towns: [],
      })
      const wrapper = createWrapper({ forceShowFilters: true })
      await flushPromises()
      mockFetchNear.mockClear()

      const input = wrapper.find('.range-slider-stub')
      input.element.value = 45 // their own max = "no limit"
      await input.trigger('change')
      await flushPromises()

      expect(mockMe.value.settings.browseMaxMinutes).toBe(45)
      expect(mockMe.value.settings.browseMaxDistance).toBe(
        Number.MAX_SAFE_INTEGER
      )
      // The top stop needs no RADIUS - it stores the sentinel and defers to the server's own
      // reach. It does still need the reach SHAPE, or dragging to the top would leave the map
      // shading the narrower travel time the member just dragged away from. So there is exactly
      // one lookup, and it is the one that asks for the polygon.
      expect(mockFetchNear).toHaveBeenCalledTimes(1)
      expect(mockFetchNear).toHaveBeenCalledWith(51.5, -0.1, 45, true)
      const emitted = wrapper.emitted('update:selectedMaxDistance')
      expect(emitted[emitted.length - 1]).toEqual([Number.MAX_SAFE_INTEGER])
    })

    // For any position left of max, the chosen MINUTES are stored (so the slider restores) and the
    // routing-derived crow-flies mile radius is stored as browseMaxDistance for the fast feed filter.
    it('stores the chosen minutes and the routing-derived mile radius left of max', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      input.element.value = 10
      await input.trigger('change')
      await flushPromises()
      // true = also fetch the reach outline: browse draws a map, so it takes the shape from
      // the routing pass this lookup already runs rather than routing it again.
      expect(mockFetchNear).toHaveBeenCalledWith(51.5, -0.1, 10, true)
      expect(mockMe.value.settings.browseMaxMinutes).toBe(10)
      expect(mockMe.value.settings.browseMaxDistance).toBe(4) // mocked reach_radius_miles
      expect(wrapper.emitted('update:selectedMaxDistance')[0]).toEqual([4])
    })

    it('refetches the count after a slider change settles', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      input.element.value = 10
      await input.trigger('change')
      await flushPromises()
      expect(mockMessageStore.fetchCount).toHaveBeenCalled()
    })

    it('does not save on every drag tick - only on change (debounced persistence)', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      input.element.value = 10
      await input.trigger('input')
      await flushPromises()
      expect(mockAuthStore.saveAndGet).not.toHaveBeenCalled()
    })
  })

  describe('filters active badge (#G)', () => {
    it('does not show the badge when everything is at its default', () => {
      const wrapper = createWrapper({ forceShowFilters: false })
      expect(wrapper.find('.filters-active-badge').exists()).toBe(false)
    })

    it('shows the badge when sort is not Unseen', () => {
      mockMe.value = {
        id: 1,
        settings: { browseView: 'nearby', browseSort: 'Newest' },
      }
      const wrapper = createWrapper({ forceShowFilters: false })
      expect(wrapper.find('.filters-active-badge').exists()).toBe(true)
    })

    it('shows the badge when the view is not nearby (a specific group/mygroups)', () => {
      mockMe.value = {
        id: 1,
        settings: { browseView: 'mygroups', browseSort: 'Unseen' },
      }
      const wrapper = createWrapper({ forceShowFilters: false })
      expect(wrapper.find('.filters-active-badge').exists()).toBe(true)
    })

    it('shows the badge when the post type is not All', () => {
      mockMe.value = {
        id: 1,
        settings: {
          browseView: 'nearby',
          browseSort: 'Unseen',
          browseType: 'Offer',
        },
      }
      const wrapper = createWrapper({ forceShowFilters: false })
      expect(wrapper.find('.filters-active-badge').exists()).toBe(true)
    })

    it('shows the badge when a distance limit is set', () => {
      mockMe.value = {
        id: 1,
        settings: {
          browseView: 'nearby',
          browseSort: 'Unseen',
          browseMaxDistance: 3,
        },
      }
      const wrapper = createWrapper({ forceShowFilters: false })
      expect(wrapper.find('.filters-active-badge').exists()).toBe(true)
    })
  })

  describe('post type stickiness (#I)', () => {
    it('initialises the type dropdown from settings.browseType', () => {
      mockMe.value = {
        id: 1,
        settings: {
          browseView: 'nearby',
          browseSort: 'Unseen',
          browseType: 'Offer',
        },
      }
      const wrapper = createWrapper({ forceShowFilters: true })
      const typeSelect = wrapper.findAll('.b-form-select')[0]
      expect(typeSelect.element.value).toBe('Offer')
    })

    it('persists a type change to settings.browseType and emits update:selectedType', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const typeSelect = wrapper.findAll('.b-form-select')[0]
      await typeSelect.setValue('Wanted')
      await flushPromises()
      expect(mockAuthStore.saveAndGet).toHaveBeenCalledWith({
        settings: expect.objectContaining({ browseType: 'Wanted' }),
      })
      expect(wrapper.emitted('update:selectedType')[0]).toEqual(['Wanted'])
    })
  })
})
