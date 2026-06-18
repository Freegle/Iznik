import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ComposeGroup from '~/components/ComposeGroup.vue'

const mockComposeStore = {
  postcode: {
    name: 'SW1A 1AA',
    groupsnear: [
      { id: 1, namedisplay: 'London Central', nameshort: 'london-central' },
      { id: 2, namedisplay: 'Westminster', nameshort: 'westminster' },
    ],
  },
  group: null,
  setPostcode: vi.fn(),
}

const mockApi = {
  location: {
    typeahead: vi.fn().mockResolvedValue([
      {
        name: 'SW1A 1AA',
        groupsnear: [
          { id: 1, namedisplay: 'London Central', nameshort: 'london-central' },
        ],
      },
    ]),
  },
}

const mockGroupStore = {
  get: vi.fn().mockReturnValue(null),
}

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => mockComposeStore,
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => mockGroupStore,
}))

vi.mock('~/api', () => ({
  default: () => mockApi,
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: {} }),
}))

// Rippling-out (#10): the composer no longer lets the user PICK a group. The origin group is
// derived from their postcode/location (containing-or-closest community) and shown read-only.
describe('ComposeGroup', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockComposeStore.postcode = {
      name: 'SW1A 1AA',
      groupsnear: [
        { id: 1, namedisplay: 'London Central', nameshort: 'london-central' },
        { id: 2, namedisplay: 'Westminster', nameshort: 'westminster' },
      ],
    }
    mockComposeStore.group = null
    mockComposeStore.setPostcode = vi.fn()
    mockGroupStore.get.mockReturnValue(null)
  })

  function createWrapper(props = {}) {
    return mount(ComposeGroup, { props })
  }

  it('shows the derived origin community read-only, with no picker', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('[data-test="compose-group"]').text()).toContain(
      'London Central'
    )
    // The user cannot choose a different group: there is no select control.
    expect(wrapper.find('select').exists()).toBe(false)
  })

  it('locks the group to the first (nearest/containing) community when none is set', async () => {
    createWrapper()
    await flushPromises()
    expect(mockComposeStore.group).toBe(1)
  })

  it('preserves a pre-set group (e.g. a repost) instead of overwriting it', async () => {
    mockComposeStore.group = 2
    const wrapper = createWrapper()
    await flushPromises()
    expect(mockComposeStore.group).toBe(2)
    expect(wrapper.find('[data-test="compose-group"]').text()).toContain(
      'Westminster'
    )
  })

  it('falls back to nameshort when namedisplay is null', async () => {
    mockComposeStore.postcode.groupsnear = [
      { id: 1, namedisplay: null, nameshort: 'london-central' },
    ]
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('[data-test="compose-group"]').text()).toContain(
      'london-central'
    )
  })

  it('shows a finding message when no community is known yet', async () => {
    mockComposeStore.postcode = { name: 'SW1A 1AA', groupsnear: [] }
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.text()).toContain('Finding your local community')
  })
})
