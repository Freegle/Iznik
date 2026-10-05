import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ConfirmModal from '~/components/ConfirmModal.vue'

// Mock useOurModal composable
const mockShow = vi.fn()
const mockHide = vi.fn()
const mockModal = ref({ show: mockShow, hide: mockHide })
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: mockModal,
    show: mockShow,
    hide: mockHide,
  }),
}))

describe('ConfirmModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(ConfirmModal, {
      props,
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="modal" :title="title"><slot /><slot name="footer" /></div>',
            props: ['title'],
          },
          'b-button': {
            template:
              '<button :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'disabled'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('displays default title "Are you sure?"', () => {
      const wrapper = createWrapper()
      const modal = wrapper.find('.modal')
      expect(modal.attributes('title')).toBe('Are you sure?')
    })

    it('displays custom title', () => {
      const wrapper = createWrapper({ title: 'Delete Item' })
      const modal = wrapper.find('.modal')
      expect(modal.attributes('title')).toBe('Delete Item')
    })

    it('displays default message', () => {
      const wrapper = createWrapper()
      expect(wrapper.html()).toContain('Are you sure you want to do this?')
    })

    it('displays custom message', () => {
      const wrapper = createWrapper({
        message: '<p>Custom warning message</p>',
      })
      expect(wrapper.html()).toContain('Custom warning message')
    })

    it('renders Cancel button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Cancel')
    })

    it('renders Confirm button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Confirm')
    })
  })

  // Props tests removed - already covered by rendering tests (lines 44-66)

  describe('confirmDisabled', () => {
    // plans/active/2026-09-27-lockdown-switch.md section 11.6: the lockdown
    // press dialog keeps its Confirm button disabled until the presser types
    // LOCKDOWN. This is a general capability (default false, so every other
    // caller is unaffected) rather than something bolted onto one component.
    it('leaves the Confirm button enabled by default', () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      const confirmBtn = buttons.find((b) => b.text() === 'Confirm')
      expect(confirmBtn.attributes('disabled')).toBeFalsy()
    })

    it('disables the Confirm button when confirmDisabled is true', () => {
      const wrapper = createWrapper({ confirmDisabled: true })
      const buttons = wrapper.findAll('button')
      const confirmBtn = buttons.find((b) => b.text() === 'Confirm')
      // A true boolean attribute renders as disabled="" in the DOM, which is
      // an empty string - falsy in JS even though the attribute is present.
      expect(confirmBtn.attributes('disabled')).toBeDefined()
    })

    it('does not emit confirm when clicked while disabled', async () => {
      const wrapper = createWrapper({ confirmDisabled: true })
      const buttons = wrapper.findAll('button')
      const confirmBtn = buttons.find((b) => b.text() === 'Confirm')
      await confirmBtn.trigger('click')
      expect(wrapper.emitted('confirm')).toBeFalsy()
    })
  })

  describe('confirmLabel', () => {
    // plans/active/2026-09-27-lockdown-switch.md section 11.6 calls the
    // lockdown press dialog's confirm control "the Press button" - so the
    // label needs to be overridable, not hardcoded to "Confirm".
    it('labels the confirm button "Confirm" by default', () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      expect(buttons.some((b) => b.text() === 'Confirm')).toBe(true)
    })

    it('uses a custom confirm label when given', () => {
      const wrapper = createWrapper({ confirmLabel: 'Press' })
      const buttons = wrapper.findAll('button')
      expect(buttons.some((b) => b.text() === 'Press')).toBe(true)
      expect(buttons.some((b) => b.text() === 'Confirm')).toBe(false)
    })
  })

  describe('confirmTestid', () => {
    it('omits data-testid on the confirm button by default', () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      const confirmBtn = buttons.find((b) => b.text() === 'Confirm')
      expect(confirmBtn.attributes('data-testid')).toBeUndefined()
    })

    it('applies a custom data-testid to the confirm button when given', () => {
      const wrapper = createWrapper({ confirmTestid: 'lockdown-confirm-press' })
      const button = wrapper.find('[data-testid="lockdown-confirm-press"]')
      expect(button.exists()).toBe(true)
      expect(button.text()).toBe('Confirm')
    })
  })

  describe('interactions', () => {
    it('calls hide when Cancel button is clicked', async () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      const cancelBtn = buttons.find((b) => b.text() === 'Cancel')
      await cancelBtn.trigger('click')
      expect(mockHide).toHaveBeenCalled()
    })

    it('emits confirm and calls hide when Confirm button is clicked', async () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      const confirmBtn = buttons.find((b) => b.text() === 'Confirm')
      await confirmBtn.trigger('click')
      expect(wrapper.emitted('confirm')).toBeTruthy()
      expect(mockHide).toHaveBeenCalled()
    })

    it('does not emit confirm when Cancel is clicked', async () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      const cancelBtn = buttons.find((b) => b.text() === 'Cancel')
      await cancelBtn.trigger('click')
      expect(wrapper.emitted('confirm')).toBeFalsy()
    })
  })

  describe('exposed methods', () => {
    it('exposes show method', () => {
      const wrapper = createWrapper()
      expect(typeof wrapper.vm.show).toBe('function')
    })

    it('show method calls the modal show function', () => {
      const wrapper = createWrapper()
      wrapper.vm.show()
      expect(mockShow).toHaveBeenCalled()
    })
  })

  describe('slots', () => {
    it('allows custom content via default slot', () => {
      const wrapper = mount(ConfirmModal, {
        slots: {
          default: '<div class="custom-content">Custom slot content</div>',
        },
        global: {
          stubs: {
            'b-modal': {
              template:
                '<div class="modal"><slot /><slot name="footer" /></div>',
            },
            'b-button': {
              template: '<button @click="$emit(\'click\')"><slot /></button>',
            },
          },
        },
      })
      expect(wrapper.find('.custom-content').exists()).toBe(true)
      expect(wrapper.text()).toContain('Custom slot content')
    })
  })
})
