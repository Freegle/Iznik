import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModAlertHistory from '~/modtools/components/ModAlertHistory.vue'

// datetimeshort is deliberately NOT mocked: the "Invalid Date" bug lived in how the real
// formatter handles a missing complete timestamp, so the tests below need the real thing.
const defaultAlert = {
  id: 1,
  created: '2024-01-01T10:00:00',
  complete: '2024-01-01T12:00:00',
  subject: 'Test Alert',
  group: {
    nameshort: 'Test Group',
  },
}

const mockAlertStore = {
  get: vi.fn((id) => (id === 1 ? defaultAlert : null)),
}

vi.mock('~/stores/alert', () => ({
  useAlertStore: () => mockAlertStore,
}))

describe('ModAlertHistory', () => {
  function mountComponent(props = {}, alert = null) {
    if (alert) {
      mockAlertStore.get.mockImplementation((id) => (id === 1 ? alert : null))
    }

    return mount(ModAlertHistory, {
      props: { alertid: 1, ...props },
      global: {
        stubs: {
          'b-row': { template: '<div class="row"><slot /></div>' },
          'b-col': { template: '<div class="col"><slot /></div>' },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
          },
          ModAlertHistoryDetailsModal: {
            template: '<div class="details-modal" />',
            methods: { show: vi.fn() },
          },
          ModAlertHistoryStatsModal: {
            template: '<div class="stats-modal" />',
            methods: { show: vi.fn() },
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockAlertStore.get.mockImplementation((id) =>
      id === 1 ? defaultAlert : null
    )
  })

  describe('rendering', () => {
    it('displays alert subject', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Test Alert')
    })

    it('displays group name', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Test Group')
    })

    it('has Show Stats and Show Details buttons', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Show Stats')
      expect(wrapper.text()).toContain('Show Details')
    })

    it('shows the completion time for a finished alert', () => {
      const wrapper = mountComponent(
        {},
        { ...defaultAlert, complete: '2024-01-01T12:00:00' }
      )
      expect(wrapper.text()).toContain('1st Jan, 2024 12:00')
      expect(wrapper.text()).not.toContain('In progress')
    })

    // The API serialises a NULL complete timestamp as an empty string, which used to reach
    // dayjs and render the literal "Invalid Date" in the history table.
    it.each([
      ['an empty string', ''],
      ['null', null],
      ['undefined', undefined],
      ['an unparseable value', 'not a date'],
    ])('shows In progress when complete is %s', (_label, complete) => {
      const wrapper = mountComponent({}, { ...defaultAlert, complete })
      expect(wrapper.text()).toContain('In progress')
      expect(wrapper.text()).not.toContain('Invalid Date')
    })
  })

  describe('data', () => {
    it('starts with showDetails false', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.showDetails).toBe(false)
    })

    it('starts with showStats false', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.showStats).toBe(false)
    })
  })

  describe('methods', () => {
    it('sets showDetails to true on details()', () => {
      const wrapper = mountComponent()
      wrapper.vm.details()
      expect(wrapper.vm.showDetails).toBe(true)
    })

    it('sets showStats to true on stats()', () => {
      const wrapper = mountComponent()
      wrapper.vm.stats()
      expect(wrapper.vm.showStats).toBe(true)
    })
  })
})
