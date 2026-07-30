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
  // The card awaits fetch(id) and drives the profile/tagline off its resolved value.
  fetch: vi.fn().mockResolvedValue(null),
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
    mockGroupStore.fetch = vi.fn().mockResolvedValue(null)
  })

  function createWrapper(props = {}) {
    return mount(ComposeGroup, {
      props,
      global: {
        stubs: {
          // GroupProfileImage renders the group's profile with an @error fallback to
          // /icon.png; stub it to a plain img so we can assert the src it receives.
          GroupProfileImage: {
            template: '<img :src="image" :alt="altText" />',
            props: ['image', 'size', 'altText'],
          },
        },
      },
    })
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

  it('ignores a pre-set group and derives the origin from the postcode', async () => {
    // Rippling-out: a stale/pre-set group (e.g. carried over from a repost or an earlier
    // compose) must NOT win over the containing-or-closest community for the current postcode.
    mockComposeStore.group = 2
    const wrapper = createWrapper()
    await flushPromises()
    expect(mockComposeStore.group).toBe(1)
    expect(wrapper.find('[data-test="compose-group"]').text()).toContain(
      'London Central'
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

  it('falls back to the cached store name when the nearby entry has none', async () => {
    mockComposeStore.postcode.groupsnear = [
      { id: 1, namedisplay: null, nameshort: null },
    ]
    mockGroupStore.get.mockReturnValue({ namedisplay: 'Cached Community' })
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('[data-test="compose-group"]').text()).toContain(
      'Cached Community'
    )
  })

  it('logs and still derives the group when the postcode refetch fails', async () => {
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    mockApi.location.typeahead.mockRejectedValueOnce(new Error('network'))
    const wrapper = createWrapper()
    await flushPromises()
    // Refetch failed, but the component still locks and shows the derived group.
    expect(wrapper.find('[data-test="compose-group"]').text()).toContain(
      'London Central'
    )
    expect(mockComposeStore.group).toBe(1)
    expect(consoleSpy).toHaveBeenCalled()
    consoleSpy.mockRestore()
  })

  it('renders the finding state and skips the refetch when no postcode is set', async () => {
    mockComposeStore.postcode = null
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.text()).toContain('Finding your local community')
    expect(mockApi.location.typeahead).not.toHaveBeenCalled()
  })

  it('shows the profile picture and tagline from the awaited fetch result', async () => {
    // groupsnear entries are trimmed to name-only, so the card awaits the full group
    // record (fetch) and drives the card off its result - NOT groupStore.get(), whose
    // reactive dependency left the profile stuck on /icon.png for a logged-in member
    // (Discourse #1170 follow-up: EH4 1HY showed the default instead of the group logo).
    mockGroupStore.fetch.mockResolvedValue({
      id: 1,
      namedisplay: 'London Central',
      profile: 'https://example.com/logo.png',
      tagline: 'Reuse in central London',
    })
    const wrapper = createWrapper()
    await flushPromises()
    expect(mockGroupStore.fetch).toHaveBeenCalledWith(1)
    const img = wrapper.find('.compose-group__logo')
    expect(img.attributes('src')).toBe('https://example.com/logo.png')
    expect(wrapper.find('.compose-group__tagline').text()).toBe(
      'Reuse in central London'
    )
  })

  it('uses the default icon and omits the tagline when the fetch has no profile/tagline', async () => {
    // fetch resolves a group without profile/tagline → falls back to /icon.png, no tagline.
    mockGroupStore.fetch.mockResolvedValue({
      id: 1,
      namedisplay: 'London Central',
    })
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('.compose-group__logo').attributes('src')).toBe(
      '/icon.png'
    )
    expect(wrapper.find('.compose-group__tagline').exists()).toBe(false)
  })
})
