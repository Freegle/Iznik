import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModSysAdminPafModal from '~/modtools/components/ModSysAdminPafModal.vue'

const mockHide = vi.fn()

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: vi.fn(),
    hide: mockHide,
  }),
}))

vi.mock('~/modtools/components/ModSysAdminPaf.vue', () => ({
  default: { name: 'ModSysAdminPaf', template: '<div class="paf" />' },
}))

describe('ModSysAdminPafModal', () => {
  function mountComponent() {
    return mount(ModSysAdminPafModal, {
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="modal" :title="title"><slot /><slot name="footer" /><button class="hidden-trigger" @click="$emit(\'hidden\')" /></div>',
            props: ['title'],
            emits: ['hidden'],
          },
          'b-button': {
            template:
              '<button class="close" @click="$emit(\'click\')"><slot /></button>',
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the PAF update inside the modal', () => {
    const wrapper = mountComponent()
    expect(wrapper.find('.modal').attributes('title')).toBe('PAF address data')
    expect(wrapper.find('.paf').exists()).toBe(true)
  })

  it('closes from the Close button', async () => {
    const wrapper = mountComponent()
    await wrapper.find('button.close').trigger('click')
    expect(mockHide).toHaveBeenCalled()
  })

  it('tells the parent when it has been hidden', async () => {
    const wrapper = mountComponent()
    await wrapper.find('.hidden-trigger').trigger('click')
    expect(wrapper.emitted('hidden')).toHaveLength(1)
  })
})
