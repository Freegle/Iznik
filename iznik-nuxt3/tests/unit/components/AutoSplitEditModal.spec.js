import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import AutoSplitEditModal from '~/components/AutoSplitEditModal.vue'

const patch = vi.fn()
const message = {
  id: 5,
  autosplit: true,
  bulkitems: [
    {
      id: 1,
      name: 'Sofa',
      quantity: 1,
      condition: 'Unknown',
      attachments: [{ id: 9 }],
    },
    {
      id: 2,
      name: 'Chairs',
      quantity: 2,
      condition: 'Unknown',
      attachments: [],
    },
    { id: 3, name: 'Lamp', quantity: 1, condition: 'Unknown', attachments: [] },
  ],
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ byId: () => message, patch }),
}))
vi.mock('~/composables/useOurModal', async () => {
  const { ref } = await import('vue')
  return { useOurModal: () => ({ modal: ref(null), hide: vi.fn() }) }
})

const opts = {
  props: { id: 5 },
  global: {
    stubs: {
      'b-modal': { template: '<div><slot /><slot name="footer" /></div>' },
      'b-form-input': {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template:
          '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
      },
      'b-button': {
        template:
          '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
        props: ['disabled'],
        emits: ['click'],
      },
      'v-icon': true,
    },
  },
}

describe('AutoSplitEditModal', () => {
  beforeEach(() => patch.mockClear())

  it('saves recounted and removed items, sending only which stay and how many', async () => {
    const w = mount(AutoSplitEditModal, opts)
    expect(w.text()).toContain('Sofa')
    const inputs = w.findAll('input')
    expect(inputs).toHaveLength(3)
    await inputs[1].setValue('4')
    await w.find('[data-testid="edit-remove-3"]').trigger('click')
    await w.find('[data-testid="edit-items-save"]').trigger('click')
    await flushPromises()

    expect(w.emitted('hidden')).toBeTruthy()
    expect(patch).toHaveBeenCalledWith({
      id: 5,
      bulkitems: [
        { id: 1, quantity: 1 },
        { id: 2, quantity: 4 },
      ],
    })
  })

  it('will not save fewer than two items, and points at Undo', async () => {
    const w = mount(AutoSplitEditModal, opts)
    await w.find('[data-testid="edit-remove-3"]').trigger('click')
    await w.find('[data-testid="edit-remove-2"]').trigger('click')
    expect(w.text()).toContain('use Undo')
    expect(
      w.find('[data-testid="edit-items-save"]').attributes('disabled')
    ).toBeDefined()
    await w.find('[data-testid="edit-items-save"]').trigger('click')
    expect(patch).not.toHaveBeenCalled()
  })
})
