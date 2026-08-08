import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, reactive } from 'vue'

import PartnershipsPage from '~/modtools/pages/partnerships.vue'

const me = ref({ id: 1, teams: ['Partnerships'] })
const supportOrAdmin = ref(false)

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me, supportOrAdmin }),
}))

vi.mock('~/composables/useMTBuildHead', () => ({
  buildHead: () => ({}),
}))

const store = reactive({
  list: [],
  summary: null,
  expiring: [],
  statsJobs: [],
  statsRunning: false,
  refresh: vi.fn(),
  remove: vi.fn(),
  fetchStatsJobs: vi.fn(),
  addStatsJob: vi.fn(),
  removeStatsJob: vi.fn(),
})

vi.mock('~/stores/partnerships', () => ({
  usePartnershipsStore: () => store,
}))

const partnership = (overrides = {}) => ({
  id: 1,
  name: 'Northshire Council',
  authorityid: 10,
  authorityname: 'Northshire',
  startdate: '2026-04-01',
  enddate: '2027-03-31',
  amount: 6000,
  paid: 3000,
  groupcount: 4,
  agreed: true,
  visible: true,
  expiring: false,
  expired: false,
  ...overrides,
})

function mountPage() {
  return mount(PartnershipsPage, {
    global: {
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        'b-badge': {
          template: '<span class="badge"><slot /></span>',
          props: ['variant'],
        },
        // emits must be declared, or the parent's @click also falls through to the root
        // <button> as a native listener and every click fires the handler twice.
        'b-button': {
          template: '<button @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size'],
          emits: ['click'],
        },
        NoticeMessage: {
          name: 'NoticeMessage',
          template: '<div class="notice"><slot /></div>',
          props: ['variant'],
        },
        GChart: {
          name: 'GChart',
          template: '<div class="gchart" />',
          props: ['data', 'options', 'type'],
        },
        ModPartnershipTotal: {
          name: 'ModPartnershipTotal',
          template: '<div class="total">{{ label }}:{{ value }}</div>',
          props: ['label', 'value', 'money', 'variant'],
        },
        ModPartnershipDetail: {
          name: 'ModPartnershipDetail',
          template: '<div class="detail" />',
          props: ['id'],
        },
        ModPartnershipStats: {
          name: 'ModPartnershipStats',
          template: '<div class="stats" />',
          props: ['partnerships'],
        },
        ModPartnershipEditModal: {
          name: 'ModPartnershipEditModal',
          template: '<div class="editmodal" />',
          props: ['partnership'],
        },
        ConfirmModal: {
          name: 'ConfirmModal',
          template: '<div class="confirm" />',
          props: ['title', 'message'],
        },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

describe('modtools partnerships page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    me.value = { id: 1, teams: ['Partnerships'] }
    supportOrAdmin.value = false
    store.list = []
    store.summary = null
    store.expiring = []
  })

  it('turns away someone not on the team', async () => {
    me.value = { id: 1, teams: ['ChitChat Moderation'] }

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('need to be on the Partnerships team')
    expect(store.refresh).not.toHaveBeenCalled()
  })

  it('lets Support in without team membership', async () => {
    me.value = { id: 1 }
    supportOrAdmin.value = true

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).not.toContain('need to be on the Partnerships team')
    expect(store.refresh).toHaveBeenCalled()
  })

  it('loads the deals and totals for a team member', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(store.refresh).toHaveBeenCalled()
    expect(wrapper.text()).toContain('No partnerships yet')
  })

  it('shows the headline totals', async () => {
    store.summary = {
      total: 10000,
      agreed: 6000,
      invoiced: 5000,
      paid: 3000,
      outstanding: 2000,
      active: 2,
      years: [],
    }

    const wrapper = mountPage()
    await flushPromises()

    const totals = wrapper.findAll('.total').map((t) => t.text())
    expect(totals).toContain('Agreed income:6000')
    // Anything not yet agreed is money we hope for, not money we have.
    expect(totals).toContain('In discussion:4000')
    expect(totals).toContain('Outstanding:2000')
  })

  it('lists a deal with its council, term and money', async () => {
    store.list = [partnership()]

    const wrapper = mountPage()
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('Northshire Council')
    expect(text).toContain('2026-04-01 to 2027-03-31')
    expect(text).toContain('£6,000')
    expect(text).toContain('£3,000')
  })

  it('marks a deal that has not been agreed', async () => {
    store.list = [partnership({ agreed: false })]

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('In discussion')
  })

  it('marks a deal that has ended', async () => {
    store.list = [partnership({ expired: true })]

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Ended')
  })

  it('marks a deal that needs renewing', async () => {
    store.list = [partnership({ expiring: true })]

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Renewal due')
  })

  it('marks a deal hidden from members', async () => {
    store.list = [partnership({ visible: false })]

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Hidden')
  })

  it('calls out the renewals coming up', async () => {
    store.expiring = [partnership({ expiring: true })]

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('sponsorship runs')
    expect(wrapper.text()).toContain('Northshire Council (2027-03-31)')
  })

  it('draws the income graph split by financial year', async () => {
    store.summary = {
      total: 9000,
      agreed: 9000,
      invoiced: 0,
      paid: 0,
      outstanding: 0,
      active: 1,
      years: [
        { financialyear: 2026, label: '2026/27', agreed: 3000, pipeline: 0 },
        { financialyear: 2027, label: '2027/28', agreed: 3000, pipeline: 500 },
      ],
    }

    const wrapper = mountPage()
    await flushPromises()

    const chart = wrapper.findComponent({ name: 'GChart' })
    expect(chart.exists()).toBe(true)
    expect(chart.props('data')).toEqual([
      ['Financial year', 'Agreed', 'In discussion'],
      ['2026/27', 3000, 0],
      ['2027/28', 3000, 500],
    ])
  })

  it('leaves the graph out when there is nothing to draw', async () => {
    store.summary = {
      total: 0,
      agreed: 0,
      invoiced: 0,
      paid: 0,
      outstanding: 0,
      active: 0,
      years: [],
    }

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.findComponent({ name: 'GChart' }).exists()).toBe(false)
  })

  it('opens the details for one deal at a time', async () => {
    store.list = [partnership()]

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('.detail').exists()).toBe(false)

    const details = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Details')
    await details.trigger('click')

    expect(wrapper.find('.detail').exists()).toBe(true)

    const hide = wrapper.findAll('button').find((b) => b.text() === 'Hide')
    await hide.trigger('click')

    expect(wrapper.find('.detail').exists()).toBe(false)
  })

  it('opens the editor for a new deal', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('.editmodal').exists()).toBe(false)

    const add = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Add partnership'))
    await add.trigger('click')

    expect(wrapper.find('.editmodal').exists()).toBe(true)
    expect(
      wrapper
        .findComponent({ name: 'ModPartnershipEditModal' })
        .props('partnership')
    ).toBeNull()
  })

  it('opens the editor on an existing deal', async () => {
    store.list = [partnership()]

    const wrapper = mountPage()
    await flushPromises()

    const edit = wrapper.findAll('button').find((b) => b.text() === 'Edit')
    await edit.trigger('click')

    expect(
      wrapper
        .findComponent({ name: 'ModPartnershipEditModal' })
        .props('partnership').id
    ).toBe(1)
  })

  it('asks before deleting, because it pulls the sponsor off the site', async () => {
    store.list = [partnership()]

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('.confirm').exists()).toBe(false)

    const del = wrapper.findAll('button').find((b) => b.text() === 'Delete')
    await del.trigger('click')

    expect(wrapper.find('.confirm').exists()).toBe(true)
    expect(store.remove).not.toHaveBeenCalled()

    await wrapper.findComponent({ name: 'ConfirmModal' }).vm.$emit('confirm')
    await flushPromises()

    expect(store.remove).toHaveBeenCalledWith(1)
  })
})
