import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

import ModPartnershipStats from '~/modtools/components/ModPartnershipStats.vue'

const store = reactive({
  statsJobs: [],
  statsRunning: false,
  fetchStatsJobs: vi.fn(),
  addStatsJob: vi.fn(),
  removeStatsJob: vi.fn(),
})

vi.mock('~/stores/partnerships', () => ({
  usePartnershipsStore: () => store,
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ auth: { jwt: 'jwt-token', persistent: null } }),
}))

const partnerships = [
  { id: 1, authorityid: 10, authorityname: 'Northshire' },
  { id: 2, authorityid: 11, authorityname: 'Southbury' },
  // A second deal with the same council must not give a duplicate option.
  { id: 3, authorityid: 10, authorityname: 'Northshire' },
]

function mountStats(props = {}) {
  return mount(ModPartnershipStats, {
    props: { partnerships, ...props },
    global: {
      stubs: {
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-badge': { template: '<span><slot /></span>', props: ['variant'] },
        'b-button': {
          template: '<button @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size'],
          emits: ['click'],
        },
        'b-form-select': {
          template:
            '<select><option v-for="o in options" :key="o.value" :value="o.value">{{ o.text }}</option></select>',
          props: ['modelValue', 'options', 'multiple'],
        },
        SpinButton: {
          template:
            '<button :disabled="disabled" @click="$emit(\'handle\')" />',
          props: ['variant', 'iconName', 'label', 'spinclass', 'disabled'],
        },
        NoticeMessage: { template: '<div><slot /></div>', props: ['variant'] },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

describe('ModPartnershipStats', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    store.statsJobs = []
    store.statsRunning = false
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('loads the jobs when it appears', async () => {
    mountStats()
    await flushPromises()

    expect(store.fetchStatsJobs).toHaveBeenCalled()
  })

  it('offers each council once, however many deals we have had with it', async () => {
    const wrapper = mountStats()
    await flushPromises()

    // The first select is the council picker; the second is the quarter.
    const options = wrapper.findAll('select')[0].findAll('option')

    expect(options).toHaveLength(2)
    // Sorted by name, so a long list is easy to pick from.
    expect(options.map((o) => o.text())).toEqual(['Northshire', 'Southbury'])
  })

  it('offers the last full quarter plus recent ones', async () => {
    const wrapper = mountStats()
    await flushPromises()

    const quarters = wrapper.findAll('select')[1].findAll('option')

    expect(quarters[0].text()).toBe('Last full quarter')
    expect(quarters).toHaveLength(5)
  })

  it('nudges you to add a partnership when there are no councils', async () => {
    const wrapper = mountStats({ partnerships: [] })
    await flushPromises()

    expect(wrapper.text()).toContain('Add a partnership first')
  })

  it('names the councils on a job rather than showing bare ids', async () => {
    store.statsJobs = [
      {
        id: 5,
        authorityids: '10,11',
        quarter: '3 months ago',
        status: 'Ready',
        requested: '2026-08-08 10:00:00',
        files: [
          { id: 1, filename: 'Freegle-Statistics-Northshire.xlsx', size: 2048 },
        ],
      },
    ]

    const wrapper = mountStats()
    await flushPromises()

    expect(wrapper.text()).toContain('Northshire, Southbury')
    expect(wrapper.text()).toContain('Freegle-Statistics-Northshire.xlsx')
  })

  it('says a job is still building while it has no files', async () => {
    store.statsJobs = [
      {
        id: 5,
        authorityids: '10',
        quarter: '3 months ago',
        status: 'Running',
        requested: '2026-08-08 10:00:00',
        files: [],
      },
    ]

    const wrapper = mountStats()
    await flushPromises()

    expect(wrapper.text()).toContain('Building...')
  })

  it('shows why a job failed', async () => {
    store.statsJobs = [
      {
        id: 5,
        authorityids: '10',
        quarter: '3 months ago',
        status: 'Failed',
        error: 'Authority 10 not found',
        requested: '2026-08-08 10:00:00',
        files: [],
      },
    ]

    const wrapper = mountStats()
    await flushPromises()

    expect(wrapper.text()).toContain('Authority 10 not found')
    expect(wrapper.text()).not.toContain('Building...')
  })

  it('keeps polling while something is still building', async () => {
    store.statsRunning = true
    mountStats()
    await flushPromises()

    expect(store.fetchStatsJobs).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(10000)

    expect(store.fetchStatsJobs).toHaveBeenCalledTimes(2)
  })

  it('stops polling once everything has finished', async () => {
    mountStats()
    await flushPromises()

    expect(store.fetchStatsJobs).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(60000)

    expect(store.fetchStatsJobs).toHaveBeenCalledTimes(1)
  })

  it('stops polling when the page is left', async () => {
    store.statsRunning = true
    const wrapper = mountStats()
    await flushPromises()

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(60000)

    expect(store.fetchStatsJobs).toHaveBeenCalledTimes(1)
  })

  it('says so when a download fails, rather than saving a broken spreadsheet', async () => {
    store.statsJobs = [
      {
        id: 5,
        authorityids: '10',
        quarter: '3 months ago',
        status: 'Ready',
        requested: '2026-08-08 10:00:00',
        files: [
          { id: 1, filename: 'Freegle-Statistics-Northshire.xlsx', size: 2048 },
        ],
      },
    ]

    global.fetch = vi.fn().mockResolvedValue({ ok: false, status: 403 })

    const wrapper = mountStats()
    await flushPromises()

    const link = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Freegle-Statistics'))
    await link.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Could not download')
    expect(wrapper.text()).toContain('403')
  })

  it('deletes a job', async () => {
    store.statsJobs = [
      {
        id: 5,
        authorityids: '10',
        quarter: '3 months ago',
        status: 'Ready',
        requested: '2026-08-08 10:00:00',
        files: [],
      },
    ]

    const wrapper = mountStats()
    await flushPromises()

    const deleteButton = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Delete')
    await deleteButton.trigger('click')

    expect(store.removeStatsJob).toHaveBeenCalledWith(5)
  })
})
