import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import RipplingExplanationModal from '~/components/RipplingExplanationModal.vue'

const mockHide = vi.fn()
const mockShow = vi.fn()

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: mockShow,
    hide: mockHide,
  }),
}))

function mountComponent() {
  return mount(RipplingExplanationModal, {
    global: {
      stubs: {
        'b-modal': {
          template: `
            <div class="b-modal" :id="id" :title="title" :size="size">
              <slot name="default" />
              <slot name="footer" />
            </div>
          `,
          props: ['id', 'title', 'size'],
        },
        'b-button': {
          template:
            '<button :class="variant" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant'],
        },
        RipplingExplanation: {
          template: '<div class="rippling-explanation-stub" />',
        },
      },
    },
  })
}

describe('RipplingExplanationModal', () => {
  it('renders the modal with the correct id and title', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.b-modal').exists()).toBe(true)
    expect(wrapper.find('.b-modal').attributes('id')).toBe(
      'ripplingExplanationModal'
    )
    expect(wrapper.find('.b-modal').attributes('title')).toBe(
      'How does this work?'
    )
  })

  it('renders the reusable RipplingExplanation component', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.rippling-explanation-stub').exists()).toBe(true)
  })

  it('exposes show and hide methods', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    expect(typeof wrapper.vm.show).toBe('function')
    expect(typeof wrapper.vm.hide).toBe('function')
  })

  it('calls hide when the Close button is clicked', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    const button = wrapper.find('button')
    expect(button.text()).toBe('Close')
    await button.trigger('click')
    expect(mockHide).toHaveBeenCalled()
  })
})
