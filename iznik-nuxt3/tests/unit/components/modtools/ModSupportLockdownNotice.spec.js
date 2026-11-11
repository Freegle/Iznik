import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownNotice from '~/modtools/components/ModSupportLockdownNotice.vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: "No notice" or
// custom text, edited in a text box and only sent on Save. The earlier
// wordings are offered as starting text while a lockdown is on; "Things are
// back to normal" once it has closed.
describe('ModSupportLockdownNotice', () => {
  function createWrapper(props = {}) {
    return mount(ModSupportLockdownNotice, {
      props,
      global: {
        stubs: {
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['disabled', 'variant', 'size'],
            emits: ['click'],
          },
        },
      },
    })
  }

  const box = (w) => w.find('[data-testid="lockdown-notice-text"]')
  const save = (w) => w.find('[data-testid="lockdown-notice-save"]')
  const button = (w, text) => w.findAll('button').find((b) => b.text() === text)

  it('starts with the saved notice in the box', () => {
    const wrapper = createWrapper({ notice: 'Back soon.', active: true })
    expect(box(wrapper).element.value).toBe('Back soon.')
    expect(wrapper.find('[data-testid="lockdown-notice-current"]').text()).toBe(
      'Members see: "Back soon."'
    )
  })

  it('says members see no notice when there is none', () => {
    const wrapper = createWrapper({ notice: null, active: true })
    expect(box(wrapper).element.value).toBe('')
    expect(wrapper.find('[data-testid="lockdown-notice-current"]').text()).toBe(
      'Members see no notice.'
    )
  })

  it('does not save until something has changed', async () => {
    const wrapper = createWrapper({ notice: 'Back soon.', active: true })
    expect(save(wrapper).attributes('disabled')).toBeDefined()
    await box(wrapper).setValue('Back very soon.')
    expect(save(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('does not send anything while typing - only on Save', async () => {
    const wrapper = createWrapper({ active: true })
    await box(wrapper).setValue('Messages may be delayed.')
    expect(wrapper.emitted('save')).toBeFalsy()
    await save(wrapper).trigger('click')
    expect(wrapper.emitted('save')).toEqual([['Messages may be delayed.']])
  })

  it('saves null for No notice', async () => {
    const wrapper = createWrapper({ notice: 'Back soon.', active: true })
    await wrapper.find('[data-testid="lockdown-notice-none"]').trigger('click')
    await save(wrapper).trigger('click')
    expect(wrapper.emitted('save')).toEqual([[null]])
  })

  it('trims the text, and treats only spaces as no notice', async () => {
    const wrapper = createWrapper({ notice: 'Back soon.', active: true })
    await box(wrapper).setValue('   ')
    await save(wrapper).trigger('click')
    expect(wrapper.emitted('save')).toEqual([[null]])
  })

  it('offers the earlier wordings as starting text while active', async () => {
    const wrapper = createWrapper({ active: true })
    await button(wrapper, 'Running slowly').trigger('click')
    expect(box(wrapper).element.value).toContain(
      'Freegle is running slowly today.'
    )
    await button(wrapper, 'Spam attack').trigger('click')
    expect(box(wrapper).element.value).toContain("please don't click the link.")
    expect(button(wrapper, 'Back to normal')).toBeUndefined()
  })

  it('offers Things are back to normal once closed', async () => {
    const wrapper = createWrapper({ active: false })
    expect(wrapper.find('h4').text()).toBe('Member notice after the lockdown')
    expect(button(wrapper, 'Spam attack')).toBeUndefined()
    await button(wrapper, 'Back to normal').trigger('click')
    await save(wrapper).trigger('click')
    expect(wrapper.emitted('save')).toEqual([['Things are back to normal.']])
  })

  it('resets the box when the saved notice changes', async () => {
    const wrapper = createWrapper({ notice: 'Old.', active: true })
    await box(wrapper).setValue('Half typed')
    await wrapper.setProps({ notice: null })
    expect(box(wrapper).element.value).toBe('')
  })

  it('disables Save while busy', async () => {
    const wrapper = createWrapper({ active: true, busy: true })
    await box(wrapper).setValue('Something')
    expect(save(wrapper).attributes('disabled')).toBeDefined()
  })
})
