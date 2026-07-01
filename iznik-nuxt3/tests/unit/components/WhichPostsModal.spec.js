import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import WhichPostsModal from '~/components/WhichPostsModal.vue'

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
  return mount(WhichPostsModal, {
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
      },
    },
  })
}

describe('WhichPostsModal', () => {
  it('renders the modal with the correct id and title', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.b-modal').exists()).toBe(true)
    expect(wrapper.find('.b-modal').attributes('id')).toBe('whichPostsModal')
    expect(wrapper.find('.b-modal').attributes('title')).toBe(
      'Which posts do I see?'
    )
  })

  it('renders the reusable WhichPostsExplanation component', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    // The same component the /help page renders for the which-posts topic, so the
    // modal and the help page stay consistent (#K).
    expect(wrapper.text()).toContain('Show posts from')
    expect(wrapper.text()).toContain('Can I always reply?')
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
