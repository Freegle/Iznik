import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModSupportLockdownPress from '~/modtools/components/ModSupportLockdownPress.vue'
import ConfirmModal from '~/components/ConfirmModal.vue'
import ModSupportLockdownWhatHappens from '~/modtools/components/ModSupportLockdownWhatHappens.vue'

const mockPatch = vi.fn()

vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: () => ({
    patch: mockPatch,
  }),
}))

// Used only by the "real ConfirmModal" describe block below, so that ConfirmModal's
// internal useOurModal() doesn't try to auto-show against a plain b-modal stub.
const mockModalShow = vi.fn()
const mockModalHide = vi.fn()
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: mockModalShow,
    hide: mockModalHide,
  }),
}))

// plans/active/2026-09-27-lockdown-switch.md section 11.11: the not-yet-
// pressed state of the Support Lockdown tab. The page says what will happen
// before the button. Reason is required (it's what geeks@ and the history
// explain the incident with); the notice is optional free text.
describe('ModSupportLockdownPress', () => {
  function createWrapper() {
    return mount(ModSupportLockdownPress, {
      global: {
        components: { ModSupportLockdownWhatHappens },
        stubs: {
          NoticeMessage: { template: '<div><slot /></div>' },
          'b-form-group': { template: '<div><slot /></div>' },
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          'b-form-select': {
            template:
              '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value || null)"><option v-for="o in options" :key="String(o.value)" :value="o.value">{{ o.text }}</option></select>',
            props: ['modelValue', 'options'],
          },
          'b-form-input': {
            template:
              '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['disabled', 'variant', 'size'],
          },
          'v-icon': { template: '<i />' },
          ConfirmModal: {
            template:
              '<div :data-title="title" :data-confirm-label="confirmLabel" :data-confirm-disabled="String(confirmDisabled)" :data-confirm-testid="confirmTestid"><slot /></div>',
            props: [
              'title',
              'confirmLabel',
              'confirmDisabled',
              'confirmTestid',
            ],
            emits: ['confirm'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('disables the Press button while reason is empty', () => {
    const wrapper = createWrapper()
    const button = wrapper.find('[data-testid="lockdown-press-button"]')
    expect(button.attributes('disabled')).toBeDefined()
  })

  it('enables the Press button once a reason is entered', async () => {
    const wrapper = createWrapper()
    await wrapper
      .find('[data-testid="lockdown-reason"]')
      .setValue('Suspected phishing wave')
    const button = wrapper.find('[data-testid="lockdown-press-button"]')
    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('says what will happen before the Press button, without opening the dialog', () => {
    const wrapper = createWrapper()
    const text = wrapper
      .find('[data-testid="lockdown-press-explanation"]')
      .text()
    expect(text).toContain('Chat between members')
    expect(text).toContain(
      'Messages one member sends another wait. Chat with the volunteers keeps working both ways.'
    )
    expect(text).toContain('Moderators can only use the basic Approve button.')
    expect(text).toContain('Nothing lifts on its own.')
    expect(
      wrapper.find('[data-testid="lockdown-confirm-modal"]').exists()
    ).toBe(false)
  })

  it('presses with the reason and no notice when none is written', async () => {
    mockPatch.mockResolvedValue({})
    const wrapper = createWrapper()
    await wrapper
      .find('[data-testid="lockdown-reason"]')
      .setValue('Suspected phishing wave')
    await wrapper.vm.press()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'press',
      reason: 'Suspected phishing wave',
    })
  })

  it('presses with the written notice, trimmed', async () => {
    mockPatch.mockResolvedValue({})
    const wrapper = createWrapper()
    await wrapper
      .find('[data-testid="lockdown-reason"]')
      .setValue('Suspected phishing wave')
    await wrapper
      .find('[data-testid="lockdown-press-notice"]')
      .setValue('  Messages may be delayed.  ')
    await wrapper.vm.press()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'press',
      reason: 'Suspected phishing wave',
      notice: 'Messages may be delayed.',
    })
  })

  it('offers the earlier wordings as starting text, and No notice clears it', async () => {
    const wrapper = createWrapper()
    const buttons = wrapper.findAll('button')
    await buttons.find((b) => b.text() === 'Spam attack').trigger('click')
    expect(
      wrapper.find('[data-testid="lockdown-press-notice"]').element.value
    ).toContain("We're dealing with a spam attack")

    await wrapper
      .find('[data-testid="lockdown-press-notice-none"]')
      .trigger('click')
    expect(
      wrapper.find('[data-testid="lockdown-press-notice"]').element.value
    ).toBe('')
  })

  it('sends no notice when the notice box is only spaces', async () => {
    mockPatch.mockResolvedValue({})
    const wrapper = createWrapper()
    await wrapper.find('[data-testid="lockdown-reason"]').setValue('Wave')
    await wrapper.find('[data-testid="lockdown-press-notice"]').setValue('   ')
    await wrapper.vm.press()
    expect(mockPatch).toHaveBeenCalledWith({ action: 'press', reason: 'Wave' })
  })

  it('trims the reason before sending', async () => {
    mockPatch.mockResolvedValue({})
    const wrapper = createWrapper()
    await wrapper
      .find('[data-testid="lockdown-reason"]')
      .setValue('  Suspected phishing wave  ')
    await wrapper.vm.press()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'press',
      reason: 'Suspected phishing wave',
    })
  })

  it('emits pressed after a successful press', async () => {
    mockPatch.mockResolvedValue({})
    const wrapper = createWrapper()
    await wrapper
      .find('[data-testid="lockdown-reason"]')
      .setValue('Suspected phishing wave')
    await wrapper.vm.press()
    expect(wrapper.emitted('pressed')).toBeTruthy()
  })

  it('does not emit pressed when the patch fails', async () => {
    mockPatch.mockRejectedValue(new Error('network down'))
    const wrapper = createWrapper()
    await wrapper
      .find('[data-testid="lockdown-reason"]')
      .setValue('Suspected phishing wave')
    await expect(wrapper.vm.press()).rejects.toThrow('network down')
    expect(wrapper.emitted('pressed')).toBeFalsy()
  })

  // ConfirmModal is v-if-gated on the Press button (house pattern - see
  // ModSupportLockdownPress.vue), so every test below has to open it first
  // rather than finding it already mounted.
  async function openConfirmModal(wrapper) {
    await wrapper
      .find('[data-testid="lockdown-reason"]')
      .setValue('Suspected phishing wave')
    await wrapper.find('[data-testid="lockdown-press-button"]').trigger('click')
  }

  // plans/active/2026-09-27-lockdown-switch.md sections 11.6 and 11.11: the
  // confirm dialog carries the same words as the page, and its Press button
  // stays disabled until the presser types LOCKDOWN.
  describe('confirm dialog (section 11.6)', () => {
    it('titles the dialog "Lock down Freegle?" and labels its button "Press"', async () => {
      const wrapper = createWrapper()
      await openConfirmModal(wrapper)
      const modal = wrapper.find('[data-confirm-label]')
      expect(modal.attributes('data-title')).toBe('Lock down Freegle?')
      expect(modal.attributes('data-confirm-label')).toBe('Press')
    })

    it('gives the ConfirmModal the lockdown-confirm-press testid for its Press button', async () => {
      const wrapper = createWrapper()
      await openConfirmModal(wrapper)
      const modal = wrapper.find('[data-confirm-label]')
      expect(modal.attributes('data-confirm-testid')).toBe(
        'lockdown-confirm-press'
      )
    })

    it('shows the same words as the page', async () => {
      const wrapper = createWrapper()
      const onPage = wrapper
        .find(
          '[data-testid="lockdown-press-explanation"] [data-testid="lockdown-what-happens"]'
        )
        .text()
      await openConfirmModal(wrapper)
      const inDialog = wrapper
        .find(
          '[data-testid="lockdown-confirm-modal"] [data-testid="lockdown-what-happens"]'
        )
        .text()
      expect(inDialog).toBe(onPage)
      expect(inDialog).toContain('Email to members')
      expect(inDialog).toContain('geeks@ is emailed now')
    })

    it('keeps the Press button disabled until LOCKDOWN is typed exactly', async () => {
      const wrapper = createWrapper()
      await openConfirmModal(wrapper)
      const modal = () => wrapper.find('[data-confirm-label]')
      expect(modal().attributes('data-confirm-disabled')).toBe('true')

      await wrapper
        .find('[data-testid="lockdown-confirm-input"]')
        .setValue('lockdown')
      expect(modal().attributes('data-confirm-disabled')).toBe('true')

      await wrapper
        .find('[data-testid="lockdown-confirm-input"]')
        .setValue('LOCKDOWN')
      expect(modal().attributes('data-confirm-disabled')).toBe('false')
    })

    it('clears the typed confirmation text after a successful press', async () => {
      mockPatch.mockResolvedValue({})
      const wrapper = createWrapper()
      await openConfirmModal(wrapper)
      await wrapper
        .find('[data-testid="lockdown-confirm-input"]')
        .setValue('LOCKDOWN')
      await wrapper.vm.press()
      expect(
        wrapper.find('[data-testid="lockdown-confirm-input"]').element.value
      ).toBe('')
    })
  })

  // Proves the confirm-testid wiring end to end through the real ConfirmModal
  // component (not the attribute stub above), so a future change to either
  // component can't silently break the data-testid the team relies on.
  describe('confirm-testid wiring through the real ConfirmModal', () => {
    function createWrapperWithRealConfirmModal() {
      return mount(ModSupportLockdownPress, {
        global: {
          components: { ConfirmModal, ModSupportLockdownWhatHappens },
          stubs: {
            NoticeMessage: { template: '<div><slot /></div>' },
            'b-form-group': { template: '<div><slot /></div>' },
            'b-form-textarea': {
              template:
                '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
              props: ['modelValue'],
            },
            'b-form-select': {
              template:
                '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value || null)"><option v-for="o in options" :key="String(o.value)" :value="o.value">{{ o.text }}</option></select>',
              props: ['modelValue', 'options'],
            },
            'b-form-input': {
              template:
                '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
              props: ['modelValue'],
            },
            'b-button': {
              template:
                '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
              props: ['disabled', 'variant', 'size'],
            },
            'v-icon': { template: '<i />' },
            'b-modal': {
              template: '<div><slot /><slot name="footer" /></div>',
            },
          },
        },
      })
    }

    it('renders a real button with data-testid lockdown-confirm-press', async () => {
      const wrapper = createWrapperWithRealConfirmModal()
      await openConfirmModal(wrapper)
      const button = wrapper.find('[data-testid="lockdown-confirm-press"]')
      expect(button.exists()).toBe(true)
      expect(button.text()).toBe('Press')
    })
  })
})
