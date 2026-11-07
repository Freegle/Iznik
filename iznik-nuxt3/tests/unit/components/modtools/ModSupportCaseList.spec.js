import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModSupportCaseList from '~/modtools/components/ModSupportCaseList.vue'

const mockFetchCases = vi.fn()
const meState = { myid: ref(1) }
vi.mock('~/composables/useMe', () => ({ useMe: () => meState }))
vi.mock('~/api', () => ({
  default: () => ({ supportcases: { fetchCases: mockFetchCases } }),
}))
vi.mock('#app', () => ({ useRuntimeConfig: () => ({ public: {} }) }))
vi.mock('~/composables/useTimeFormat', () => ({
  timeago: vi.fn(() => '5 minutes ago'),
}))

function row(id, extra = {}) {
  return {
    id,
    created: '2026-11-07T10:00:00Z',
    status: 'Open',
    chatid: 90 + id,
    member: { id: 2, displayname: 'Morgan Member' },
    referrer: { id: 4, displayname: 'Rae Referrer' },
    classification: 'Usage',
    confidence: 'High',
    summary: 'Why does case ' + id + ' fail?',
    firstpass_status: 'Done',
    messages: 2,
    lastactivity: '2026-11-07T10:30:00Z',
    ...extra,
  }
}

const stubs = {
  'b-form-radio-group': {
    template:
      '<div class="filter"><button v-for="o in options" :key="o.value" :data-value="o.value" @click="$emit(\'update:modelValue\', o.value)">{{ o.text }}</button></div>',
    props: ['modelValue', 'options'],
    emits: ['update:modelValue'],
  },
  'b-button': {
    template:
      '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'disabled'],
    emits: ['click'],
  },
  'b-badge': { template: '<span class="badge"><slot /></span>' },
  'b-spinner': { template: '<span class="spinner" />' },
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
  'nuxt-link': {
    template: '<a :href="to" v-bind="$attrs"><slot /></a>',
    props: ['to'],
  },
}

const mountList = () => mount(ModSupportCaseList, { global: { stubs } })

describe('ModSupportCaseList', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    meState.myid.value = 1
  })

  it('loads the list when the member logs in after it opened', async () => {
    meState.myid.value = undefined
    mockFetchCases.mockResolvedValueOnce([]).mockResolvedValueOnce([row(1)])
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.findAll('[data-testid="case-row"]')).toHaveLength(0)
    meState.myid.value = 5
    await flushPromises()
    expect(wrapper.findAll('[data-testid="case-row"]')).toHaveLength(1)
  })

  it('lists open cases first with a link to each', async () => {
    mockFetchCases.mockResolvedValue([
      row(2),
      row(1, { firstpass_status: 'Pending', messages: 1 }),
    ])
    const wrapper = mountList()
    await flushPromises()

    expect(mockFetchCases).toHaveBeenCalledWith({ status: 'Open', limit: 50 })
    const rows = wrapper.findAll('[data-testid="case-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].attributes('href')).toBe('/support/cases/2')
    expect(rows[0].text()).toContain('About Morgan Member')
    expect(rows[0].text()).toContain('Referred by Rae Referrer')
    expect(rows[0].text()).toContain('2 replies')
    expect(rows[0].text()).toContain('Why does case 2 fail?')
    expect(rows[1].text()).toContain('1 reply.')
    expect(rows[1].text()).toContain('First pass: Pending')
    expect(rows[0].text()).not.toContain('First pass:')
  })

  it('handles a case with no member and no first pass', async () => {
    mockFetchCases.mockResolvedValue([
      row(3, {
        member: null,
        referrer: null,
        classification: null,
        confidence: null,
        summary: null,
      }),
    ])
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.find('[data-testid="case-row"]').text()).toContain('Case 3')
  })

  it('pages on the last id and stops when a page is short', async () => {
    const first = Array.from({ length: 50 }, (_, i) => row(100 - i))
    mockFetchCases.mockResolvedValueOnce(first).mockResolvedValueOnce([row(40)])
    const wrapper = mountList()
    await flushPromises()
    await wrapper.find('[data-testid="case-more"]').trigger('click')
    await flushPromises()

    expect(mockFetchCases).toHaveBeenLastCalledWith({
      status: 'Open',
      limit: 50,
      before: 51,
    })
    expect(wrapper.findAll('[data-testid="case-row"]')).toHaveLength(51)
    expect(wrapper.find('[data-testid="case-more"]').exists()).toBe(false)
  })

  it('reloads from the start when the filter changes', async () => {
    mockFetchCases.mockResolvedValue([row(5)])
    const wrapper = mountList()
    await flushPromises()
    await wrapper.find('[data-value="Handled"]').trigger('click')
    await flushPromises()
    expect(mockFetchCases).toHaveBeenLastCalledWith({
      status: 'Handled',
      limit: 50,
    })
    expect(wrapper.findAll('[data-testid="case-row"]')).toHaveLength(1)
  })

  it('drops a response that arrives after the filter changed', async () => {
    let releaseOpen
    mockFetchCases.mockImplementationOnce(
      () => new Promise((resolve) => (releaseOpen = resolve))
    )
    mockFetchCases.mockResolvedValueOnce([row(8, { status: 'Handled' })])
    const wrapper = mountList()
    await flushPromises()
    await wrapper.find('[data-value="Handled"]').trigger('click')
    await flushPromises()
    releaseOpen([row(1), row(2)])
    await flushPromises()
    const rows = wrapper.findAll('[data-testid="case-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].attributes('href')).toBe('/support/cases/8')
  })

  it('says so when there are none, and when loading fails', async () => {
    mockFetchCases.mockResolvedValue([])
    let wrapper = mountList()
    await flushPromises()
    expect(wrapper.text()).toContain('No cases to show')

    mockFetchCases.mockRejectedValue(new Error('500'))
    wrapper = mountList()
    await flushPromises()
    expect(wrapper.text()).toContain('Could not load the cases')
  })
})
