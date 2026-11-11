import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import AskNameModal from '~/components/AskNameModal.vue'

const { mockHide, mockSaveName, mockSkipName, askNameOpen } = vi.hoisted(
  () => ({
    mockHide: vi.fn(),
    mockSaveName: vi.fn(),
    mockSkipName: vi.fn(),
    askNameOpen: { value: true },
  })
)

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: ref(null), hide: mockHide }),
}))
vi.mock('~/composables/useAskName', () => ({
  useAskName: () => ({
    askNameOpen,
    saveName: mockSaveName,
    skipName: mockSkipName,
  }),
}))
vi.mock('~/composables/useLockdownNotice', () => ({
  useLockdownNotice: () => ({
    lockdownError: ref(null),
    guardLockdown: (fn) => fn(),
  }),
}))

function createWrapper() {
  return mount(AskNameModal, {
    global: {
      stubs: {
        'b-modal': {
          name: 'BModalStub',
          template: '<div class="b-modal"><slot /><slot name="footer" /></div>',
          props: ['title', 'noCloseOnBackdrop'],
          emits: ['hidden'],
        },
        'b-button': {
          template:
            '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'disabled'],
        },
        'b-form-input': {
          template:
            '<input class="name" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue'],
        },
      },
    },
  })
}

const button = (w, text) => w.findAll('button').find((b) => b.text() === text)

describe('AskNameModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    askNameOpen.value = true
    mockSaveName.mockResolvedValue()
  })

  it('says who sees the name', () => {
    expect(createWrapper().text()).toContain(
      'Other freeglers see it when you post or reply.'
    )
  })

  it('cannot save an empty name', async () => {
    const w = createWrapper()
    await w.find('input.name').setValue('   ')
    expect(button(w, 'Save').attributes('disabled')).toBeDefined()
  })

  it('saves the trimmed name', async () => {
    const w = createWrapper()
    await w.find('input.name').setValue('  Sam ')
    await button(w, 'Save').trigger('click')
    await flushPromises()
    expect(mockSaveName).toHaveBeenCalledWith('Sam')
    expect(mockHide).toHaveBeenCalled()
  })

  it('treats closing without saving as not now', async () => {
    const w = createWrapper()
    await w.findComponent({ name: 'BModalStub' }).vm.$emit('hidden')
    expect(mockSkipName).toHaveBeenCalled()
  })

  it('does not count a save as not now when the modal then closes', async () => {
    const w = createWrapper()
    await w.find('input.name').setValue('Sam')
    await button(w, 'Save').trigger('click')
    await flushPromises()
    await w.findComponent({ name: 'BModalStub' }).vm.$emit('hidden')
    expect(mockSkipName).not.toHaveBeenCalled()
  })
})
