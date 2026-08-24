import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, Suspense, h, reactive } from 'vue'

const mockRoute = reactive({
  params: {},
  query: { id: '99', uid: 'abc' },
  path: '/merge',
  name: 'merge',
  fullPath: '/merge',
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => mockRoute,
  }
})
globalThis.__testUseRoute = () => mockRoute

const mockFetch = vi.fn()
const mockReject = vi.fn()
const mockAccept = vi.fn().mockResolvedValue({})

vi.mock('~/api', () => ({
  default: () => ({
    merge: {
      fetch: mockFetch,
      reject: mockReject,
      accept: mockAccept,
    },
  }),
}))

const twoAccountsMerge = {
  id: 99,
  user1: {
    id: 1,
    name: 'Alice A',
    email: 'alice@example.com',
    logins: [{ type: 'Native' }],
  },
  user2: {
    id: 2,
    name: 'Alice B',
    email: 'aliceb@example.com',
    logins: [{ type: 'Google' }, { type: 'Google' }],
  },
}

async function mountMergePage() {
  const MergePage = (await import('~/pages/merge.vue')).default
  const Wrapper = defineComponent({
    setup() {
      return () => h(Suspense, null, { default: () => h(MergePage) })
    },
  })
  return mount(Wrapper, {
    global: {
      stubs: {
        NoticeMessage: { template: '<div class="notice"><slot /></div>' },
        SupportLink: { template: '<span>support</span>' },
        // The shared global b-form-select stub reads the native <select>'s
        // string value, losing the numeric type of the bound user ids. This
        // page's ids are always numeric, so override with a stub that
        // preserves that, matching how bootstrap-vue-next's real component
        // behaves via Vue's typed v-model binding.
        'b-form-select': {
          template:
            '<select :value="modelValue" @change="$emit(\'update:modelValue\', Number($event.target.value))"><slot /></select>',
          props: ['modelValue'],
          emits: ['update:modelValue'],
        },
        SpinButton: {
          template: '<button class="spin-button" @click="onClick" />',
          emits: ['handle'],
          methods: {
            onClick() {
              this.$emit('handle', () => {})
            },
          },
        },
      },
    },
  })
}

describe('pages/merge.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetch.mockResolvedValue(JSON.parse(JSON.stringify(twoAccountsMerge)))
  })

  it('fetches the merge and shows both accounts with de-duplicated login types', async () => {
    const wrapper = await mountMergePage()
    await flushPromises()

    expect(mockFetch).toHaveBeenCalledWith({ id: '99', uid: 'abc' })
    expect(wrapper.text()).toContain('Alice A')
    expect(wrapper.text()).toContain('Alice B')
    // user2 has TWO Google logins - logins() must de-dupe via [...new Set(...)]
    expect(wrapper.text()).toContain('Google')
    expect(wrapper.text()).not.toMatch(/Google.*Google/)
  })

  it('shows the invalid-request message when the merge cannot be found', async () => {
    mockFetch.mockResolvedValue(null)

    const wrapper = await mountMergePage()
    await flushPromises()

    expect(wrapper.text()).toContain("That request isn't valid")
  })

  it('rejects the merge and calls the API with both user ids', async () => {
    const wrapper = await mountMergePage()
    await flushPromises()

    const buttons = wrapper.findAll('button')
    const rejectButton = buttons.find((b) => b.text() === 'No thanks')
    await rejectButton.trigger('click')

    expect(mockReject).toHaveBeenCalledWith({
      id: '99',
      uid: 'abc',
      user1: 1,
      user2: 2,
    })
    expect(wrapper.text()).toContain("we'll keep them separate")
  })

  it('combines the accounts, keeping the non-preferred user2 id', async () => {
    const wrapper = await mountMergePage()
    await flushPromises()

    const buttons = wrapper.findAll('button')
    await buttons.find((b) => b.text() === 'Yes please').trigger('click')

    const select = wrapper.find('select')
    await select.setValue('1')

    await wrapper.find('.spin-button').trigger('click')
    await flushPromises()

    expect(mockAccept).toHaveBeenCalledWith({
      id: '99',
      uid: 'abc',
      user1: 1,
      user2: 2,
    })
    expect(wrapper.text()).toContain("We've merged your accounts")
  })
})
