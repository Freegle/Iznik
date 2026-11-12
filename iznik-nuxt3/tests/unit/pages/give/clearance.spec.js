/**
 * /give/clearance: the closing date is optional ("Leave it blank to keep it open until you take it
 * down"), so a date that was set can be removed when editing, and a date that has already gone is
 * never posted.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ClearancePage from '~/pages/give/clearance.vue'

const state = vi.hoisted(() => ({}))

globalThis.__testUseRouter = () => ({ push: vi.fn() })
globalThis.__testUseRoute = () => ({ query: state.query })
globalThis.useHead = () => {}

const mockPatch = vi.fn()
const mockFetch = vi.fn()
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    patch: mockPatch,
    fetch: mockFetch,
    byId: () => null,
  }),
}))

const mockCreateDraft = vi.fn()
const mockSubmitDraft = vi.fn()
vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({
    get clearanceDraft() {
      return state.draft
    },
    saveClearanceDraft: vi.fn(),
    clearClearanceDraft: vi.fn(),
    createDraft: mockCreateDraft,
    submitDraft: mockSubmitDraft,
  }),
}))

vi.mock('~/composables/useCompose', async () => {
  const { ref } = await import('vue')
  const actual = await vi.importActual('~/composables/useCompose')
  return {
    setup: async () => ({
      email: ref('freegler@example.com'),
      loggedIn: ref(true),
      initialPostcode: ref(''),
      postcode: ref({ id: 1 }),
      postcodeValid: ref(true),
      noGroups: ref(false),
    }),
    postcodeSelect: vi.fn(),
    postcodeClear: vi.fn(),
    makeCanSubmit: actual.makeCanSubmit,
  }
})

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 1, permissions: ['Clearance'] },
    forceLogin: false,
  }),
}))

const stubs = {
  'client-only': { template: '<div><slot /></div>' },
  'b-row': { template: '<div><slot /></div>' },
  'b-col': { template: '<div><slot /></div>' },
  'b-button': { template: '<button><slot /></button>' },
  'b-form-input': {
    template:
      '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue'],
  },
  'b-form-textarea': { template: '<textarea />', props: ['modelValue'] },
  'v-icon': { template: '<i />' },
  PostCode: { template: '<div />' },
  ComposeGroup: { template: '<div />' },
  BulkItemEditor: { template: '<div />' },
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
  SpinButton: {
    template: '<button class="spin" @click="$emit(\'handle\', () => {})" />',
    props: ['disabled'],
  },
}

async function mountPage() {
  const Host = {
    components: { ClearancePage },
    template: '<Suspense><ClearancePage /></Suspense>',
  }
  const wrapper = mount(Host, { global: { stubs } })
  await flushPromises()
  return wrapper
}

describe('give/clearance: closing date', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    state.query = {}
    state.draft = null
    mockCreateDraft.mockResolvedValue(5)
    mockSubmitDraft.mockResolvedValue({ id: 9 })
  })

  it('removes the closing date from an existing clearance when it is blanked', async () => {
    state.query = { id: '42' }
    mockFetch.mockResolvedValue({
      id: 42,
      subject: 'OFFER: Office clearance',
      textbody: 'Everything must go',
      deadline: '2099-12-01',
      bulkitems: [{ id: 1, name: 'Desk', quantity: 1 }],
    })

    const wrapper = await mountPage()
    const date = wrapper.find('[data-testid="clearance-deadline"]')
    expect(date.element.value).toBe('2099-12-01')
    await date.setValue('')
    await wrapper.find('.spin').trigger('click')
    await flushPromises()

    expect(mockPatch).toHaveBeenCalledWith(
      expect.objectContaining({ id: 42, deadline: '' })
    )
  })

  it('does not post a clearance with a closing date that has already gone', async () => {
    state.draft = {
      title: 'Office clearance',
      description: '',
      items: [{ name: 'Desk', quantity: 1 }],
      tray: [],
      slots: [''],
      accessInstructions: '',
      deadline: '2020-01-01',
    }

    const wrapper = await mountPage()
    await wrapper.find('.spin').trigger('click')
    await flushPromises()

    expect(mockSubmitDraft).not.toHaveBeenCalledWith(
      expect.anything(),
      expect.anything(),
      expect.objectContaining({ deadline: expect.stringContaining('2020') })
    )
  })
})
