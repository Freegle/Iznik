import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModBulkSpammerReport from '~/modtools/components/ModBulkSpammerReport.vue'

const mockHide = vi.fn()

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: vi.fn(),
    hide: mockHide,
  }),
}))

const stubs = {
  'b-modal': {
    template:
      '<div class="modal"><h5 class="title">{{ title }}</h5><slot /><slot name="footer" /></div>',
    props: ['title', 'size'],
  },
  'b-button': {
    template:
      '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'disabled'],
    emits: ['click'],
  },
  'b-form-textarea': {
    template:
      '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'placeholder'],
  },
  ExternalLink: { template: '<a><slot /></a>' },
}

function mountModal(props = {}) {
  return mount(ModBulkSpammerReport, {
    props: { count: 12, ...props },
    global: { stubs },
  })
}

describe('ModBulkSpammerReport', () => {
  beforeEach(() => {
    mockHide.mockClear()
  })

  it('says it is a report to the Spam team, for everyone', () => {
    const w = mountModal()
    expect(w.find('.title').text()).toBe('Report 12 as spammers')
    expect(w.find('[data-test="bulk-spam-send"]').text()).toBe('Send report')
    expect(w.text()).toContain('reported to the Spam team')
    expect(w.text()).toContain('already on the list is left as they are')
  })

  it('reads naturally for one member', () => {
    const w = mountModal({ count: 1 })
    expect(w.find('.title').text()).toBe('Report 1 as a spammer')
    expect(w.text()).toContain('This member will be reported')
  })

  it('will not send without a reason', async () => {
    const w = mountModal()
    const send = w.find('[data-test="bulk-spam-send"]')
    expect(send.attributes('disabled')).toBeDefined()
    await w.find('textarea').setValue('   ')
    expect(send.attributes('disabled')).toBeDefined()
    await send.trigger('click')
    expect(w.emitted('confirm')).toBeUndefined()
  })

  it('emits the trimmed reason and closes', async () => {
    const w = mountModal()
    await w.find('textarea').setValue('  Same name, many accounts  ')
    await w.find('[data-test="bulk-spam-send"]').trigger('click')
    expect(w.emitted('confirm')[0]).toEqual(['Same name, many accounts'])
    expect(mockHide).toHaveBeenCalled()
  })
})
