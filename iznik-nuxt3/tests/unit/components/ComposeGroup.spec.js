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

const mockAuthStore = {
  groups: [{ groupid: 3, namedisplay: 'My Group', nameshort: 'my-group' }],
  fetchUser: vi.fn().mockResolvedValue(undefined),
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

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
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
    mockAuthStore.groups = [
      { groupid: 3, namedisplay: 'My Group', nameshort: 'my-group' },
    ]
    mockGroupStore.get.mockReturnValue(null)
  })

  function createWrapper(props = {}) {
    return mount(ComposeGroup, {
      props,
      global: {
        stubs: {
          'b-form-select': {
            template:
              '<select class="form-select" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.text }}</option></select>',
            props: ['modelValue', 'options'],
            emits: ['update:modelValue'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders form select', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.form-select').exists()).toBe(true)
    })

    it('applies width style when width prop is provided', () => {
      const wrapper = createWrapper({ width: 250 })
      expect(wrapper.find('.form-select').attributes('style')).toContain(
        'width: 250px'
      )
    })
  })

  describe('props', () => {
    it('has optional width prop', () => {
      const wrapper = createWrapper({ width: 200 })
      expect(wrapper.props('width')).toBe(200)
    })

    it('has null default for width', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('width')).toBe(null)
    })
  })

  describe('groupOptions computed', () => {
    it('includes groups from postcode.groupsnear', () => {
      const wrapper = createWrapper()
      const options = wrapper.findAll('option')
      expect(options.some((o) => o.text().includes('London Central'))).toBe(
        true
      )
      expect(options.some((o) => o.text().includes('Westminster'))).toBe(true)
    })

    it('includes member groups', () => {
      const wrapper = createWrapper()
      const options = wrapper.findAll('option')
      expect(options.some((o) => o.text().includes('My Group'))).toBe(true)
    })

    it('uses namedisplay when available', () => {
      const wrapper = createWrapper()
      const options = wrapper.findAll('option')
      expect(options.some((o) => o.text() === 'London Central')).toBe(true)
    })

    it('falls back to nameshort when namedisplay is null', () => {
      mockComposeStore.postcode.groupsnear = [
        { id: 1, namedisplay: null, nameshort: 'london-central' },
      ]
      const wrapper = createWrapper()
      const options = wrapper.findAll('option')
      expect(options.some((o) => o.text() === 'london-central')).toBe(true)
    })

    it('deduplicates groups by id', () => {
      mockAuthStore.groups = [
        { groupid: 1, namedisplay: 'London Central', nameshort: 'london' },
      ]
      const wrapper = createWrapper()
      const options = wrapper.findAll('option')
      const londonOptions = options.filter((o) =>
        o.text().includes('London Central')
      )
      expect(londonOptions).toHaveLength(1)
    })

    it('returns empty array when no postcode or groups', () => {
      mockComposeStore.postcode = null
      mockAuthStore.groups = []
      const wrapper = createWrapper()
      expect(wrapper.findAll('option')).toHaveLength(0)
    })

    it('includes current group as first option when not in groupsnear or myGroups', () => {
      mockComposeStore.group = 55
      mockAuthStore.groups = []
      mockComposeStore.postcode = {
        name: 'SW1A 1AA',
        groupsnear: [
          { id: 1, namedisplay: 'London Central', nameshort: 'london-central' },
        ],
      }
      const wrapper = createWrapper()
      const options = wrapper.findAll('option')
      // Group 55 should be prepended even though it's not in groupsnear or myGroups
      expect(options[0].element.value).toBe('55')
    })

    it('uses cached group name when group store has it', () => {
      mockComposeStore.group = 55
      mockAuthStore.groups = []
      mockComposeStore.postcode = {
        name: 'SW1A 1AA',
        groupsnear: [
          { id: 1, namedisplay: 'London Central', nameshort: 'london-central' },
        ],
      }
      mockGroupStore.get.mockReturnValue({
        namedisplay: 'Repost Group',
        nameshort: 'repost',
      })
      const wrapper = createWrapper()
      const options = wrapper.findAll('option')
      expect(options[0].element.value).toBe('55')
      expect(options[0].text()).toBe('Repost Group')
    })
  })

  describe('group computed (v-model)', () => {
    it('returns selected group from store', () => {
      mockComposeStore.group = 2
      const wrapper = createWrapper()
      expect(wrapper.vm.group).toBe(2)
    })

    it('falls back to first group from postcode when no group selected', () => {
      mockComposeStore.group = null
      const wrapper = createWrapper()
      expect(wrapper.vm.group).toBe(1)
    })

    it('sets group in store when changed', async () => {
      const wrapper = createWrapper()

      await wrapper.find('.form-select').setValue('2')

      expect(mockComposeStore.group).toBe('2')
    })
  })

  describe('lifecycle', () => {
    it('fetches user on mount', async () => {
      createWrapper()
      await flushPromises()
      expect(mockAuthStore.fetchUser).toHaveBeenCalled()
    })

    it('fetches fresh postcode data on mount', async () => {
      createWrapper()
      await flushPromises()
      expect(mockApi.location.typeahead).toHaveBeenCalledWith('SW1A 1AA')
    })

    it('updates postcode store with fresh data after typeahead', async () => {
      createWrapper()
      await flushPromises()
      expect(mockComposeStore.setPostcode).toHaveBeenCalledWith({
        name: 'SW1A 1AA',
        groupsnear: [
          { id: 1, namedisplay: 'London Central', nameshort: 'london-central' },
        ],
      })
    })

    it('auto-selects first group when postcode has groups but no group selected', async () => {
      mockComposeStore.group = null
      createWrapper()
      await flushPromises()
      expect(mockComposeStore.group).toBe(1)
    })

    it('does not fetch when no postcode', async () => {
      mockComposeStore.postcode = null
      createWrapper()
      await flushPromises()
      expect(mockApi.location.typeahead).not.toHaveBeenCalled()
    })
  })

  describe('repost group preservation', () => {
    it('restores pre-set group after fetchUser if it was overridden but is in myGroups', async () => {
      // Simulate repost flow: group 55 is pre-set but not in groupsnear (only group 1 is)
      mockComposeStore.group = 55
      mockAuthStore.groups = [
        { groupid: 55, namedisplay: 'Repost Group', nameshort: 'repost' },
      ]
      // fetchUser resets group to groupsnear[0] (simulates b-form-select override)
      mockAuthStore.fetchUser = vi.fn().mockImplementation(async () => {
        mockComposeStore.group = 1
      })

      createWrapper()
      await flushPromises()

      // The final guard should restore 55 because user is a member
      expect(mockComposeStore.group).toBe(55)
    })

    it('does not restore savedGroup if it is not valid (not in groupsnear or myGroups)', async () => {
      // Group 99 is pre-set but user is no longer a member and it is not nearby
      mockComposeStore.group = 99
      mockAuthStore.groups = []
      mockAuthStore.fetchUser = vi.fn().mockImplementation(async () => {
        mockComposeStore.group = 1
      })

      createWrapper()
      await flushPromises()

      // Group 99 is invalid, so the override (1) should stand
      expect(mockComposeStore.group).toBe(1)
    })
  })

  describe('user group selection after mount', () => {
    it('preserves user selection when group changed after mount but before fetchUser completes', async () => {
      // Simulate repost flow: group 1 is pre-set (original group)
      mockComposeStore.group = 1
      mockAuthStore.groups = [
        { groupid: 1, namedisplay: 'Original Group', nameshort: 'original' },
        { groupid: 2, namedisplay: 'New Group', nameshort: 'new' },
      ]

      // fetchUser will be called but we want to simulate it happening after user changes selection
      let fetchUserCalled = false
      mockAuthStore.fetchUser = vi.fn().mockImplementation(async () => {
        // Simulate that user changed group to 2 while fetch was in progress
        // The group should stay as 2, not be restored to 1
        fetchUserCalled = true
      })

      const wrapper = createWrapper()

      // User changes group selection to 2
      await wrapper.find('.form-select').setValue('2')
      expect(mockComposeStore.group).toBe('2')

      // Wait for lifecycle to complete
      await flushPromises()
      expect(fetchUserCalled).toBe(true)

      // The group should still be 2 (user's selection), not restored to 1
      // Because we only restore if group was cleared (falsy), not if it changed
      expect(mockComposeStore.group).toBe('2')
    })

    it('restores savedGroup if it was cleared (falsy) but is still valid', async () => {
      // Simulate repost flow: group 1 is pre-set
      mockComposeStore.group = 1
      mockAuthStore.groups = [
        { groupid: 1, namedisplay: 'Original Group', nameshort: 'original' },
      ]

      // Simulate b-form-select clearing the group during fetchUser
      mockAuthStore.fetchUser = vi.fn().mockImplementation(async () => {
        mockComposeStore.group = null // Group was cleared by b-form-select
      })

      createWrapper()
      await flushPromises()

      // The final guard should restore 1 because it was cleared (falsy) and is valid
      expect(mockComposeStore.group).toBe(1)
    })
  })

  describe('error handling', () => {
    it('handles postcode fetch error gracefully', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
      mockApi.location.typeahead.mockRejectedValueOnce(
        new Error('Network error')
      )

      createWrapper()
      await flushPromises()

      expect(consoleSpy).toHaveBeenCalled()
      consoleSpy.mockRestore()
    })
  })
})
