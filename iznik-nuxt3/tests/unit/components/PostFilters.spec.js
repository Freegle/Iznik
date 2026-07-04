import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import PostFilters from '~/components/PostFilters.vue'

const mockMiscStore = {
  set: vi.fn(),
}

const mockMessageStore = {
  fetchCount: vi.fn(),
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
const { mockNearbyMessageList, mockWhichPostsShow } = vi.hoisted(() => {
  const { ref: hoistedRef } = require('vue')
  return {
    mockNearbyMessageList: hoistedRef([]),
    mockWhichPostsShow: vi.fn(),
  }
})

// PostFilters.vue's only need from '~/constants' is the distance-slider sentinel -
// mock it explicitly (matching the plain-factory style other spec files use for this
// module) rather than via importOriginal, which does not reliably re-resolve aliased
// modules from inside a vi.mock factory in this project's Vitest setup.
vi.mock('~/constants', () => ({
  BROWSE_DISTANCE_UNLIMITED: Number.MAX_SAFE_INTEGER,
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

    it('scales the slider max to the farthest distance in the feed (rounded up)', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.attributes('max'))).toBe(7) // ceil(6.7)
    })

    it('floors the slider max at 2 when the feed is tiny/empty', () => {
      mockNearbyMessageList.value = []
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.attributes('max'))).toBe(2)
    })

    it('has a minimum of 0.5 miles and a step of 0.5', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.attributes('min'))).toBe(0.5)
      expect(Number(input.attributes('step'))).toBe(0.5)
    })

    it('labels the ends Nearer/Further with no numeric readout', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      expect(wrapper.text()).toContain('Nearer')
      expect(wrapper.text()).toContain('Further')
    })

    it('renders the thumb at the far right by default (unlimited sentinel)', () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.element.value)).toBe(7)
    })

    it('renders the thumb at the real value when a distance limit is already saved', () => {
      mockMe.value = meWithLocation({ browseMaxDistance: 3 })
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      expect(Number(input.element.value)).toBe(3)
    })

    // Discourse 9844: the server pre-filters the feed to the saved browseMaxDistance, so after
    // a narrow distance is saved the farthest loaded post - and hence the feed-derived max -
    // collapses to roughly that distance. Without headroom the thumb (at the saved distance)
    // would pin to the far right, indistinguishable from "Further", while the blue coverage area
    // stayed small. The slider max must keep headroom so a narrow setting still reads as narrow.
    it('keeps headroom above a finite saved distance when the pre-filtered feed collapses', () => {
      // Feed pre-filtered to ~5mi (farthest post 4.8) while the member saved a 5mi limit.
      mockMe.value = meWithLocation({ browseMaxDistance: 5 })
      mockNearbyMessageList.value = [
        { id: 1, distance: 1.1 },
        { id: 2, distance: 4.8 },
      ]
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      const max = Number(input.attributes('max'))
      const value = Number(input.element.value)
      // ceil(5 * 1.25) = 7, so the scale extends past the saved distance...
      expect(max).toBe(7)
      // ...and the thumb sits at the saved 5, NOT pinned to the far right (the bug).
      expect(value).toBe(5)
      expect(value).toBeLessThan(max)
    })

    it('stores BROWSE_DISTANCE_UNLIMITED (not the feed max) when dragged to the rightmost stop', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      input.element.value = 7
      await input.trigger('change')
      await flushPromises()
      expect(mockMe.value.settings.browseMaxDistance).toBe(
        Number.MAX_SAFE_INTEGER
      )
      expect(wrapper.emitted('update:selectedMaxDistance')[0]).toEqual([
        Number.MAX_SAFE_INTEGER,
      ])
    })

    it('stores the real mile value for any position left of max', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      input.element.value = 3
      await input.trigger('change')
      await flushPromises()
      expect(mockMe.value.settings.browseMaxDistance).toBe(3)
      expect(wrapper.emitted('update:selectedMaxDistance')[0]).toEqual([3])
    })

    it('refetches the count after a slider change settles', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      input.element.value = 3
      await input.trigger('change')
      await flushPromises()
      expect(mockMessageStore.fetchCount).toHaveBeenCalled()
    })

    it('does not save on every drag tick - only on change (debounced persistence)', async () => {
      const wrapper = createWrapper({ forceShowFilters: true })
      const input = wrapper.find('.range-slider-stub')
      input.element.value = 3
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
