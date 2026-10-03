import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModModeration from '~/modtools/components/ModModeration.vue'

// Mock stores
const mockUserStore = {
  edit: vi.fn(),
  byId: vi.fn(),
  fetch: vi.fn().mockResolvedValue({}),
}

const mockMemberStore = {
  setPostingStatus: vi.fn().mockResolvedValue(),
}

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/modtools/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

describe('ModModeration', () => {
  const createUser = (overrides = {}) => ({
    id: 456,
    displayname: 'Test User',
    trustlevel: null,
    postingstatus: 'MODERATED',
    ...overrides,
  })

  function mountComponent(props = {}) {
    const { _userData, ...componentProps } = props
    const userid = componentProps.userid || 456
    const userData = _userData || createUser({ id: userid })

    mockUserStore.byId.mockImplementation((id) => {
      if (id === userid) return userData
      return null
    })

    return mount(ModModeration, {
      props: {
        userid,
        ...componentProps,
      },
      global: {
        stubs: {
          'b-form-select': {
            template: `
              <select :value="modelValue" @change="$emit('update:modelValue', $event.target.value)">
                <template v-if="options">
                  <option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.text }}</option>
                </template>
                <slot />
              </select>
            `,
            props: ['modelValue', 'options', 'size', 'readonly'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockUserStore.edit.mockResolvedValue({})
    mockUserStore.byId.mockReturnValue(createUser())
    mockUserStore.fetch.mockResolvedValue({})
    mockMemberStore.setPostingStatus.mockResolvedValue()
  })

  describe('rendering', () => {
    it('renders two select elements', () => {
      const wrapper = mountComponent()
      const selects = wrapper.findAll('select')
      expect(selects.length).toBe(2)
    })

    it('renders posting status options', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Moderated')
      expect(wrapper.text()).toContain('Default')
      expect(wrapper.text()).toContain('Unmoderated')
      expect(wrapper.text()).toContain("Can't Post")
    })

    it('renders trust level options', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Volunteering - not asked')
      expect(wrapper.text()).toContain('Volunteering - basic')
      expect(wrapper.text()).toContain('Volunteering - moderate')
      expect(wrapper.text()).toContain('Volunteering - advanced')
      expect(wrapper.text()).toContain('Volunteering - declined')
      expect(wrapper.text()).toContain('Volunteering - disabled')
    })
  })

  describe('postingStatus computed', () => {
    it('returns postingstatus from the national user record', () => {
      const wrapper = mountComponent({
        _userData: createUser({ postingstatus: 'DEFAULT' }),
      })
      expect(wrapper.vm.postingStatus).toBe('DEFAULT')
    })

    it('falls back to MODERATED when postingstatus is null', () => {
      const wrapper = mountComponent({
        _userData: createUser({ postingstatus: null }),
      })
      expect(wrapper.vm.postingStatus).toBe('MODERATED')
    })

    it('calls memberStore.setPostingStatus when setting postingStatus', async () => {
      const wrapper = mountComponent({
        userid: 111,
        _userData: createUser({ id: 111 }),
      })

      // Directly set the computed property to test the setter
      wrapper.vm.postingStatus = 'PROHIBITED'
      await flushPromises()

      expect(mockMemberStore.setPostingStatus).toHaveBeenCalledWith(
        111,
        'PROHIBITED'
      )
    })

    it('uses userid prop for setPostingStatus calls', async () => {
      const wrapper = mountComponent({
        userid: 999,
        _userData: createUser({ id: 999 }),
      })

      // Directly set the computed property to test the setter
      wrapper.vm.postingStatus = 'DEFAULT'
      await flushPromises()

      expect(mockMemberStore.setPostingStatus).toHaveBeenCalledWith(
        999,
        'DEFAULT'
      )
    })
  })

  describe('trustlevel computed', () => {
    it('returns trustlevel from user', () => {
      const wrapper = mountComponent({
        _userData: createUser({ trustlevel: 'Basic' }),
      })
      expect(wrapper.vm.trustlevel).toBe('Basic')
    })

    it('returns null when trustlevel is not set', () => {
      const wrapper = mountComponent({
        _userData: createUser({ trustlevel: null }),
      })
      expect(wrapper.vm.trustlevel).toBeNull()
    })

    it('calls userStore.edit when setting trustlevel', async () => {
      const wrapper = mountComponent({
        userid: 111,
        _userData: createUser({ id: 111, trustlevel: null }),
      })

      // Directly set the computed property to test the setter
      wrapper.vm.trustlevel = 'Advanced'
      await flushPromises()

      expect(mockUserStore.edit).toHaveBeenCalledWith({
        id: 111,
        trustlevel: 'Advanced',
      })
    })

    it('uses userid prop for trustlevel edit', async () => {
      const wrapper = mountComponent({
        userid: 888,
        _userData: createUser({ id: 888 }),
      })

      // Directly set the computed property to test the setter
      wrapper.vm.trustlevel = 'Moderate'
      await flushPromises()

      expect(mockUserStore.edit).toHaveBeenCalledWith({
        id: 888,
        trustlevel: 'Moderate',
      })
    })
  })

  describe('options computed', () => {
    it('returns correct posting status options', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.options).toEqual([
        { value: 'MODERATED', text: 'Moderated' },
        { value: 'DEFAULT', text: 'Default' },
        { value: 'UNMODERATED', text: 'Unmoderated' },
        { value: 'PROHIBITED', text: "Can't Post" },
      ])
    })
  })

  describe('props', () => {
    it('accepts userid prop', () => {
      const wrapper = mountComponent({ userid: 999 })
      expect(wrapper.props('userid')).toBe(999)
    })

    it('has no membership prop any more', () => {
      const wrapper = mountComponent()
      expect(wrapper.props('membership')).toBeUndefined()
    })

    it('defaults size to lg', () => {
      const wrapper = mountComponent()
      expect(wrapper.props('size')).toBe('lg')
    })
  })

  describe('trust level values', () => {
    it('handles Basic trust level', () => {
      const wrapper = mountComponent({
        _userData: createUser({ trustlevel: 'Basic' }),
      })
      expect(wrapper.vm.trustlevel).toBe('Basic')
    })

    it('handles Moderate trust level', () => {
      const wrapper = mountComponent({
        _userData: createUser({ trustlevel: 'Moderate' }),
      })
      expect(wrapper.vm.trustlevel).toBe('Moderate')
    })

    it('handles Advanced trust level', () => {
      const wrapper = mountComponent({
        _userData: createUser({ trustlevel: 'Advanced' }),
      })
      expect(wrapper.vm.trustlevel).toBe('Advanced')
    })

    it('handles Declined trust level', () => {
      const wrapper = mountComponent({
        _userData: createUser({ trustlevel: 'Declined' }),
      })
      expect(wrapper.vm.trustlevel).toBe('Declined')
    })

    it('handles Excluded trust level', () => {
      const wrapper = mountComponent({
        _userData: createUser({ trustlevel: 'Excluded' }),
      })
      expect(wrapper.vm.trustlevel).toBe('Excluded')
    })
  })

  describe('posting status values', () => {
    it('handles MODERATED status', () => {
      const wrapper = mountComponent({
        _userData: createUser({ postingstatus: 'MODERATED' }),
      })
      expect(wrapper.vm.postingStatus).toBe('MODERATED')
    })

    it('handles DEFAULT status', () => {
      const wrapper = mountComponent({
        _userData: createUser({ postingstatus: 'DEFAULT' }),
      })
      expect(wrapper.vm.postingStatus).toBe('DEFAULT')
    })

    it('handles UNMODERATED status', () => {
      const wrapper = mountComponent({
        _userData: createUser({ postingstatus: 'UNMODERATED' }),
      })
      expect(wrapper.vm.postingStatus).toBe('UNMODERATED')
    })

    it('handles PROHIBITED status', () => {
      const wrapper = mountComponent({
        _userData: createUser({ postingstatus: 'PROHIBITED' }),
      })
      expect(wrapper.vm.postingStatus).toBe('PROHIBITED')
    })
  })

  describe('async setters', () => {
    it('setter is async and awaits setPostingStatus call', async () => {
      let resolveEdit
      mockMemberStore.setPostingStatus.mockReturnValue(
        new Promise((resolve) => {
          resolveEdit = resolve
        })
      )

      const wrapper = mountComponent()

      // Directly set the computed property to test the async setter
      wrapper.vm.postingStatus = 'DEFAULT'

      // setPostingStatus should be called immediately
      expect(mockMemberStore.setPostingStatus).toHaveBeenCalled()

      // Resolve the edit
      resolveEdit({})
      await flushPromises()
    })
  })
})
