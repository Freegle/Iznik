import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { createMockMemberStore, createMockUserStore } from '../../mocks/stores'
import ModAddMemberModal from '~/modtools/components/ModAddMemberModal.vue'

// Create mock store instances
const mockMemberStore = createMockMemberStore()
const mockUserStore = createMockUserStore()

// Mock the store imports
vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

// Mock the modal composable with proper Vue ref to avoid template ref warnings
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: vi.fn(),
    hide: vi.fn(),
  }),
}))

describe('ModAddMemberModal', () => {
  const defaultProps = {
    groupid: 456,
  }

  function mountComponent(props = {}) {
    return mount(ModAddMemberModal, {
      props: { ...defaultProps, ...props },
      global: {
        stubs: {
          'b-modal': {
            template: '<div class="modal"><slot /><slot name="footer" /></div>',
          },
          'b-form-input': {
            template:
              '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
          },
          NoticeMessage: {
            template: '<div class="notice"><slot /></div>',
          },
          'v-icon': true,
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    // Reset mock return values
    mockUserStore.add.mockResolvedValue(123)
  })

  describe('rendering', () => {
    it('renders email input when not added', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('input').exists()).toBe(true)
    })

    it('renders Add button when not added', () => {
      const wrapper = mountComponent()
      const buttons = wrapper.findAll('button')
      const addButton = buttons.find((b) => b.text().includes('Add'))
      expect(addButton).toBeDefined()
    })

    it('Add button is disabled when email is empty', () => {
      const wrapper = mountComponent()
      const buttons = wrapper.findAll('button')
      const addButton = buttons.find((b) => b.text().includes('Add'))
      expect(addButton?.attributes('disabled')).toBeDefined()
    })

    it('Add button is disabled when email is invalid', async () => {
      const wrapper = mountComponent()
      wrapper.vm.email = 'not-an-email'
      await wrapper.vm.$nextTick()
      const buttons = wrapper.findAll('button')
      const addButton = buttons.find((b) => b.text().includes('Add'))
      expect(addButton?.attributes('disabled')).toBeDefined()
    })

    it('Add button is enabled when email is valid', async () => {
      const wrapper = mountComponent()
      wrapper.vm.email = 'valid@example.com'
      await wrapper.vm.$nextTick()
      const buttons = wrapper.findAll('button')
      const addButton = buttons.find((b) => b.text().includes('Add'))
      expect(addButton?.attributes('disabled')).toBeUndefined()
    })

    it('shows added message after successful add', async () => {
      const wrapper = mountComponent()

      // Set email
      wrapper.vm.email = 'test@example.com'
      await wrapper.vm.$nextTick()

      // Call add method
      await wrapper.vm.add()
      await flushPromises()

      // Should show the added ID message
      expect(wrapper.text()).toContain('123')
    })
  })

  describe('add functionality', () => {
    it('calls userStore.add with the email', async () => {
      const wrapper = mountComponent()

      wrapper.vm.email = 'newuser@example.com'
      await wrapper.vm.$nextTick()

      await wrapper.vm.add()
      await flushPromises()

      expect(mockUserStore.add).toHaveBeenCalledWith({
        email: 'newuser@example.com',
      })
    })

    it('calls memberStore.add with userid and groupid after userStore.add succeeds', async () => {
      const wrapper = mountComponent({ groupid: 789 })

      wrapper.vm.email = 'newuser@example.com'
      await wrapper.vm.$nextTick()

      await wrapper.vm.add()
      await flushPromises()

      expect(mockMemberStore.add).toHaveBeenCalledWith({
        userid: 123, // returned from userStore.add
        groupid: 789,
      })
    })

    it('does not call memberStore.add if userStore.add returns falsy', async () => {
      mockUserStore.add.mockResolvedValue(null)

      const wrapper = mountComponent()

      wrapper.vm.email = 'newuser@example.com'
      await wrapper.vm.$nextTick()

      await wrapper.vm.add()
      await flushPromises()

      expect(mockMemberStore.add).not.toHaveBeenCalled()
    })

    it('sets addedId after successful add', async () => {
      const wrapper = mountComponent()

      wrapper.vm.email = 'newuser@example.com'
      await wrapper.vm.$nextTick()

      await wrapper.vm.add()
      await flushPromises()

      expect(wrapper.vm.addedId).toBe(123)
    })

    // Regression: PUT /user previously returned 409 for existing emails even when the caller
    // was an authenticated moderator. The Go fix makes it return 200 + existing id, so
    // userStore.add resolves with the existing id and memberStore.add is still called.
    // See https://discourse.ilovefreegle.org/t/9618/14
    it('calls memberStore.add when userStore.add returns id for an already-registered email', async () => {
      mockUserStore.add.mockResolvedValue(456)

      const wrapper = mountComponent({ groupid: 789 })

      wrapper.vm.email = 'existing@example.com'
      await wrapper.vm.$nextTick()

      await wrapper.vm.add()
      await flushPromises()

      expect(mockUserStore.add).toHaveBeenCalledWith({ email: 'existing@example.com' })
      expect(mockMemberStore.add).toHaveBeenCalledWith({ userid: 456, groupid: 789 })
      expect(wrapper.vm.addedId).toBe(456)
    })
  })

  // Regression: add() had no error handling so any API failure propagated to Nuxt's
  // global error handler and showed the generic "oh dear something went wrong" page.
  // See https://discourse.ilovefreegle.org/t/9628/1
  describe('error handling', () => {
    it('shows an error message and does not throw when userStore.add() rejects', async () => {
      mockUserStore.add.mockRejectedValue(new Error('Network error'))

      const wrapper = mountComponent()

      wrapper.vm.email = 'fail@example.com'
      await wrapper.vm.$nextTick()

      // Must not throw — before the fix this propagated to Nuxt's global error handler
      await expect(wrapper.vm.add()).resolves.toBeUndefined()
      await flushPromises()

      expect(wrapper.vm.addedId).toBeNull()
      expect(wrapper.text()).toMatch(/error|wrong|fail/i)
    })

    it('shows an error message and does not throw when memberStore.add() rejects', async () => {
      mockUserStore.add.mockResolvedValue(123)
      mockMemberStore.add.mockRejectedValue(new Error('Forbidden'))

      const wrapper = mountComponent({ groupid: 456 })

      wrapper.vm.email = 'test@example.com'
      await wrapper.vm.$nextTick()

      // Must not throw — before the fix this propagated to Nuxt's global error handler
      await expect(wrapper.vm.add()).resolves.toBeUndefined()
      await flushPromises()

      expect(wrapper.text()).toMatch(/error|wrong|fail/i)
    })
  })
})
