import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModSysAdminSupportAI from '~/modtools/components/ModSysAdminSupportAI.vue'

vi.mock('marked', () => ({
  marked: vi.fn((text) => `<p>${text}</p>`),
}))

const mockFetchRuns = vi.fn()
const mockFetchRun = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    supportai: { fetchRuns: mockFetchRuns, fetchRun: mockFetchRun },
  }),
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: {} }),
}))

vi.mock('~/composables/useTimeFormat', () => ({
  timeago: vi.fn(() => '5 minutes ago'),
}))

function run(id, extra = {}) {
  return {
    id,
    modid: 10,
    modname: 'Sam Support',
    userid: 20,
    username: 'Morgan Member',
    query: `Question ${id}`,
    analysis: `Answer ${id}`,
    status: 'Success',
    input_tokens: 1500,
    output_tokens: 500,
    duration_ms: 75000,
    quota_5h_before: 10,
    quota_5h_after: 12.5,
    quota_7d_before: 40,
    quota_7d_after: 40.25,
    rating: null,
    rating_comment: null,
    created_at: '2026-10-02T10:00:00Z',
    ...extra,
  }
}

const stubs = {
  'b-form-radio-group': {
    template:
      '<div class="filter"><button v-for="o in options" :key="o.value" :data-value="o.value" @click="$emit(\'update:modelValue\', o.value)">{{ o.text }}</button></div>',
    props: ['modelValue', 'options', 'buttons', 'buttonVariant', 'size'],
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
  'v-icon': { template: '<span class="icon" />', props: ['icon'] },
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
  ModSupportAIRating: {
    template: '<div class="rating" :data-rating="rating" />',
    props: ['runId', 'rating', 'comment', 'compact'],
  },
}

function mountPage() {
  return mount(ModSysAdminSupportAI, { global: { stubs } })
}

describe('ModSysAdminSupportAI', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('lists runs most recent first with who, what and what it used', async () => {
    mockFetchRuns.mockResolvedValue([
      run(2, { rating: -1, rating_comment: 'Missed the held message' }),
      run(1, { rating: 1 }),
    ])
    const wrapper = mountPage()
    await flushPromises()

    expect(mockFetchRuns).toHaveBeenCalledWith({ limit: 50 })
    const rows = wrapper.findAll('[data-testid="supportai-run"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Question 2')
    expect(rows[0].text()).toContain('Sam Support')
    expect(rows[0].text()).toContain('Morgan Member (#20)')
    expect(rows[0].text()).toContain('Missed the held message')
    expect(rows[0].text()).toContain('1m 15s')
    expect(rows[0].text()).toContain('2.0k tokens')
    expect(rows[0].text()).toContain('5h +2.5%, week +0.25%')
    expect(rows[0].classes()).toContain('run--down')
    expect(rows[1].classes()).toContain('run--up')
    expect(wrapper.text()).toContain('1 up, 1 down, 0 not rated')
  })

  it('leaves quota out when it is not known', async () => {
    mockFetchRuns.mockResolvedValue([
      run(1, { quota_5h_before: null, quota_7d_after: null }),
    ])
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).not.toContain('5h +')
    expect(wrapper.text()).not.toContain('week +')
  })

  it('opens a run to show its answer and each step', async () => {
    mockFetchRuns.mockResolvedValue([run(1)])
    mockFetchRun.mockResolvedValue({
      ...run(1),
      transcript: JSON.stringify([
        { type: 'text', text: 'Checking their email.' },
        { type: 'tool', name: 'db_query', input: { sql: 'SELECT 1' } },
        { type: 'result', text: 'validated: NULL', error: false },
      ]),
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('.run__summary').trigger('click')
    await flushPromises()

    expect(mockFetchRun).toHaveBeenCalledWith(1)
    const detail = wrapper.find('.run__detail')
    expect(detail.text()).toContain('Answer 1')
    expect(detail.text()).toContain('Checking their email.')
    expect(detail.text()).toContain('db_query')
    expect(detail.text()).toContain('SELECT 1')
    expect(detail.text()).toContain('validated: NULL')

    // Closing and reopening does not fetch it again.
    await wrapper.find('.run__summary').trigger('click')
    await wrapper.find('.run__summary').trigger('click')
    await flushPromises()
    expect(mockFetchRun).toHaveBeenCalledTimes(1)
  })

  it('copes with a run whose transcript is missing or broken', async () => {
    mockFetchRuns.mockResolvedValue([run(1)])
    mockFetchRun.mockResolvedValue({ ...run(1), transcript: 'not json' })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('.run__summary').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('No steps were recorded.')
  })

  it('filters by rating, starting again from the most recent', async () => {
    mockFetchRuns.mockResolvedValue([run(1)])
    const wrapper = mountPage()
    await flushPromises()

    mockFetchRuns.mockResolvedValue([run(5, { rating: -1 })])
    await wrapper.find('.filter [data-value="down"]').trigger('click')
    await flushPromises()

    expect(mockFetchRuns).toHaveBeenLastCalledWith({
      limit: 50,
      rating: 'down',
    })
    const rows = wrapper.findAll('[data-testid="supportai-run"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('Question 5')
  })

  it('loads older runs from before the last one shown', async () => {
    const page = Array.from({ length: 50 }, (_, i) => run(100 - i))
    mockFetchRuns.mockResolvedValue(page)
    const wrapper = mountPage()
    await flushPromises()

    mockFetchRuns.mockResolvedValue([run(10)])
    await wrapper.find('[data-testid="supportai-more"]').trigger('click')
    await flushPromises()

    expect(mockFetchRuns).toHaveBeenLastCalledWith({ limit: 50, before: 51 })
    expect(wrapper.findAll('[data-testid="supportai-run"]')).toHaveLength(51)
    // A short page means there is nothing older.
    expect(wrapper.find('[data-testid="supportai-more"]').exists()).toBe(false)
  })

  it('says so when the runs cannot be loaded', async () => {
    mockFetchRuns.mockRejectedValue(new Error('500'))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Could not load the helper runs.')
  })
})
