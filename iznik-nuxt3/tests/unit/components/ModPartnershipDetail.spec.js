import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

import ModPartnershipDetail from '~/modtools/components/ModPartnershipDetail.vue'

const detail = {}

const store = reactive({
  byId: (id) => detail[id] || null,
  fetchOne: vi.fn(),
  fetchGroups: vi.fn(),
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
    partnership: { id: 1, name: 'Northshire Council', amount: 9000 },
    groups: [
      {
        groupid: 100,
        nameshort: 'northshire',
        namedisplay: 'Northshire Freegle',
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
    store.fetchGroups.mockResolvedValue({
      groups: d.groups,
      available: d.groups,
    })
  })

  it('loads the deal and the council boundary when it opens', async () => {
    mountDetail()
    await flushPromises()

    expect(store.fetchOne).toHaveBeenCalledWith(1)
    expect(store.fetchGroups).toHaveBeenCalledWith(1)
  })

  it('lists the communities covered', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('Northshire Freegle')
  })

  it('warns when the deal covers nothing, so nothing shows to members', async () => {
    const d = setDetail({ groups: [] })
    store.fetchOne.mockResolvedValue(d)
    store.fetchGroups.mockResolvedValue({ groups: [], available: [] })

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('No communities are covered')
  })

  it('offers groups inside the boundary that the deal has dropped', async () => {
    const d = setDetail()
    store.fetchOne.mockResolvedValue(d)
    store.fetchGroups.mockResolvedValue({
      groups: d.groups,
      available: [
        ...d.groups,
        {
          groupid: 200,
          nameshort: 'eastborough',
          namedisplay: 'Eastborough Freegle',
        },
      ],
    })

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.text()).toContain('Eastborough Freegle')

    const add = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Eastborough'))
    await add.trigger('click')

    expect(store.addGroup).toHaveBeenCalledWith(1, 200)
  })

  it('removes a community from the deal', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    const remove = wrapper.findAll('button').find((b) => b.text() === 'Remove')
    await remove.trigger('click')

    expect(store.removeGroup).toHaveBeenCalledWith(1, 100)
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

    expect(store.removePayment).toHaveBeenCalledWith(1, 5)
  })

  it('will not add an invoice without a date', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(spin(wrapper, 'Add').attributes('disabled')).toBeDefined()
  })
})
