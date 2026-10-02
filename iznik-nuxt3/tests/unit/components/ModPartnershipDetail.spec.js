import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

import ModPartnershipDetail from '~/modtools/components/ModPartnershipDetail.vue'

const detail = {}

const store = reactive({
  byId: (id) => detail[id] || null,
  fetchOne: vi.fn(),
  addGroup: vi.fn(),
  removeGroup: vi.fn(),
  redetectGroups: vi.fn(),
  setYears: vi.fn(),
  addPayment: vi.fn(),
  editPayment: vi.fn(),
  removePayment: vi.fn(),
})

vi.mock('~/stores/partnerships', () => ({
  usePartnershipsStore: () => store,
}))

function setDetail(overrides = {}) {
  detail[1] = {
    partnership: {
      id: 1,
      authorityid: 10,
      name: 'Northshire Council',
      amount: 9000,
      paid: 4500,
      fullprice: null,
      status: 'Confirmed',
      renewal: 'Unsure',
      startdate: '2026-04-01',
      enddate: '2028-03-31',
    },
    contacts: [],
    history: [],
    groups: [
      {
        groupid: 100,
        nameshort: 'northshire',
        namedisplay: 'Northshire Freegle',
        source: 'Boundary',
        overlap: 1,
      },
    ],
    years: [
      { financialyear: 2026, label: '2026/27', amount: 4500 },
      { financialyear: 2027, label: '2027/28', amount: 4500 },
    ],
    payments: [],
    explicityears: false,
    ...overrides,
  }
  return detail[1]
}

function mountDetail() {
  return mount(ModPartnershipDetail, {
    props: { id: 1 },
    global: {
      stubs: {
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-button': {
          template: '<button @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size'],
          emits: ['click'],
        },
        'b-form-input': {
          template:
            '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'type', 'size', 'min'],
        },
        SpinButton: {
          template:
            '<button class="spin" :data-label="label" :disabled="disabled" @click="$emit(\'handle\')" />',
          props: [
            'variant',
            'iconName',
            'label',
            'size',
            'spinclass',
            'disabled',
          ],
        },
        NoticeMessage: {
          template: '<div class="notice"><slot /></div>',
          props: ['variant'],
        },
        ExternalLink: {
          template: '<a class="ext" :href="href"><slot /></a>',
          props: ['href'],
        },
        ModPartnershipGroupPicker: {
          template:
            '<button class="picker" @click="$emit(\'pick\', { id: 300, namedisplay: \'Blackpool\' })" />',
          props: ['exclude', 'label'],
          emits: ['pick'],
        },
        ModPartnershipRenewal: {
          template: '<span class="renewal">{{ renewal }}</span>',
          props: ['renewal', 'showText'],
        },
        'b-badge': {
          template: '<span class="badge"><slot /></span>',
          props: ['variant'],
        },
        ConfirmModal: {
          template:
            '<div class="confirm">{{ title }} <button class="yes" @click="$emit(\'confirm\')">Confirm</button></div>',
          props: ['title', 'message'],
          emits: ['confirm', 'hidden'],
        },
      },
    },
  })
}

function spin(wrapper, label) {
  return wrapper
    .findAll('button.spin')
    .find((b) => b.attributes('data-label') === label)
}

describe('ModPartnershipDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    const d = setDetail()
    store.fetchOne.mockResolvedValue(d)
  })

  it('loads the deal when it opens', async () => {
    mountDetail()
    await flushPromises()

    expect(store.fetchOne).toHaveBeenCalledWith(1)
  })

  it('lists the communities covered, linked to their Explore page, with their share', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    const link = wrapper.find('a.ext')
    expect(link.text()).toBe('Northshire Freegle')
    expect(link.attributes('href')).toMatch(/\/explore\/northshire$/)
    expect(wrapper.text()).toContain('100% inside')
  })

  it('marks a community added by hand', async () => {
    store.fetchOne.mockResolvedValue(
      setDetail({
        groups: [
          {
            groupid: 300,
            nameshort: 'blackpool',
            namedisplay: 'Blackpool',
            source: 'Added',
            overlap: null,
          },
        ],
      })
    )
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('added by hand')
  })

  it('shows the deal length, money received against due, and renewal', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('2 years')
    expect(wrapper.text()).toContain('£4,500')
    expect(wrapper.text()).toContain('of £9,000 due')
    expect(wrapper.find('.renewal').text()).toBe('Unsure')
    // Three months before the end.
    expect(wrapper.text()).toContain('31 Dec 2027')
  })

  it('shows the bulk discount when there was one', async () => {
    store.fetchOne.mockResolvedValue(
      setDetail({
        partnership: { ...setDetail().partnership, fullprice: 10000 },
      })
    )
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('10% (£1,000)')
  })

  it('lists the council contacts', async () => {
    store.fetchOne.mockResolvedValue(
      setDetail({
        contacts: [
          { id: 1, name: 'Fred', email: 'f@example.gov.uk', role: 'Finance' },
        ],
      })
    )
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('Fred')
    expect(wrapper.text()).toContain('f@example.gov.uk')
    expect(wrapper.text()).toContain('(Finance)')
  })

  it('lists every deal with the council', async () => {
    store.fetchOne.mockResolvedValue(
      setDetail({
        history: [
          {
            id: 1,
            startdate: '2026-04-01',
            enddate: '2028-03-31',
            amount: 9000,
            status: 'Confirmed',
          },
          {
            id: 7,
            startdate: '2022-06-30',
            enddate: '2023-06-29',
            amount: 300,
            status: 'Paid',
          },
        ],
      })
    )
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('History with this council')
    expect(wrapper.text()).toContain('30 Jun 2022')
    expect(wrapper.text()).toContain('£300')
  })

  it('warns when the deal covers nothing, so nothing shows to members', async () => {
    const d = setDetail({ groups: [] })
    store.fetchOne.mockResolvedValue(d)

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('No communities are covered')
  })

  it('offers back communities inside the boundary that were left out', async () => {
    const d = setDetail()
    d.groups.push({
      groupid: 200,
      nameshort: 'eastborough',
      namedisplay: 'Eastborough Freegle',
      source: 'Removed',
      overlap: 0.4,
    })
    store.fetchOne.mockResolvedValue(d)

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('left out')
    expect(wrapper.findAll('a.ext')).toHaveLength(1)

    const add = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Eastborough'))
    await add.trigger('click')

    expect(store.addGroup).toHaveBeenCalledWith(1, 200)
  })

  it('removes a community from the deal', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    const remove = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Leave out')
    await remove.trigger('click')

    expect(store.removeGroup).toHaveBeenCalledWith(1, 100)
  })

  it('adds a community from outside the boundary', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    await wrapper.find('button.picker').trigger('click')

    expect(store.addGroup).toHaveBeenCalledWith(1, 300)
  })

  it('re-checks the boundary on demand', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    await spin(wrapper, 'Re-check the boundary').trigger('click')

    expect(store.redetectGroups).toHaveBeenCalledWith(1)
  })

  it('says the split was worked out when none has been agreed', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('Spread evenly across the term')
  })

  it('says the split was agreed when it was', async () => {
    const d = setDetail({ explicityears: true })
    store.fetchOne.mockResolvedValue(d)

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('Split as agreed with the council')
  })

  it('flags a split that does not add up to the deal value', async () => {
    const d = setDetail({
      years: [
        { financialyear: 2026, label: '2026/27', amount: 1000 },
        { financialyear: 2027, label: '2027/28', amount: 1000 },
      ],
    })
    store.fetchOne.mockResolvedValue(d)

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain("These don't match")
  })

  it('does not flag a split that adds up', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).not.toContain("These don't match")
  })

  it('saves the year-by-year split', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    await spin(wrapper, 'Save split').trigger('click')
    await flushPromises()

    expect(store.setYears).toHaveBeenCalledWith(1, [
      { financialyear: 2026, amount: 4500 },
      { financialyear: 2027, amount: 4500 },
    ])
  })

  it('can drop back to spreading the money evenly', async () => {
    const d = setDetail({ explicityears: true })
    store.fetchOne.mockResolvedValue(d)

    const wrapper = mountDetail()
    await flushPromises()

    await spin(wrapper, 'Spread evenly').trigger('click')
    await flushPromises()

    expect(store.setYears).toHaveBeenCalledWith(1, [])
  })

  it('hides the reset when no split has been agreed', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(spin(wrapper, 'Spread evenly')).toBeUndefined()
  })

  it('says when nothing has been invoiced', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('Nothing invoiced yet')
  })

  it('lists invoices and lets an unpaid one be marked paid', async () => {
    const d = setDetail({
      payments: [
        {
          id: 5,
          date: '2026-04-15',
          amount: 4500,
          paid: null,
          reference: 'INV-1',
        },
      ],
    })
    store.fetchOne.mockResolvedValue(d)

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('INV-1')
    expect(wrapper.text()).toContain('£4,500.00')

    const mark = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Mark paid today')
    await mark.trigger('click')

    expect(store.editPayment).toHaveBeenCalledWith(
      1,
      5,
      expect.objectContaining({ paid: expect.any(String) })
    )
  })

  it('shows the date a paid invoice was settled', async () => {
    const d = setDetail({
      payments: [
        {
          id: 5,
          date: '2026-04-15',
          amount: 4500,
          paid: '2026-05-01',
          reference: '',
        },
      ],
    })
    store.fetchOne.mockResolvedValue(d)

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('2026-05-01')
    expect(wrapper.text()).not.toContain('Mark paid today')
  })

  it('deletes an invoice', async () => {
    const d = setDetail({
      payments: [
        { id: 5, date: '2026-04-15', amount: 4500, paid: null, reference: '' },
      ],
    })
    store.fetchOne.mockResolvedValue(d)

    const wrapper = mountDetail()
    await flushPromises()

    const del = wrapper.findAll('button').find((b) => b.text() === 'Delete')
    await del.trigger('click')

    // Asks first.
    expect(store.removePayment).not.toHaveBeenCalled()
    expect(wrapper.find('.confirm').text()).toContain('Delete this invoice?')

    await wrapper.find('button.yes').trigger('click')
    await flushPromises()

    expect(store.removePayment).toHaveBeenCalledWith(1, 5)
  })

  it('will not add an invoice without a date', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(spin(wrapper, 'Add').attributes('disabled')).toBeDefined()
  })
})
