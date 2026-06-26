import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import RipplingHelpModal from '~/modtools/components/RipplingHelpModal.vue'

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
  return mount(RipplingHelpModal, {
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

describe('RipplingHelpModal', () => {
  describe('rendering', () => {
    it('renders the modal', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.find('.b-modal').exists()).toBe(true)
    })

    it('renders modal with correct id', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.find('.b-modal').attributes('id')).toBe(
        'ripplingHelpModal'
      )
    })

    it('renders modal with correct title', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.find('.b-modal').attributes('title')).toBe(
        'How does this work?'
      )
    })

    it('renders the RipplingExplanation component', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.find('.rippling-explanation-stub').exists()).toBe(true)
    })

    it('renders the Using the map section heading', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      const headings = wrapper.findAll('h5')
      const headingTexts = headings.map((h) => h.text())
      expect(headingTexts).toContain('Using the map')
    })

    it('renders the map usage list items', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      const listItems = wrapper.findAll('li')
      const texts = listItems.map((li) => li.text())
      expect(texts.some((t) => t.includes('Click the map'))).toBe(true)
      expect(texts.some((t) => t.includes('Animate ripple'))).toBe(true)
    })

    it('renders the Close button in the footer', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      const button = wrapper.find('button')
      expect(button.exists()).toBe(true)
      expect(button.text()).toBe('Close')
    })
  })

  describe('modal functionality', () => {
    it('exposes show method', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      expect(typeof wrapper.vm.show).toBe('function')
    })

    it('exposes hide method', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      expect(typeof wrapper.vm.hide).toBe('function')
    })

    it('calls hide when Close button is clicked', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      const button = wrapper.find('button')
      await button.trigger('click')
      expect(mockHide).toHaveBeenCalled()
    })
  })
})
