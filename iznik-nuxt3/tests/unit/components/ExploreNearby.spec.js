import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import ExploreNearby from '~/components/ExploreNearby.vue'
import PlaceAutocomplete from '~/components/PlaceAutocomplete.vue'
import MapGroup from '~/components/MapGroup.vue'

// Mutable fixtures, set per-test in beforeEach.
let mockSummaryList
let mockMe

const mockFetch = vi.fn()
const mockOneOfMyGroups = vi.fn()

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    fetch: mockFetch,
    get summaryList() {
      return mockSummaryList
    },
  }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: {
      get value() {
        return mockMe
      },
    },
    oneOfMyGroups: mockOneOfMyGroups,
  }),
}))

const MANCHESTER = { name: 'Manchester', lat: 53.4808, lng: -2.2426 }

const manchesterGroup = {
  id: 1,
  namedisplay: 'Manchester Freegle',
  region: 'North West',
  lat: 53.4808,
  lng: -2.2426,
  onmap: 1,
  publish: 1,
}
const stockportGroup = {
  id: 2,
  namedisplay: 'Stockport Freegle',
  region: 'North West',
  lat: 53.4106,
  lng: -2.1575,
  onmap: 1,
  publish: 1,
}
const londonGroup = {
  id: 3,
  namedisplay: 'London Freegle',
  region: 'London',
  lat: 51.5074,
  lng: -0.1278,
  onmap: 1,
  publish: 1,
}

function createWrapper() {
  return shallowMount(ExploreNearby, {
    global: {
      stubs: {
        // Render slot content so we can assert on the empty-state message.
        NoticeMessage: {
          template: '<div class="notice-message"><slot /></div>',
          props: ['variant'],
        },
        ExternalLink: {
          template: '<a class="external-link"><slot /></a>',
          props: ['href'],
        },
        'nuxt-link': {
          template: '<a :href="to"><slot /></a>',
          props: ['to', 'noPrefetch'],
        },
        'b-button': {
          template: '<button :to="to"><slot /></button>',
          props: ['variant', 'to'],
        },
      },
    },
  })
}

describe('ExploreNearby', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockSummaryList = {
      1: manchesterGroup,
      2: stockportGroup,
      3: londonGroup,
    }
    mockMe = null
    mockOneOfMyGroups.mockReturnValue(false)
  })

  it('renders the place search box', () => {
    const wrapper = createWrapper()
    expect(wrapper.findComponent(PlaceAutocomplete).exists()).toBe(true)
  })

  it('fetches the list of groups on setup', () => {
    createWrapper()
    expect(mockFetch).toHaveBeenCalled()
  })

  it('shows no community list before a place is chosen', () => {
    const wrapper = createWrapper()
    expect(wrapper.findAllComponents(MapGroup)).toHaveLength(0)
  })

  it('lists nearby communities, nearest first, after a place is selected', async () => {
    const wrapper = createWrapper()
    wrapper.findComponent(PlaceAutocomplete).vm.$emit('selected', MANCHESTER)
    await flushPromises()

    const groups = wrapper.findAllComponents(MapGroup)
    expect(groups).toHaveLength(3)
    expect(groups.map((g) => g.props('id'))).toEqual([1, 2, 3])
  })

  it('shows the empty state when no community is near the place', async () => {
    // Groups that only accept joins within 20 miles...
    mockSummaryList = {
      1: { ...manchesterGroup, showjoin: 20 },
      2: { ...stockportGroup, showjoin: 20 },
      3: { ...londonGroup, showjoin: 20 },
    }
    const wrapper = createWrapper()
    // ...and a place (Penzance) far from all of them.
    wrapper
      .findComponent(PlaceAutocomplete)
      .vm.$emit('selected', { name: 'Penzance', lat: 50.118, lng: -5.537 })
    await flushPromises()

    expect(wrapper.findAllComponents(MapGroup)).toHaveLength(0)
    expect(wrapper.text()).toContain("couldn't find a Freegle community")
  })

  it('excludes communities the user already belongs to', async () => {
    mockOneOfMyGroups.mockImplementation((id) => id === 1)
    const wrapper = createWrapper()
    wrapper.findComponent(PlaceAutocomplete).vm.$emit('selected', MANCHESTER)
    await flushPromises()

    const ids = wrapper.findAllComponents(MapGroup).map((g) => g.props('id'))
    expect(ids).toEqual([2, 3])
  })

  it('clears the list when the search box is cleared', async () => {
    const wrapper = createWrapper()
    wrapper.findComponent(PlaceAutocomplete).vm.$emit('selected', MANCHESTER)
    await flushPromises()
    expect(wrapper.findAllComponents(MapGroup).length).toBeGreaterThan(0)

    wrapper.findComponent(PlaceAutocomplete).vm.$emit('cleared')
    await flushPromises()
    expect(wrapper.findAllComponents(MapGroup)).toHaveLength(0)
  })

  it('shows nearby communities immediately when the user has a saved location', async () => {
    mockMe = {
      lat: 53.4808,
      lng: -2.2426,
      settings: { mylocation: { name: 'Manchester' } },
    }
    const wrapper = createWrapper()
    await flushPromises()

    expect(wrapper.findAllComponents(MapGroup).length).toBeGreaterThan(0)
  })

  it('offers region browse buttons', () => {
    const wrapper = createWrapper()
    const buttons = wrapper.findAll('button')
    const labels = buttons.map((b) => b.text())
    expect(labels).toContain('London')
    expect(labels).toContain('North West')
  })
})
