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
})

vi.mock('~/stores/partnerships', () => ({
  usePartnershipsStore: () => store,
}))

const mockAuthoritySearch = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    authority: { search: mockAuthoritySearch },
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
          template: '<div class="toggle" @click="$emit(\'change\')" />',
          props: [
            'value',
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
  agreed: true,
  visible: true,
  tagline: 'Reuse in Northshire',
  description: 'desc',
  linkurl: 'https://northshire.example.gov.uk',
  imageurl: '',
  contactname: 'Waste team',
  contactemail: 'waste@northshire.example.gov.uk',
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

describe('ModPartnershipEditModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuthoritySearch.mockResolvedValue([])
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

  it('saves an edit and closes', async () => {
    const wrapper = mountModal({ partnership: existing })

    await save(wrapper)

    expect(store.edit).toHaveBeenCalledWith(
      1,
      expect.objectContaining({
        name: 'Northshire Council',
        amount: 6000,
        tagline: 'Reuse in Northshire',
        linkurl: 'https://northshire.example.gov.uk',
      })
    )
    expect(wrapper.emitted('saved')).toBeTruthy()
    expect(mockHide).toHaveBeenCalled()
  })

  it('searches for a council and names the deal after the one picked', async () => {
    mockAuthoritySearch.mockResolvedValue([
      { id: 42, name: 'Southbury', area_code: 'UTA' },
    ])

    const wrapper = mountModal()
    await searchCouncils(wrapper, 'Southbury')

    expect(mockAuthoritySearch).toHaveBeenCalledWith('Southbury')

    const pick = wrapper
      .findAll('button')
      .find((b) => b.text().includes('Southbury'))
    await pick.trigger('click')
    await flushPromises()

    const values = wrapper.findAll('input').map((i) => i.element.value)
    expect(values).toContain('Southbury')
  })

  it('saves a new deal once a council has been picked', async () => {
    mockAuthoritySearch.mockResolvedValue([{ id: 42, name: 'Southbury' }])

    const wrapper = mountModal()
    await searchCouncils(wrapper, 'Southbury')

    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('Southbury'))
      .trigger('click')

    // Dates are required, so fill them in the way the form does.
    const inputs = wrapper.findAll('input')
    await inputs[2].setValue('2026-04-01')
    await inputs[3].setValue('2027-03-31')

    await save(wrapper)

    expect(store.add).toHaveBeenCalledWith(
      expect.objectContaining({ authorityid: 42, name: 'Southbury' })
    )
  })

  it('says so when no council matches', async () => {
    const wrapper = mountModal()
    await searchCouncils(wrapper, 'Nowhere')

    expect(wrapper.text()).toContain('No councils matched')
  })

  it('warns that an unagreed deal stays hidden from members', () => {
    const wrapper = mountModal({ partnership: { ...existing, agreed: false } })

    expect(wrapper.text()).toContain("members won't see the council")
  })

  it('surfaces a save failure rather than closing silently', async () => {
    store.edit.mockRejectedValueOnce(new Error('Server said no'))
    const wrapper = mountModal({ partnership: existing })

    await save(wrapper)

    expect(wrapper.text()).toContain('Server said no')
    expect(mockHide).not.toHaveBeenCalled()
  })
})
