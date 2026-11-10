import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMockMemberStore } from '../../mocks/stores'
import ModRole from '~/modtools/components/ModRole.vue'
import { ROLE_MEMBER, ROLE_MODERATOR, ROLE_OWNER } from '~/constants'

// Create mock store instance that can be configured per test
const mockMemberStore = createMockMemberStore()

// Mock the store import - this intercepts the import statement
vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

describe('ModRole', () => {
  const defaultProps = {
    userid: 123,
    groupid: 456,
    role: ROLE_MEMBER,
  }

  function mountModRole(props = {}) {
    return mount(ModRole, {
      props: { ...defaultProps, ...props },
      global: {
        stubs: {
          'b-form-select': {
            template: `
              <select
                :value="modelValue"
                @change="$emit('update:modelValue', $event.target.value); $emit('change', $event.target.value)"
              >
                <slot />
              </select>
            `,
            props: ['modelValue'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('rendering', () => {
    it('renders a select element', () => {
      const wrapper = mountModRole()
      expect(wrapper.find('select').exists()).toBe(true)
    })

    it('has Member, Moderator, and Owner options', () => {
      const wrapper = mountModRole()
      const options = wrapper.findAll('option')
      expect(options).toHaveLength(3)
      expect(options[0].text()).toBe('Member')
      expect(options[1].text()).toBe('Moderator')
      expect(options[2].text()).toBe('Owner')
    })

    it('initializes with the role prop value', async () => {
      const wrapper = mountModRole({ role: ROLE_MODERATOR })
      await wrapper.vm.$nextTick()
      // The component sets therole in mounted()
      expect(wrapper.vm.therole).toBe(ROLE_MODERATOR)
    })
  })

  describe('role changes', () => {
    it('calls memberStore.update when role changes', async () => {
      const wrapper = mountModRole({ role: ROLE_MEMBER })
      await wrapper.vm.$nextTick()

      // Change the select value
      const select = wrapper.find('select')
      await select.setValue(ROLE_MODERATOR)

      // Verify store was called with correct params
      expect(mockMemberStore.update).toHaveBeenCalledWith({
        userid: 123,
        groupid: 456,
        role: ROLE_MODERATOR,
      })
    })

    it('passes correct userid and groupid to store', async () => {
      const wrapper = mountModRole({
        userid: 999,
        groupid: 888,
        role: ROLE_MEMBER,
      })
      await wrapper.vm.$nextTick()

      const select = wrapper.find('select')
      await select.setValue(ROLE_OWNER)

      expect(mockMemberStore.update).toHaveBeenCalledWith({
        userid: 999,
        groupid: 888,
        role: ROLE_OWNER,
      })
    })

    it('updates local state when selection changes', async () => {
      const wrapper = mountModRole({ role: ROLE_MEMBER })
      await wrapper.vm.$nextTick()

      const select = wrapper.find('select')
      await select.setValue(ROLE_OWNER)

      expect(wrapper.vm.therole).toBe(ROLE_OWNER)
    })
  })

  describe('props validation', () => {
    it('accepts required props', () => {
      const wrapper = mountModRole({
        userid: 100,
        groupid: 200,
        role: ROLE_MODERATOR,
      })
      expect(wrapper.props('userid')).toBe(100)
      expect(wrapper.props('groupid')).toBe(200)
      expect(wrapper.props('role')).toBe(ROLE_MODERATOR)
    })
  })
})
