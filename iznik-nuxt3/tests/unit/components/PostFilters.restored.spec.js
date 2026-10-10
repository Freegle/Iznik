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

    // The slider is a TRAVEL-TIME range in MINUTES, not a miles scale tied to the feed - so the
    // "Up to about N miles by road" reach hint stays stable instead of jumping as the feed reloads
    // (Discourse 9808). Its top is the member's own density-sized reach cap; until the server
    // answers, the flat cap applies.

    // The sentinel defers to the server's own reach, which grows every post to the
    // widest band's budget - so it only means "as far as I would go" for a member whose
    // own band earns that ceiling. A sparse member at their top stop is exactly that case.

    // For any position left of max, the chosen MINUTES are stored (so the slider restores) and the
    // routing-derived crow-flies mile radius is stored as browseMaxDistance for the fast feed filter.

  })

  // "Show posts from" used to forget a single community on reload: only the two whole-feed
  // views were stored, so the dropdown sprang back to Nearby every visit (Discourse 10096).
})
