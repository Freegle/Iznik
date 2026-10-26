import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import OutcomeModal from '~/components/OutcomeModal.vue'
import { flowCalls, resetFlowCalls } from '../helpers/flowTelemetryMock'

vi.mock('~/composables/useFlowTelemetry', async () => {
  const m = await import('../helpers/flowTelemetryMock')
  return m.flowTelemetryModule
})

const { mockModal, mockHide } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockModal: ref(null),
    mockHide: vi.fn(),
  }
})

const mockById = vi.fn()
const mockUpdate = vi.fn()
const mockFetch = vi.fn()
const mockAddBy = vi.fn()
const mockRemoveBy = vi.fn()
const mockBusEmit = vi.fn()

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: mockModal,
    hide: mockHide,
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    byId: mockById,
    update: mockUpdate,
    fetch: mockFetch,
    addBy: mockAddBy,
    removeBy: mockRemoveBy,
  }),
}))

vi.stubGlobal('useNuxtApp', () => ({
  $bus: { $emit: mockBusEmit },
}))

describe('OutcomeModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetFlowCalls()
    mockById.mockReturnValue({
      id: 123,
      subject: 'Test Item',
      type: 'Offer',
      availablenow: 1,
      availableinitially: 1,
      groups: [{ groupid: 456 }],
      replies: [],
    })
  })

  function createWrapper(props = {}) {
    return mount(OutcomeModal, {
      props: {
        id: 123,
        type: 'Taken',
        ...props,
      },
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="b-modal"><slot name="title" /><slot /><slot name="footer" /></div>',
            props: ['scrollable', 'size', 'noStacking', 'dialogClass'],
          },
          'b-badge': {
            template: '<span class="b-badge" :class="variant"><slot /></span>',
            props: ['variant'],
          },
          'b-button': {
            template:
              '<button :class="variant" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
            props: ['variant', 'size', 'disabled', 'pressed'],
          },
          'b-button-group': {
            template: '<div class="btn-group"><slot /></div>',
          },
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'rows', 'maxRows'],
          },
          NoticeMessage: {
            template:
              '<div class="notice-message" :class="variant"><slot /></div>',
            props: ['variant'],
          },
          OutcomeBy: {
            template: '<div class="outcome-by" />',
            props: [
              'availablenow',
              'type',
              'msgid',
              'left',
              'takenBy',
              'chooseError',
              'invalid',
            ],
          },
          SpinButton: {
            template:
              '<button class="spin-button" :disabled="disabled" @click="$emit(\'handle\', () => {})"><slot />{{ label }}</button>',
            props: ['variant', 'iconName', 'label', 'disabled'],
          },
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon', 'scale', 'color'],
          },
        },
        mocks: {
          $bus: {
            $emit: mockBusEmit,
          },
        },
        provide: {
          useNuxtApp: () => ({
            $bus: { $emit: mockBusEmit },
          }),
        },
      },
    })
  }

  describe('flow telemetry', () => {
    it('starts the chat_outcome flow, entered by outcome type, when it opens', () => {
      createWrapper({ type: 'Received' })
      expect(flowCalls.slice(0, 2)).toEqual([
        ['start', 'Received'],
        ['enterStep', 'choose'],
      ])
    })

    it('reports Taken with nobody chosen as a validation failure', async () => {
      const wrapper = createWrapper({ type: 'Taken' })
      await wrapper.find('.spin-button').trigger('click')
      expect(flowCalls).toContainEqual(['validationFailed', 'taker', 'missing'])
      expect(flowCalls.map((c) => c[0])).not.toContain('submit')
    })

    it('sends submit, an ok result and complete for a withdrawal', async () => {
      mockUpdate.mockResolvedValue()
      mockFetch.mockResolvedValue()
      const wrapper = createWrapper({ type: 'Withdrawn' })
      flowCalls.length = 0
      await wrapper.vm.submit(() => {})
      expect(flowCalls.map((c) => c[0])).toEqual([
        'submit',
        'submitResult',
        'completeStep',
        'complete',
      ])
      expect(flowCalls[1]).toEqual(['submitResult', true, 200, null])
    })

    it('sends a failed result when the outcome update throws', async () => {
      mockUpdate.mockRejectedValueOnce(
        Object.assign(new Error('nope'), { response: { status: 500 } })
      )
      const wrapper = createWrapper({ type: 'Withdrawn' })
      flowCalls.length = 0
      await expect(wrapper.vm.submit(() => {})).rejects.toThrow('nope')
      expect(flowCalls).toContainEqual(['submitResult', false, 500, 'error'])
    })

    it('abandons as closed when hidden without an outcome', () => {
      const wrapper = createWrapper({ type: 'Taken' })
      flowCalls.length = 0
      wrapper.vm.onHide()
      expect(flowCalls).toEqual([['abandon', 'closed']])
    })
  })

  describe('rendering', () => {
    it('renders modal container', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-modal').exists()).toBe(true)
    })

    it('displays message subject in title', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Test Item')
    })

    it('renders OutcomeBy component for Taken type', () => {
      const wrapper = createWrapper({ type: 'Taken' })
      expect(wrapper.find('.outcome-by').exists()).toBe(true)
    })

    it('shows withdrawal notice for Withdrawn type', () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })
      expect(wrapper.find('.notice-message').exists()).toBe(true)
      expect(wrapper.text()).toContain('Mark as')
    })
  })

  describe('message display', () => {
    it('shows TAKEN for Offer messages', () => {
      mockById.mockReturnValue({
        id: 123,
        subject: 'Test Item',
        type: 'Offer',
        availablenow: 1,
        availableinitially: 1,
        groups: [{ groupid: 456 }],
        replies: [],
      })
      const wrapper = createWrapper({ type: 'Withdrawn' })
      expect(wrapper.text()).toContain('TAKEN')
    })

    it('shows RECEIVED for Wanted messages', () => {
      mockById.mockReturnValue({
        id: 123,
        subject: 'Test Item',
        type: 'Wanted',
        availablenow: 1,
        availableinitially: 1,
        groups: [{ groupid: 456 }],
        replies: [],
      })
      const wrapper = createWrapper({ type: 'Withdrawn' })
      expect(wrapper.text()).toContain('RECEIVED')
    })

    it('shows available count badge when more than 1 available', () => {
      mockById.mockReturnValue({
        id: 123,
        subject: 'Test Item',
        type: 'Offer',
        availablenow: 3,
        availableinitially: 3,
        groups: [{ groupid: 456 }],
        replies: [],
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.b-badge').exists()).toBe(true)
      expect(wrapper.text()).toContain('3 available')
    })

    it('says part gone rather than a number once giving has started', () => {
      mockById.mockReturnValue({
        id: 123,
        subject: 'Test Item',
        type: 'Offer',
        availablenow: 2,
        availableinitially: 5,
        partgone: true,
        groups: [{ groupid: 456 }],
        replies: [],
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.b-badge').text()).toContain(
        'Part gone, some still available'
      )
    })
  })

  describe('happiness selection', () => {
    it('renders happiness buttons when completion is shown', () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })
      expect(wrapper.text()).toContain('Happy')
      expect(wrapper.text()).toContain('Fine')
      expect(wrapper.text()).toContain('Sad')
    })

    it('renders happiness icons', () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })
      expect(wrapper.find('.v-icon[data-icon="smile"]').exists()).toBe(true)
      expect(wrapper.find('.v-icon[data-icon="meh"]').exists()).toBe(true)
      expect(wrapper.find('.v-icon[data-icon="frown"]').exists()).toBe(true)
    })

    it('updates happiness when button clicked', async () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })
      const happyBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Happy'))

      await happyBtn.trigger('click')

      expect(wrapper.vm.happiness).toBe('Happy')
    })
  })

  describe('comments section', () => {
    it('renders comments textarea when completion shown', () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })
      expect(wrapper.find('textarea').exists()).toBe(true)
    })

    it('shows public comments notice for happy/fine outcomes', () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })
      expect(wrapper.text()).toContain('may be public')
    })
  })

  describe('footer buttons', () => {
    it('renders cancel button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Cancel')
    })

    it('renders submit button with correct label for Taken', () => {
      const wrapper = createWrapper({ type: 'Taken' })
      expect(wrapper.text()).toContain('Mark as TAKEN')
    })

    it('renders submit button with correct label for Withdrawn', () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })
      expect(wrapper.text()).toContain('Withdraw')
    })

    it('does not disable submit button when no users selected for Taken', () => {
      const wrapper = createWrapper({ type: 'Taken' })
      const submitBtn = wrapper.find('.spin-button')
      expect(submitBtn.attributes('disabled')).toBeUndefined()
    })
  })

  describe('cancel functionality', () => {
    it('calls hide when cancel clicked', async () => {
      const wrapper = createWrapper()
      const cancelBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Cancel'))

      await cancelBtn.trigger('click')

      expect(mockHide).toHaveBeenCalled()
    })

    it('resets tookUsers when cancel clicked', async () => {
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 1, count: 1 }]

      const cancelBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Cancel'))
      await cancelBtn.trigger('click')

      expect(wrapper.vm.tookUsers).toEqual([])
    })

    it('resets happiness when cancel clicked', async () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })
      wrapper.vm.happiness = 'Happy'

      const cancelBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Cancel'))
      await cancelBtn.trigger('click')

      expect(wrapper.vm.happiness).toBeNull()
    })
  })

  describe('computed properties', () => {
    describe('message', () => {
      it('returns message from store by id', () => {
        const wrapper = createWrapper()
        expect(wrapper.vm.message.id).toBe(123)
        expect(wrapper.vm.message.subject).toBe('Test Item')
      })
    })

    describe('left', () => {
      it('returns availablenow minus taken count', () => {
        mockById.mockReturnValue({
          id: 123,
          subject: 'Test Item',
          type: 'Offer',
          availablenow: 5,
          availableinitially: 5,
          groups: [{ groupid: 456 }],
          replies: [],
        })
        const wrapper = createWrapper()
        wrapper.vm.tookUsers = [
          { userid: 1, count: 2 },
          { userid: 2, count: 1 },
        ]
        expect(wrapper.vm.left).toBe(2)
      })

      it('ignores users with negative userid', () => {
        mockById.mockReturnValue({
          id: 123,
          subject: 'Test Item',
          type: 'Offer',
          availablenow: 3,
          availableinitially: 3,
          groups: [{ groupid: 456 }],
          replies: [],
        })
        const wrapper = createWrapper()
        wrapper.vm.tookUsers = [{ userid: -1, count: 1 }]
        expect(wrapper.vm.left).toBe(3)
      })
    })

    describe('showCompletion', () => {
      it('returns true for Withdrawn type', () => {
        const wrapper = createWrapper({ type: 'Withdrawn' })
        expect(wrapper.vm.showCompletion).toBe(true)
      })

      it('returns true when availableinitially is 1', () => {
        const wrapper = createWrapper()
        expect(wrapper.vm.showCompletion).toBe(true)
      })

      it('returns true when left is 0 on a bulk offer', () => {
        mockById.mockReturnValue({
          id: 123,
          subject: 'Test Item',
          type: 'Offer',
          availablenow: 2,
          availableinitially: 2,
          bulkcount: 3,
          groups: [{ groupid: 456 }],
          replies: [],
        })
        const wrapper = createWrapper()
        wrapper.vm.tookUsers = [{ userid: 1, count: 2 }]
        expect(wrapper.vm.showCompletion).toBe(true)
      })
    })

    describe('groupid', () => {
      it('returns first group id from message', () => {
        const wrapper = createWrapper()
        expect(wrapper.vm.groupid).toBe(456)
      })

      it('returns null when no groups', () => {
        mockById.mockReturnValue({
          id: 123,
          subject: 'Test Item',
          type: 'Offer',
          availablenow: 1,
          availableinitially: 1,
          groups: [],
          replies: [],
        })
        const wrapper = createWrapper()
        expect(wrapper.vm.groupid).toBeNull()
      })
    })

    describe('buttonLabel', () => {
      it('returns Submit when no type', () => {
        const wrapper = createWrapper({ type: null })
        expect(wrapper.vm.buttonLabel).toBe('Submit')
      })

      it('returns Withdraw for Withdrawn type', () => {
        const wrapper = createWrapper({ type: 'Withdrawn' })
        expect(wrapper.vm.buttonLabel).toBe('Withdraw')
      })

      it('returns Mark as TAKEN for Taken type', () => {
        const wrapper = createWrapper({ type: 'Taken' })
        expect(wrapper.vm.buttonLabel).toBe('Mark as TAKEN')
      })

      it('returns Mark as RECEIVED for Received type', () => {
        const wrapper = createWrapper({ type: 'Received' })
        expect(wrapper.vm.buttonLabel).toBe('Mark as RECEIVED')
      })
    })
  })

  describe('submit validation', () => {
    it('sets chooseError when submitting Taken with no users selected', async () => {
      const wrapper = createWrapper({ type: 'Taken' })
      expect(wrapper.vm.chooseError).toBe(false)

      const submitBtn = wrapper.find('.spin-button')
      await submitBtn.trigger('click')

      expect(wrapper.vm.chooseError).toBe(true)
      expect(wrapper.vm.submittedWithNoSelectedUser).toBe(true)
    })

    it('passes chooseError to OutcomeBy component', async () => {
      const wrapper = createWrapper({ type: 'Taken' })

      const submitBtn = wrapper.find('.spin-button')
      await submitBtn.trigger('click')

      const outcomeBy = wrapper.find('.outcome-by')
      expect(outcomeBy.exists()).toBe(true)
      expect(wrapper.vm.chooseError).toBe(true)
    })

    it('clears chooseError when users are selected and submit is called', async () => {
      const wrapper = createWrapper({ type: 'Taken' })

      // First submit with no users to trigger error
      const submitBtn = wrapper.find('.spin-button')
      await submitBtn.trigger('click')
      expect(wrapper.vm.chooseError).toBe(true)

      // Now add a user and submit again
      wrapper.vm.tookUsers = [{ userid: 1, count: 1 }]
      await submitBtn.trigger('click')
      expect(wrapper.vm.chooseError).toBe(false)
    })

    it('does not set chooseError for Withdrawn type', async () => {
      const wrapper = createWrapper({ type: 'Withdrawn' })

      const submitBtn = wrapper.find('.spin-button')
      await submitBtn.trigger('click')

      expect(wrapper.vm.chooseError).toBe(false)
    })
  })

  describe('took method', () => {
    it('updates tookUsers', () => {
      const wrapper = createWrapper()
      const users = [{ userid: 1, count: 2 }]
      wrapper.vm.took(users)
      expect(wrapper.vm.tookUsers).toEqual(users)
    })
  })

  describe('onHide', () => {
    it('emits hidden event', () => {
      const wrapper = createWrapper()
      wrapper.vm.onHide()
      expect(wrapper.emitted('hidden')).toBeTruthy()
    })

    it('resets tookUsers', () => {
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 1, count: 1 }]
      wrapper.vm.onHide()
      expect(wrapper.vm.tookUsers).toEqual([])
    })

    it('resets happiness', () => {
      const wrapper = createWrapper()
      wrapper.vm.happiness = 'Happy'
      wrapper.vm.onHide()
      expect(wrapper.vm.happiness).toBeNull()
    })
  })

  describe('props', () => {
    it('requires id prop', () => {
      const wrapper = createWrapper({ id: 999 })
      expect(wrapper.props('id')).toBe(999)
    })

    it('has optional takenBy defaulting to null', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('takenBy')).toBeNull()
    })

    it('has optional type defaulting to null', () => {
      const wrapper = createWrapper({ type: undefined })
      expect(wrapper.props('type')).toBeNull()
    })

    it('accepts takenBy object', () => {
      const takenBy = { userid: 1, displayname: 'John' }
      const wrapper = createWrapper({ takenBy })
      expect(wrapper.props('takenBy')).toEqual(takenBy)
    })
  })
  describe('some left or everything gone', () => {
    function multiItem(extra = {}) {
      mockById.mockReturnValue({
        id: 123,
        subject: 'Test Item',
        type: 'Offer',
        availablenow: 5,
        availableinitially: 5,
        groups: [{ groupid: 456 }],
        replies: [],
        ...extra,
      })
    }

    it('offers the choice when several were on offer', () => {
      multiItem()
      const wrapper = createWrapper()
      expect(wrapper.find('.all-gone').exists()).toBe(true)
      expect(wrapper.find('.some-left').exists()).toBe(true)
    })

    it('does not offer the choice for a single item', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.some-left').exists()).toBe(false)
    })

    it('does not offer the choice on a bulk offer', () => {
      multiItem({ bulkcount: 3 })
      const wrapper = createWrapper()
      expect(wrapper.find('.some-left').exists()).toBe(false)
    })

    it('assumes everything has gone until told otherwise', () => {
      multiItem()
      const wrapper = createWrapper()
      expect(wrapper.vm.allGone).toBe(true)
    })

    it('never warns that some will be left', async () => {
      multiItem()
      const wrapper = createWrapper()
      await wrapper.find('.some-left').trigger('click')
      expect(wrapper.text()).not.toContain('please adjust the numbers')
    })

    it('asks how it went when everything has gone', () => {
      multiItem()
      const wrapper = createWrapper()
      expect(wrapper.vm.showCompletion).toBe(true)
    })

    it('does not ask how it went when some are left', async () => {
      multiItem()
      const wrapper = createWrapper()
      await wrapper.find('.some-left').trigger('click')
      expect(wrapper.vm.showCompletion).toBe(false)
    })

    it('records the takers with no count', async () => {
      multiItem()
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 7 }, { userid: 8 }]
      await wrapper.vm.submit(() => {})
      expect(mockAddBy).toHaveBeenCalledTimes(2)
      expect(mockAddBy).toHaveBeenCalledWith(123, 7)
      expect(mockAddBy).toHaveBeenCalledWith(123, 8)
    })

    it('ends the post when everything has gone', async () => {
      multiItem()
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 7 }]
      await wrapper.vm.submit(() => {})
      expect(mockUpdate).toHaveBeenCalledWith(
        expect.objectContaining({ action: 'Outcome', outcome: 'Taken' })
      )
    })

    it('leaves the post up when some are left', async () => {
      multiItem()
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 7 }]
      await wrapper.find('.some-left').trigger('click')
      await wrapper.vm.submit(() => {})
      expect(mockAddBy).toHaveBeenCalledWith(123, 7)
      expect(mockUpdate).not.toHaveBeenCalled()
    })

    it('ticks the chosen answer', async () => {
      multiItem()
      const wrapper = createWrapper()
      const tick = (sel) =>
        wrapper.find(`${sel} .v-icon`).attributes('data-icon')
      expect(tick('.all-gone')).toBe('check-circle')
      expect(tick('.some-left')).toBe('circle')
      await wrapper.find('.some-left').trigger('click')
      expect(tick('.all-gone')).toBe('circle')
      expect(tick('.some-left')).toBe('check-circle')
    })

    it('does not say Mark as TAKEN when the post will stay up', async () => {
      multiItem()
      const wrapper = createWrapper()
      expect(wrapper.vm.buttonLabel).toBe('Mark as TAKEN')
      await wrapper.find('.some-left').trigger('click')
      expect(wrapper.vm.buttonLabel).toBe('Save, keep my post up')
      expect(wrapper.text()).toContain('Your post will stay up')
    })

    it('fetches the post again when some are left, so the card shows it', async () => {
      multiItem()
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 7 }]
      await wrapper.find('.some-left').trigger('click')
      await wrapper.vm.submit(() => {})
      expect(mockFetch).toHaveBeenCalledWith(123, true)
    })

    it('finishes a post that is already part gone', async () => {
      multiItem({ partgone: true, by: { userid: 7, displayname: 'A' } })
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 7 }]
      await wrapper.vm.submit(() => {})
      expect(mockUpdate).toHaveBeenCalledWith(
        expect.objectContaining({ action: 'Outcome', outcome: 'Taken' })
      )
    })

    it('passes a null userid through for someone else', async () => {
      multiItem()
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: null }]
      await wrapper.vm.submit(() => {})
      expect(mockAddBy).toHaveBeenCalledWith(123, null)
    })
  })

  describe('bulk clearance offer', () => {
    it('still sends the per-person counts', async () => {
      mockById.mockReturnValue({
        id: 123,
        subject: 'Test Item',
        type: 'Offer',
        availablenow: 5,
        availableinitially: 5,
        bulkcount: 3,
        groups: [{ groupid: 456 }],
        replies: [],
      })
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 7, count: 2 }]
      await wrapper.vm.submit(() => {})
      expect(mockAddBy).toHaveBeenCalledWith(123, 7, 2)
    })

    it('still completes only when the arithmetic says so', async () => {
      mockById.mockReturnValue({
        id: 123,
        subject: 'Test Item',
        type: 'Offer',
        availablenow: 5,
        availableinitially: 5,
        bulkcount: 3,
        groups: [{ groupid: 456 }],
        replies: [],
      })
      const wrapper = createWrapper()
      wrapper.vm.tookUsers = [{ userid: 7, count: 2 }]
      await wrapper.vm.submit(() => {})
      expect(mockUpdate).not.toHaveBeenCalled()
    })
  })
})
