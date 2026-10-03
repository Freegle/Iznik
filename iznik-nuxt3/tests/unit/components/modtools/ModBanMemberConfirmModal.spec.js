import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModBanMemberConfirmModal from '~/modtools/components/ModBanMemberConfirmModal.vue'

const mockHide = vi.fn()
const mockShow = vi.fn()

// Mock with proper Vue ref to avoid template ref warnings
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: mockShow,
    hide: mockHide,
  }),
}))

describe('ModBanMemberConfirmModal', () => {
  const defaultProps = {
    userid: 123,
  }

  function mountComponent(props = {}) {
    return mount(ModBanMemberConfirmModal, {
      props: { ...defaultProps, ...props },
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="modal"><slot name="title" /><slot /><slot name="footer" /></div>',
          },
          'b-form-input': {
            template: '<input type="text" v-model="modelValue" />',
            props: ['modelValue'],
            emits: ['update:modelValue'],
          },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
          },
          NoticeMessage: {
            template: '<div class="notice"><slot /></div>',
            props: ['variant'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('rendering', () => {
    it('displays reason input', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('input[type="text"]').exists()).toBe(true)
    })

    it('has Ban button', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Ban')
    })

    it('displays responsible use notice', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Please be responsible')
    })
  })

  describe('ban method', () => {
    it('emits confirm event with reason when reason is set', async () => {
      const wrapper = mountComponent()
      wrapper.vm.reason = 'Spamming'
      await wrapper.vm.ban()
      expect(wrapper.emitted('confirm')).toBeTruthy()
      expect(wrapper.emitted('confirm')[0]).toEqual(['Spamming'])
    })

    it('calls hide after emitting', async () => {
      const wrapper = mountComponent()
      wrapper.vm.reason = 'Test reason'
      await wrapper.vm.ban()
      expect(mockHide).toHaveBeenCalled()
    })

    it('does not emit when reason is empty', async () => {
      const wrapper = mountComponent()
      wrapper.vm.reason = null
      await wrapper.vm.ban()
      expect(wrapper.emitted('confirm')).toBeFalsy()
    })
  })

  describe('modal functionality', () => {
    it('exposes show and hide from composable', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.show).toBeDefined()
      expect(wrapper.vm.hide).toBeDefined()
    })
  })
})
