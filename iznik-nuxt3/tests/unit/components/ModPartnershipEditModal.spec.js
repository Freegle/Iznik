import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, reactive } from 'vue'

import ModPartnershipEditModal from '~/modtools/components/ModPartnershipEditModal.vue'

const mockHide = vi.fn()
const mockModal = ref(null)

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: mockModal, hide: mockHide, show: vi.fn() }),
}))

const store = reactive({
  add: vi.fn(),
  edit: vi.fn(),
  fetchOne: vi.fn(),
})

vi.mock('~/stores/partnerships', () => ({
  usePartnershipsStore: () => store,
}))

const mockAuthoritySearch = vi.fn()
const mockAuthorityFetch = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    authority: { search: mockAuthoritySearch, fetch: mockAuthorityFetch },
  }),
}))

function mountModal(props = {}) {
  return mount(ModPartnershipEditModal, {
    props,
    global: {
      stubs: {
        'b-modal': {
          template:
            '<div><slot name="title" /><slot /><slot name="footer" /></div>',
        },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-input-group': { template: '<div><slot /></div>' },
        'b-form-group': {
          template: '<div><label>{{ label }}</label><slot /></div>',
          props: ['label', 'description'],
        },
        'b-form-input': {
          template:
            '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'type', 'placeholder', 'min'],
        },
        'b-form-textarea': {
          template:
            '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'rows'],
        },
        'b-form-select': {
          template:
            '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="String(o.value)" :value="o.value">{{ o.text }}</option></select>',
          props: ['modelValue', 'options'],
        },
        'b-form-checkbox': {
          template:
            '<label class="cb"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
          props: ['modelValue'],
        },
        'b-button': {
          template: '<button @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size'],
          emits: ['click'],
        },
        SpinButton: {
          template: '<button class="spin" @click="$emit(\'handle\')" />',
          props: ['variant', 'iconName', 'label', 'spinclass'],
        },
        OurToggle: {
          template:
            '<div class="toggle" @click="$emit(\'update:modelValue\', !modelValue)">{{ modelValue ? labels.checked : labels.unchecked }}</div>',
          props: [
            'modelValue',
            'labels',
            'variant',
            'height',
            'width',
            'fontSize',
            'sync',
          ],
        },
        NoticeMessage: {
          template: '<div class="notice"><slot /></div>',
          props: ['variant'],
        },
        OurUploader: {
          name: 'OurUploader',
          template: '<div class="uploader" />',
          props: ['modelValue', 'type'],
        },
        OurUploadedImage: {
          template: '<img class="uploaded" :src="src" />',
          props: ['src', 'alt', 'height', 'className'],
        },
        ModPartnershipGroupPicker: {
          template:
            '<button class="picker" @click="$emit(\'pick\', { id: 77, namedisplay: \'Blackpool\' })" />',
          props: ['exclude', 'label'],
          emits: ['pick'],
        },
        'v-icon': true,
      },
    },
  })
}

const existing = {
  id: 1,
  authorityid: 10,
  name: 'Northshire Council',
  startdate: '2026-04-01',
  enddate: '2027-03-31',
  amount: 6000,
  fullprice: null,
  status: 'Confirmed',
  renewal: 'Likely',
  visible: true,
  tagline: 'Reuse in Northshire',
  description: 'desc',
  linkurl: 'https://northshire.example.gov.uk',
  imageurl: '',
  notes: '',
}

/** Save is the last SpinButton on the form; a new deal also has a council-search one. */
async function save(wrapper) {
  const buttons = wrapper.findAll('button.spin')
  await buttons[buttons.length - 1].trigger('click')
  await flushPromises()
}

/** Search is the first SpinButton, and only exists while adding a new deal. */
async function searchCouncils(wrapper, term) {
  await wrapper.find('input').setValue(term)
  await wrapper.findAll('button.spin')[0].trigger('click')
  await flushPromises()
}

async function pickCouncil(wrapper, name) {
  await wrapper
    .findAll('button')
    .find((b) => b.text().includes(name))
    .trigger('click')
  await flushPromises()
}

describe('ModPartnershipEditModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuthoritySearch.mockResolvedValue([])
    mockAuthorityFetch.mockResolvedValue({ groups: [] })
    store.fetchOne.mockResolvedValue({ contacts: [] })
    store.add.mockResolvedValue(99)
  })

  it('titles itself for a new deal', () => {
    const wrapper = mountModal()

    expect(wrapper.text()).toContain('New partnership')
  })

  it('titles itself for an edit and prefills the fields', () => {
    const wrapper = mountModal({ partnership: existing })

    expect(wrapper.text()).toContain('Edit partnership')
    const values = wrapper.findAll('input').map((i) => i.element.value)
    expect(values).toContain('Northshire Council')
    expect(values).toContain('2026-04-01')
    expect(values).toContain('Reuse in Northshire')
  })

  it('does not ask when the deal was agreed', () => {
    expect(mountModal({ partnership: existing }).text()).not.toContain(
      'Agreed on'
    )
  })

  it('only offers the council picker for a new deal', () => {
    // A new deal has the council-search button as well as Save; an edit has only Save,
    // because moving a deal to a different council is not something to do by accident.
    expect(mountModal().findAll('button.spin')).toHaveLength(2)
    expect(
      mountModal({ partnership: existing }).findAll('button.spin')
    ).toHaveLength(1)
  })

  it('refuses to save a new deal with no council', async () => {
    const wrapper = mountModal()

    await save(wrapper)

    expect(wrapper.text()).toContain('Please choose a council')
    expect(store.add).not.toHaveBeenCalled()
  })

  it('refuses to save without both dates', async () => {
    const wrapper = mountModal({ partnership: { ...existing, enddate: '' } })

    await save(wrapper)

    expect(wrapper.text()).toContain('give both a start and an end date')
    expect(store.edit).not.toHaveBeenCalled()
  })

  it('refuses an end date before the start date', async () => {
    const wrapper = mountModal({
      partnership: {
        ...existing,
        startdate: '2027-04-01',
        enddate: '2026-03-31',
      },
    })

    await save(wrapper)

    expect(wrapper.text()).toContain("end date can't be before the start date")
    expect(store.edit).not.toHaveBeenCalled()
  })

  it('says how long the deal lasts', () => {
    const wrapper = mountModal({
      partnership: {
        ...existing,
        startdate: '2025-01-01',
        enddate: '2027-12-31',
      },
    })

    expect(wrapper.text()).toContain('This deal lasts 3 years.')
  })

  it('shows the bulk discount worked out from the full price', () => {
    const wrapper = mountModal({
      partnership: { ...existing, amount: 2700, fullprice: 3000 },
    })

    expect(wrapper.text()).toContain('Bulk discount: 10% (£300)')
  })

  it('saves an edit with its status, renewal and contacts, and closes', async () => {
    store.fetchOne.mockResolvedValue({
      contacts: [
        { id: 5, name: 'Wendy', email: 'w@example.gov.uk', role: 'Waste' },
      ],
    })
    const wrapper = mountModal({ partnership: existing })
    await flushPromises()

    await save(wrapper)

    expect(store.edit).toHaveBeenCalledWith(
      1,
      expect.objectContaining({
        name: 'Northshire Council',
        amount: 6000,
        status: 'Confirmed',
        renewal: 'Likely',
        tagline: 'Reuse in Northshire',
        linkurl: 'https://northshire.example.gov.uk',
        contacts: [{ name: 'Wendy', email: 'w@example.gov.uk', role: 'Waste' }],
      })
    )
    expect(wrapper.emitted('saved')[0]).toEqual([1])
    expect(mockHide).toHaveBeenCalled()
  })

  it('drops blank contact lines and can add another contact', async () => {
    const wrapper = mountModal({ partnership: existing })
    await flushPromises()

    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('Add another contact'))
      .trigger('click')

    await save(wrapper)

    expect(store.edit.mock.calls[0][1].contacts).toEqual([])
  })

  it('offers every stage of the pipeline as a status', () => {
    const wrapper = mountModal({ partnership: existing })
    const options = wrapper.findAll('option').map((o) => o.text())

    expect(options).toEqual(
      expect.arrayContaining([
        'Quoted',
        'Agreed in principle',
        'Confirmed',
        'Paid',
        'Overdue',
        'Likely to renew',
        'Might renew',
        'Unlikely to renew',
        'Waste team',
        'Finance',
        'Other',
      ])
    )
  })

  it('warns that a deal not yet confirmed is not shown to members', () => {
    const wrapper = mountModal({
      partnership: { ...existing, status: 'InPrinciple' },
    })

    expect(wrapper.text()).toContain('once the deal is confirmed')
  })

  it('warns that a hidden deal is not shown to members', () => {
    const wrapper = mountModal({
      partnership: { ...existing, visible: false },
    })

    expect(wrapper.text()).toContain("members won't see the council")
  })

  // The toggle once took :value, which it ignores, so it read "Hide from members" on a deal
  // that was being shown.
  it('shows whether the deal is shown to members, and changes it', async () => {
    const wrapper = mountModal({ partnership: existing })
    expect(wrapper.find('.toggle').text()).toBe('Show to members')

    await wrapper.find('.toggle').trigger('click')
    expect(wrapper.find('.toggle').text()).toBe('Hide from members')

    await save(wrapper)
    expect(store.edit.mock.calls[0][1].visible).toBe(false)
  })

  it('says nothing about visibility for a confirmed, shown deal', () => {
    expect(mountModal({ partnership: existing }).find('.notice').exists()).toBe(
      false
    )
  })

  it('only offers councils, not wards or constituencies', async () => {
    mockAuthoritySearch.mockResolvedValue([
      { id: 42, name: 'Southbury', area_code: 'Unitary Authority' },
      {
        id: 43,
        name: 'Southbury Central',
        area_code: 'Unitary Authority Ward',
      },
    ])

    const wrapper = mountModal()
    await searchCouncils(wrapper, 'Southbury')

    const buttons = wrapper.findAll('button').map((b) => b.text())
    expect(buttons.some((t) => t.includes('Southbury Central'))).toBe(false)
    expect(buttons.some((t) => t.includes('Southbury'))).toBe(true)
  })

  it('shows the chosen council plainly and the communities it covers', async () => {
    mockAuthoritySearch.mockResolvedValue([
      { id: 42, name: 'Southbury', area_code: 'County Council' },
    ])
    mockAuthorityFetch.mockResolvedValue({
      groups: [
        { id: 1, namedisplay: 'Half In', overlap: 0.21 },
        { id: 2, namedisplay: 'All In', overlap: 1 },
      ],
    })

    const wrapper = mountModal()
    await searchCouncils(wrapper, 'Southbury')
    await pickCouncil(wrapper, 'Southbury')

    expect(mockAuthorityFetch).toHaveBeenCalledWith(42)
    expect(wrapper.find('.chosen-council').text()).toContain('Southbury')
    expect(wrapper.find('.chosen-council').text()).toContain('County Council')

    // Most-inside first, each with its share.
    const rows = wrapper.findAll('label.cb').map((l) => l.text())
    expect(rows[0]).toContain('All In')
    expect(rows[0]).toContain('100%')
    expect(rows[1]).toContain('Half In')
    expect(rows[1]).toContain('21%')

    const values = wrapper.findAll('input').map((i) => i.element.value)
    expect(values).toContain('Southbury')
  })

  it('saves a new deal with the communities left out and added', async () => {
    mockAuthoritySearch.mockResolvedValue([
      { id: 42, name: 'Southbury', area_code: 'County Council' },
    ])
    mockAuthorityFetch.mockResolvedValue({
      groups: [
        { id: 1, namedisplay: 'Southend', overlap: 0.06 },
        { id: 2, namedisplay: 'Chelmsford', overlap: 1 },
      ],
    })

    const wrapper = mountModal()
    await searchCouncils(wrapper, 'Southbury')
    await pickCouncil(wrapper, 'Southbury')

    // Leave out Southend, add Blackpool from outside the boundary.
    const southend = wrapper
      .findAll('label.cb')
      .find((l) => l.text().includes('Southend'))
    await southend.find('input').setValue(false)
    await wrapper.find('button.picker').trigger('click')
    expect(wrapper.text()).toContain('Blackpool')

    const dateInputs = wrapper
      .findAll('input')
      .filter((i) => i.attributes('type') !== 'checkbox')
    // Name is the first text input after the council; the two dates follow it.
    await dateInputs[1].setValue('2026-04-01')
    await dateInputs[2].setValue('2027-03-31')

    await save(wrapper)

    expect(store.add).toHaveBeenCalledWith(
      expect.objectContaining({
        authorityid: 42,
        name: 'Southbury',
        status: 'Quoted',
        excludegroupids: [1],
        includegroupids: [77],
      })
    )
    expect(wrapper.emitted('saved')[0]).toEqual([99])
  })

  it('lets the council be changed after picking one', async () => {
    mockAuthoritySearch.mockResolvedValue([
      { id: 42, name: 'Southbury', area_code: 'County Council' },
    ])

    const wrapper = mountModal()
    await searchCouncils(wrapper, 'Southbury')
    await pickCouncil(wrapper, 'Southbury')

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Change')
      .trigger('click')

    expect(wrapper.find('.chosen-council').exists()).toBe(false)
  })

  it('says so when no council matches', async () => {
    const wrapper = mountModal()
    await searchCouncils(wrapper, 'Nowhere')

    expect(wrapper.text()).toContain('No councils matched')
  })

  it('sends an uploaded logo by its image id', async () => {
    const wrapper = mountModal({ partnership: existing })
    await flushPromises()

    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('Upload logo'))
      .trigger('click')
    const uploader = wrapper.findComponent({ name: 'OurUploader' })
    await uploader.vm.$emit('update:modelValue', [
      { id: 314, ouruid: 'freegletusd-abc' },
    ])
    await flushPromises()

    expect(wrapper.find('img.uploaded').attributes('src')).toBe(
      'freegletusd-abc'
    )

    await save(wrapper)

    expect(store.edit.mock.calls[0][1].imageid).toBe(314)
  })

  it('can remove the logo', async () => {
    const wrapper = mountModal({
      partnership: { ...existing, imageurl: 'https://example.com/logo.png' },
    })
    await flushPromises()

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Remove')
      .trigger('click')
    await save(wrapper)

    expect(store.edit.mock.calls[0][1].imageurl).toBe('')
  })

  it('surfaces a save failure rather than closing silently', async () => {
    store.edit.mockRejectedValueOnce(new Error('Server said no'))
    const wrapper = mountModal({ partnership: existing })

    await save(wrapper)

    expect(wrapper.text()).toContain('Server said no')
    expect(mockHide).not.toHaveBeenCalled()
  })
})
