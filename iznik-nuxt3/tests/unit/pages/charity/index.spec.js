import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import CharityPage from '~/pages/charity/index.vue'

globalThis.useHead = () => {}

const mockSignup = vi.fn()
vi.mock('~/api', () => ({
  default: () => ({
    charity: {
      signup: mockSignup,
    },
  }),
}))

function mountPage() {
  return mount(CharityPage, {
    global: {
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        // The shared global b-form-input stub declares `id` as a component
        // prop without binding it into the template, so it never reaches
        // the DOM - id-based selectors can't find the field. Override with
        // one that forwards it, matching the real bootstrap-vue-next input.
        'b-form-input': {
          template:
            '<input :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'id', 'type', 'placeholder', 'maxlength'],
        },
        'b-form-textarea': {
          template:
            '<textarea :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'id'],
        },
        CharityBadge: { template: '<div class="charity-badge" />' },
        // The shared global b-form-radio-group stub renders a bare <slot />
        // with no listener, so a child b-form-radio's 'update:modelValue'
        // emit never reaches the group's own v-model. Override to catch the
        // native 'change' event bubbling up from the radio <input> instead.
        'b-form-radio-group': {
          template:
            '<div class="radio-group" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></div>',
          props: ['modelValue'],
          emits: ['update:modelValue'],
        },
      },
    },
  })
}

describe('pages/charity/index.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('disables the submit button until the required fields and org type are set', async () => {
    const wrapper = mountPage()

    const submitButton = wrapper.find('.submit-btn')
    expect(submitButton.attributes('disabled')).toBeDefined()

    await wrapper.find('#org-name').setValue('Wandsworth Reuse CIC')
    await wrapper.find('#contact-email').setValue('info@example.org')
    const radios = wrapper.findAll('input[type="radio"]')
    await radios[0].setValue()

    expect(wrapper.find('.submit-btn').attributes('disabled')).toBeUndefined()
  })

  it('submits the form and shows the success message', async () => {
    mockSignup.mockResolvedValue({})
    const wrapper = mountPage()

    await wrapper.find('#org-name').setValue('Wandsworth Reuse CIC')
    await wrapper.find('#contact-email').setValue('info@example.org')
    const radios = wrapper.findAll('input[type="radio"]')
    await radios[1].setValue() // 'other'

    await wrapper.find('.submit-btn').trigger('click')
    await flushPromises()

    expect(mockSignup).toHaveBeenCalledWith(
      expect.objectContaining({
        orgname: 'Wandsworth Reuse CIC',
        orgtype: 'other',
        contactemail: 'info@example.org',
        charitynumber: null,
      })
    )
    expect(wrapper.text()).toContain("Thanks! We've received your registration")
  })

  it('shows an error message when the API call fails', async () => {
    mockSignup.mockRejectedValue(new Error('network error'))
    const wrapper = mountPage()

    await wrapper.find('#org-name').setValue('Wandsworth Reuse CIC')
    await wrapper.find('#contact-email').setValue('info@example.org')
    const radios = wrapper.findAll('input[type="radio"]')
    await radios[0].setValue()

    await wrapper.find('.submit-btn').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Sorry, something went wrong')
    expect(wrapper.find('.submit-btn').attributes('disabled')).toBeUndefined()
  })
})
