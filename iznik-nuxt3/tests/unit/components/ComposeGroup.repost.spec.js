import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ComposeGroup from '~/components/ComposeGroup.vue'

const mockComposeStore = {
  postcode: {
    name: 'RH6 0HN',
    groupsnear: [
      { id: 1, namedisplay: 'Bicester', nameshort: 'bicester' },
      { id: 2, namedisplay: 'Oxford', nameshort: 'oxford' },
    ],
  },
  group: 1,  // Original message group (Bicester)
  setPostcode: vi.fn(),
}

const mockAuthStore = {
  groups: [
    { groupid: 1, namedisplay: 'Bicester', nameshort: 'bicester' },
    { groupid: 2, namedisplay: 'Oxford', nameshort: 'oxford' },
  ],
  fetchUser: vi.fn().mockResolvedValue(undefined),
}

const mockApi = {
  location: {
    typeahead: vi.fn().mockResolvedValue([
      {
        name: 'RH6 0HN',
        groupsnear: [
          { id: 1, namedisplay: 'Bicester', nameshort: 'bicester' },
          { id: 2, namedisplay: 'Oxford', nameshort: 'oxford' },
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

describe('ComposeGroup - Repost Group Change Bug', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockComposeStore.postcode = {
      name: 'RH6 0HN',
      groupsnear: [
        { id: 1, namedisplay: 'Bicester', nameshort: 'bicester' },
        { id: 2, namedisplay: 'Oxford', nameshort: 'oxford' },
      ],
    }
    mockComposeStore.group = 1  // Start with Bicester (original group)
    mockComposeStore.setPostcode = vi.fn()
    mockAuthStore.groups = [
      { groupid: 1, namedisplay: 'Bicester', nameshort: 'bicester' },
      { groupid: 2, namedisplay: 'Oxford', nameshort: 'oxford' },
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

  it('REGRESSION: user changes group during repost, change should persist after async operations', async () => {
    // Scenario: User is reposting with group 1 (Bicester) pre-selected
    expect(mockComposeStore.group).toBe(1)

    const wrapper = createWrapper()

    // Get the select element
    const selectEl = wrapper.find('.form-select')
    expect(selectEl.exists()).toBe(true)

    // Verify initial group is 1 (Bicester)
    expect(selectEl.element.value).toBe('1')

    // User changes the group to 2 (Oxford)
    await selectEl.setValue('2')
    expect(mockComposeStore.group).toBe('2')
    console.log('User changed group to Oxford: mockComposeStore.group =', mockComposeStore.group)

    // Now let the component's onMounted hook run
    // It should preserve the group change the user just made
    await flushPromises()

    // THE BUG: After async operations, the group reverts to 1 (Bicester)
    // EXPECTED: Group should still be 2 (Oxford) because user selected it
    // ACTUAL: Group is reverted to 1 (Bicester)
    console.log('After async operations: mockComposeStore.group =', mockComposeStore.group)
    expect(mockComposeStore.group).toBe('2')
  })

  it('group pre-selection is preserved when component mounts with pre-set group', async () => {
    // Scenario: group is pre-selected before mount (from repost)
    mockComposeStore.group = 1
    const wrapper = createWrapper()
    await flushPromises()

    // Group should still be 1
    expect(mockComposeStore.group).toBe(1)
  })

  it('does not restore savedGroup if currentGroup is valid (user intentionally changed it)', async () => {
    // Scenario: User is reposting with group 1 pre-selected
    // They change it to group 2, then the component lifecycle completes
    // Group 2 is valid (in groupsnear), so the final guard should NOT restore to 1

    mockComposeStore.group = 1  // Pre-selected group
    mockAuthStore.fetchUser = vi.fn().mockImplementation(async () => {
      // Simulate something that might trigger reactive update
      // But don't reset the group ourselves - that's not what the code does
    })

    const wrapper = createWrapper()

    // Simulate user changing group
    const selectEl = wrapper.find('.form-select')
    await selectEl.setValue('2')
    expect(mockComposeStore.group).toBe('2')

    // Let component lifecycle complete
    await flushPromises()

    // Group should STILL be 2 because it's valid in groupsnear
    // The final guard should NOT restore to savedGroup (1) because 2 is valid
    expect(mockComposeStore.group).toBe('2')
  })

  it('restores savedGroup only if currentGroup becomes invalid', async () => {
    // Scenario: If somehow the current group becomes invalid (not in groupsnear or myGroups),
    // then the final guard should restore the savedGroup

    mockComposeStore.group = 1  // Pre-selected group
    mockComposeStore.postcode.groupsnear = [
      { id: 1, namedisplay: 'Bicester', nameshort: 'bicester' },
      { id: 2, namedisplay: 'Oxford', nameshort: 'oxford' },
    ]

    // Simulate component mount with fetchUser that somehow resets to invalid group
    mockAuthStore.fetchUser = vi.fn().mockImplementation(async () => {
      // Simulate b-form-select resetting to a group not in groupsnear/myGroups
      mockComposeStore.group = 99  // Invalid group
    })

    createWrapper()
    await flushPromises()

    // The final guard should restore to savedGroup (1) because 99 is invalid
    expect(mockComposeStore.group).toBe(1)
  })
})
