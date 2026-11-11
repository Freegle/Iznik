import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModSupportAIRating from '~/modtools/components/ModSupportAIRating.vue'

const mockRate = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    supportai: { rate: mockRate },
  }),
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: {} }),
}))

const stubs = {
  'b-button': {
    template:
      '<button :data-variant="variant" :disabled="disabled" v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'size', 'disabled'],
    emits: ['click'],
  },
  'b-form-textarea': {
    template:
      '<textarea v-bind="$attrs" :placeholder="placeholder" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'rows', 'maxRows', 'size', 'placeholder'],
    emits: ['update:modelValue'],
  },
  'v-icon': { template: '<span class="icon" />', props: ['icon'] },
}

function mountRating(props = {}) {
  return mount(ModSupportAIRating, {
    props: { runId: 7, ...props },
    global: { stubs },
  })
}

describe('ModSupportAIRating', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockRate.mockResolvedValue({ ret: 0 })
  })

  it('asks for a rating when there is none', () => {
    const wrapper = mountRating()
    expect(wrapper.text()).toContain('Was this answer right?')
    expect(
      wrapper.find('[data-testid="ai-rating-up"]').attributes('data-variant')
    ).toBe('outline-success')
  })

  it('saves a thumbs down and asks what was wrong', async () => {
    const wrapper = mountRating()
    await wrapper.find('[data-testid="ai-rating-down"]').trigger('click')
    await flushPromises()

    expect(mockRate).toHaveBeenCalledWith(7, -1, undefined)
    expect(
      wrapper.find('[data-testid="ai-rating-down"]').attributes('data-variant')
    ).toBe('danger')
    const comment = wrapper.find('[data-testid="ai-rating-comment"]')
    expect(comment.attributes('placeholder')).toContain('What was wrong')
    expect(wrapper.emitted('rated')[0][0]).toEqual({
      rating: -1,
      comment: undefined,
    })
  })

  it('saves the comment with the rating', async () => {
    const wrapper = mountRating()
    await wrapper.find('[data-testid="ai-rating-down"]').trigger('click')
    await flushPromises()
    await wrapper
      .find('[data-testid="ai-rating-comment"]')
      .setValue('It missed the held message.')
    await wrapper.find('[data-testid="ai-rating-save"]').trigger('click')
    await flushPromises()

    expect(mockRate).toHaveBeenLastCalledWith(
      7,
      -1,
      'It missed the held message.'
    )
    expect(wrapper.find('[data-testid="ai-rating-comment"]').exists()).toBe(
      false
    )
    expect(wrapper.text()).toContain('Thanks')
  })

  it('takes a rating back off when the same thumb is clicked again', async () => {
    const wrapper = mountRating({ rating: 1 })
    await wrapper.find('[data-testid="ai-rating-up"]').trigger('click')
    await flushPromises()

    expect(mockRate).toHaveBeenCalledWith(7, 0, undefined)
    expect(
      wrapper.find('[data-testid="ai-rating-up"]').attributes('data-variant')
    ).toBe('outline-success')
    expect(wrapper.find('[data-testid="ai-rating-comment"]').exists()).toBe(
      false
    )
  })

  it('shows buttons only, and no comment box, when compact', async () => {
    const wrapper = mountRating({ compact: true })
    expect(wrapper.text()).not.toContain('Was this answer right?')
    await wrapper.find('[data-testid="ai-rating-up"]').trigger('click')
    await flushPromises()
    expect(mockRate).toHaveBeenCalledWith(7, 1, undefined)
    expect(wrapper.find('[data-testid="ai-rating-comment"]').exists()).toBe(
      false
    )
  })

  it('says so when the rating could not be saved', async () => {
    mockRate.mockRejectedValue(new Error('500'))
    const wrapper = mountRating()
    await wrapper.find('[data-testid="ai-rating-up"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Could not save your rating')
    expect(
      wrapper.find('[data-testid="ai-rating-up"]').attributes('data-variant')
    ).toBe('outline-success')
    expect(wrapper.emitted('rated')).toBeUndefined()
  })
})
