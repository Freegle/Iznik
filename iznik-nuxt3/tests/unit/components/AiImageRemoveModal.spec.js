import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import AiImageRemoveModal from '~/components/AiImageRemoveModal.vue'

const mockShow = vi.fn()
const mockHide = vi.fn()

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: mockShow,
    hide: mockHide,
  }),
}))

function mountModal() {
  return mount(AiImageRemoveModal, {
    global: {
      stubs: {
        'b-modal': {
          template:
            '<div class="b-modal" :title="title"><slot name="default" /><slot name="footer" /></div>',
          props: ['title', 'noStacking'],
        },
        'b-button': {
          template:
            '<button :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant'],
          // Declared, so the parent's @click is not also attached natively and fired twice.
          emits: ['click'],
        },
      },
    },
  })
}

describe('AiImageRemoveModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('asks why the image is being removed, with the two answers', () => {
    const wrapper = mountModal()
    expect(wrapper.text()).toContain('Why are you removing it?')
    expect(wrapper.text()).toContain('Not relevant to this post')
    expect(wrapper.text()).toContain('Bad AI image for any post of this item')
  })

  it('emits choose with false for "not relevant" and hides', async () => {
    const wrapper = mountModal()
    const button = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Not relevant'))
    await button.trigger('click')
    expect(wrapper.emitted('choose')).toEqual([[false]])
    expect(mockHide).toHaveBeenCalled()
  })

  it('emits choose with true for "bad for any post" and hides', async () => {
    const wrapper = mountModal()
    const button = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Bad AI image'))
    await button.trigger('click')
    expect(wrapper.emitted('choose')).toEqual([[true]])
    expect(mockHide).toHaveBeenCalled()
  })

  it('emits cancel and hides from the Cancel button', async () => {
    const wrapper = mountModal()
    const button = wrapper.findAll('button').find((b) => b.text() === 'Cancel')
    await button.trigger('click')
    expect(wrapper.emitted('cancel')).toBeTruthy()
    expect(wrapper.emitted('choose')).toBeUndefined()
    expect(mockHide).toHaveBeenCalled()
  })

  it('exposes show and hide', () => {
    const wrapper = mountModal()
    wrapper.vm.show()
    expect(mockShow).toHaveBeenCalled()
    wrapper.vm.hide()
    expect(mockHide).toHaveBeenCalled()
  })
})
