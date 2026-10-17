import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModMessageButtons from '~/modtools/components/ModMessageButtons.vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.3: while the mods
// surface is held, the Go API allows only a bare Approve (no subject/body/
// stdmsgid) and refuses everything else - Reject, Delete, Hold/Release,
// Delete as Spam, standard messages, edit review actions. useLockdown()
// already folds in the Support/Admin exemption, so this component only has
// to ask it one question.
const mockModsHeld = ref(false)

// A stand-in for the callback a SpinButton hands its handler to stop the spinner.
const mockSpinnerFinished = vi.fn()

vi.mock('~/modtools/composables/useLockdown', () => ({
  useLockdown: () => ({ modsHeld: mockModsHeld, held: () => false }),
}))

// Whether a community is in the post-moderation trial; the oversight Reject is only for
// copies that published themselves there.
const mockIsTrialGroup = vi.fn(() => true)
vi.mock('~/modtools/composables/useAutoapproveTrial', () => ({
  useAutoapproveTrial: () => ({
    isTrialGroup: mockIsTrialGroup,
    inTrial: ref(true),
  }),
}))

// The Check card refreshes the menu counts once it has acted.
const mockCheckWork = vi.fn().mockResolvedValue()
vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({ checkWork: mockCheckWork }),
}))

// Mock stores and composables before vi.mock calls using vi.hoisted
const { mockMessageStore, mockModConfigStore } = vi.hoisted(() => {
  const mockMessageStore = {
    byId: vi.fn(),
    fetch: vi.fn().mockResolvedValue(),
    updateMT: vi.fn().mockResolvedValue(),
    rejectFromOversight: vi.fn().mockResolvedValue(1),
    markCheckedPost: vi.fn().mockResolvedValue(1),
  }
  const mockModConfigStore = {
    configsById: {},
  }
  return { mockMessageStore, mockModConfigStore }
})

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => mockModConfigStore,
}))

vi.mock('~/composables/useStdMsgs', () => ({
  copyStdMsgs: (config) => config.stdmsgs || [],
  icon: (stdmsg) => {
    switch (stdmsg.action) {
      case 'Approve':
        return 'check'
      case 'Reject':
        return 'times'
      case 'Leave':
      case 'Leave Approved Message':
        return 'envelope'
      case 'Delete':
      case 'Delete Approved Message':
        return 'trash-alt'
      case 'Edit':
        return 'pen'
      case 'Hold Message':
        return 'pause'
      default:
        return 'check'
    }
  },
  variant: (stdmsg) => {
    switch (stdmsg.action) {
      case 'Approve':
        return 'primary'
      case 'Reject':
        return 'warning'
      case 'Leave':
      case 'Leave Approved Message':
        return 'primary'
      case 'Delete':
      case 'Delete Approved Message':
        return 'danger'
      case 'Edit':
        return 'primary'
      case 'Hold Message':
        return 'warning'
      default:
        return 'white'
    }
  },
}))

describe('ModMessageButtons', () => {
  // Helper to create test message data
  const createMessage = (overrides = {}) => ({
    id: 123,
    subject: 'Test Message Subject',
    type: 'Offer',
    heldby: null,
    groups: [{ groupid: 456, collection: 'Pending' }],
    outcomes: [],
    ...overrides,
  })

  // Helper to create test modconfig
  const createModConfig = (overrides = {}) => ({
    id: 1,
    stdmsgs: [
      { id: 1, title: 'Approve Message', action: 'Approve', rarelyused: 0 },
      { id: 2, title: 'Reject Message', action: 'Reject', rarelyused: 0 },
      { id: 3, title: 'Delete Message', action: 'Delete', rarelyused: 1 },
      { id: 4, title: 'Edit Message', action: 'Edit', rarelyused: 0 },
    ],
    messageorder: null,
    ...overrides,
  })

  // Common stubs for child components
  const commonStubs = {
    ModMessageButton: {
      template: `<button
        class="mod-message-button"
        :data-stdmsgid="String(stdmsgid)"
        :data-no-member-message="String(noMemberMessage)"
        :data-groupids="groupids ? groupids.join(',') : ''"
        :class="{
          [variant]: true,
          approve: approve === '' || approve === true,
          reject: reject === '' || reject === true,
          delete: $props.delete === '' || $props.delete === true,
          hold: hold === '' || hold === true,
          release: release === '' || release === true,
          spam: spam === '' || spam === true,
          approveedits: approveedits === '' || approveedits === true,
          revertedits: revertedits === '' || revertedits === true,
          leave: leave === '' || leave === true
        }"><slot />{{ label }}</button>`,
      props: [
        'messageid',
        'variant',
        'icon',
        'label',
        'stdmsgid',
        'autosend',
        'approve',
        'reject',
        'delete',
        'hold',
        'release',
        'spam',
        'approveedits',
        'revertedits',
        'leave',
        'isHomeGroup',
        'noMemberMessage',
        'groupids',
      ],
    },
    SpinButton: {
      template:
        '<button class="spin-button" :disabled="disabled" @click="$emit(\'handle\', mockFinish)"><slot />{{ label }}</button>',
      props: ['variant', 'iconName', 'label', 'confirm', 'flex', 'disabled'],
      data: () => ({ mockFinish: (...args) => mockSpinnerFinished(...args) }),
    },
    // Confirms by a click on its own button, as the real one confirms on its footer button.
    ConfirmModal: {
      template:
        '<div class="confirm-modal"><span class="confirm-title">{{ title }}</span><slot /><button class="confirm-yes" @click="$emit(\'confirm\')">{{ confirmLabel }}</button></div>',
      props: ['title', 'confirmLabel'],
      emits: ['confirm', 'hidden'],
    },
    NoticeMessage: {
      template: '<div class="notice-message" :class="variant"><slot /></div>',
      props: ['variant'],
    },
    OurToggle: {
      template: '<div class="our-toggle">{{ labels.checked }}</div>',
      props: [
        'modelValue',
        'height',
        'width',
        'fontSize',
        'sync',
        'labels',
        'variant',
      ],
    },
    'b-button': {
      template:
        '<button class="b-button" @click="$emit(\'click\')"><slot /></button>',
      props: ['variant'],
    },
    'v-icon': {
      template: '<i :class="icon"></i>',
      props: ['icon'],
    },
    'client-only': {
      template: '<div><slot /></div>',
    },
  }

  function mountComponent(props = {}, messageOverrides = {}) {
    const messageData = createMessage(messageOverrides)

    mockMessageStore.byId.mockImplementation((id) => {
      if (id === messageData.id) return messageData
      return null
    })

    // If modconfigid is provided, set up the modconfig store
    const modconfigid = props.modconfigid || null
    if (modconfigid) {
      // Already set up in beforeEach or via props
    }

    return mount(ModMessageButtons, {
      props: {
        messageid: messageData.id,
        ...props,
      },
      global: {
        stubs: commonStubs,
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    // Reset configsById
    mockModConfigStore.configsById = {}
    mockModsHeld.value = false
    mockMessageStore.rejectFromOversight.mockResolvedValue(1)
    mockMessageStore.markCheckedPost.mockResolvedValue(1)
    mockCheckWork.mockResolvedValue()
    mockIsTrialGroup.mockReturnValue(true)
  })

  describe('rendering', () => {
    it('renders OurToggle for autosend control', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.our-toggle').exists()).toBe(true)
      expect(wrapper.find('.our-toggle').text()).toContain('Allow autosend')
    })
  })

  describe('pending message buttons', () => {
    it('shows approve button for pending messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(true)
    })

    it('shows reject button for pending messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.find('.mod-message-button.reject').exists()).toBe(true)
    })

    it('shows delete button for pending messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.find('.mod-message-button.delete').exists()).toBe(true)
    })

    it('shows hold button for pending messages without heldby', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          groups: [{ groupid: 456, collection: 'Pending', heldby: null }],
        }
      )
      expect(wrapper.find('.mod-message-button.hold').exists()).toBe(true)
    })

    it('hides hold button when the copy on THIS group is held', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          groups: [{ groupid: 456, collection: 'Pending', heldby: 1 }],
        }
      )
      expect(wrapper.find('.mod-message-button.hold').exists()).toBe(false)
    })

    // Discourse 9970/2: heldby is per-group (messages_groups.heldby). A hold on a
    // DIFFERENT group this rippled post is also on must not swap Hold for Release
    // on the copy actually being administered (groupid prop).
    it('still shows hold button when a DIFFERENT group on the same rippled post is held', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          // The server ORs any held group into this message-wide flag, so the
          // pre-fix code (which read message.heldby directly) would still hide
          // Hold / show Release here even though group 456 itself is not held.
          heldby: 1,
          groups: [
            { groupid: 456, collection: 'Pending', heldby: null },
            { groupid: 457, collection: 'Pending', heldby: 1 },
          ],
        }
      )
      expect(wrapper.find('.mod-message-button.hold').exists()).toBe(true)
      expect(wrapper.find('.mod-message-button.release').exists()).toBe(false)
    })

    it('shows spam button for pending messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.find('.mod-message-button.spam').exists()).toBe(true)
    })

    it('hides approve button when cantpost is true', () => {
      const wrapper = mountComponent(
        { cantpost: true },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(false)
    })
  })

  describe('spam collection messages (shown in pending queue)', () => {
    // Discourse #9654: Spam-collection messages are surfaced in the Pending
    // review queue and must offer the same moderation actions as Pending —
    // otherwise they render with no action buttons (only the autosend toggle).
    const spamGroups = { groups: [{ groupid: 456, collection: 'Spam' }] }

    it('shows approve button for spam-collection messages', () => {
      const wrapper = mountComponent({}, spamGroups)
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(true)
    })

    it('shows reject button for spam-collection messages', () => {
      const wrapper = mountComponent({}, spamGroups)
      expect(wrapper.find('.mod-message-button.reject').exists()).toBe(true)
    })

    it('shows delete button for spam-collection messages', () => {
      const wrapper = mountComponent({}, spamGroups)
      expect(wrapper.find('.mod-message-button.delete').exists()).toBe(true)
    })

    it('shows spam button for spam-collection messages', () => {
      const wrapper = mountComponent({}, spamGroups)
      expect(wrapper.find('.mod-message-button.spam').exists()).toBe(true)
    })

    it('hides approve button for spam-collection messages when cantpost is true', () => {
      const wrapper = mountComponent({ cantpost: true }, spamGroups)
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(false)
    })

    it('spam computed is true for a Spam-collection message', () => {
      const wrapper = mountComponent({}, spamGroups)
      expect(wrapper.vm.spam).toBe(true)
    })

    it('spam computed is false for a Pending-collection message', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.vm.spam).toBe(false)
    })
  })

  describe('copy locked by the home community', () => {
    it('shows Approve on a pending copy that is not locked', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          groups: [{ groupid: 456, collection: 'Pending', locked_by_home: 0 }],
        }
      )
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(true)
    })

    it('hides Approve on a pending copy locked by the home community', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          groups: [{ groupid: 456, collection: 'Pending', locked_by_home: 1 }],
        }
      )
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(false)
      // Reject and Hold stay: only approving is withdrawn.
      expect(wrapper.find('.mod-message-button.reject').exists()).toBe(true)
      expect(wrapper.find('.mod-message-button.hold').exists()).toBe(true)
    })

    it('only the locked group is affected when the post is on several', () => {
      const groups = [
        { groupid: 456, collection: 'Pending', locked_by_home: 1 },
        { groupid: 789, collection: 'Pending', locked_by_home: 0 },
      ]
      expect(
        mountComponent({ groupid: 789 }, { groups })
          .find('.mod-message-button.approve')
          .exists()
      ).toBe(true)
      expect(
        mountComponent({ groupid: 456 }, { groups })
          .find('.mod-message-button.approve')
          .exists()
      ).toBe(false)
    })

    it('does not offer an Approve standard message while locked', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          groups: [{ groupid: 456, collection: 'Pending', locked_by_home: 1 }],
        }
      )
      expect(wrapper.vm.validActions).not.toContain('Approve')
    })
  })

  describe('approved message buttons', () => {
    it('shows leave (blank reply) button for approved messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Approved' }] }
      )
      expect(wrapper.find('.mod-message-button.leave').exists()).toBe(true)
    })

    it('shows delete button for approved messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Approved' }] }
      )
      expect(wrapper.find('.mod-message-button.delete').exists()).toBe(true)
    })

    it('shows spam button for approved messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Approved' }] }
      )
      expect(wrapper.find('.mod-message-button.spam').exists()).toBe(true)
    })

    it('shows Mark as TAKEN button for Offer without outcomes', () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [{ groupid: 456, collection: 'Approved' }],
          type: 'Offer',
          outcomes: [],
        }
      )
      const spinButtons = wrapper.findAll('.spin-button')
      const takenButton = spinButtons.find((btn) =>
        btn.text().includes('Mark as TAKEN')
      )
      expect(takenButton).toBeDefined()
    })

    it('shows Mark as RECEIVED button for Wanted without outcomes', () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [{ groupid: 456, collection: 'Approved' }],
          type: 'Wanted',
          outcomes: [],
        }
      )
      const spinButtons = wrapper.findAll('.spin-button')
      const receivedButton = spinButtons.find((btn) =>
        btn.text().includes('Mark as RECEIVED')
      )
      expect(receivedButton).toBeDefined()
    })

    it('shows Mark as Withdrawn button for messages without outcomes', () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [{ groupid: 456, collection: 'Approved' }],
          outcomes: [],
        }
      )
      const spinButtons = wrapper.findAll('.spin-button')
      const withdrawnButton = spinButtons.find((btn) =>
        btn.text().includes('Mark as Withdrawn')
      )
      expect(withdrawnButton).toBeDefined()
    })

    it('hides TAKEN button when message has outcomes', () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [{ groupid: 456, collection: 'Approved' }],
          type: 'Offer',
          outcomes: [{ id: 1, outcome: 'Taken' }],
        }
      )
      const spinButtons = wrapper.findAll('.spin-button')
      const takenButton = spinButtons.find((btn) =>
        btn.text().includes('Mark as TAKEN')
      )
      expect(takenButton).toBeUndefined()
    })

    // Outcomes are facts about the whole post, so they belong to the poster or to the
    // moderators of the group it was posted on. On a copy the post merely rippled into,
    // the server refuses them, so the buttons must not be offered there (Discourse 10102).
    it('hides TAKEN, RECEIVED and Withdrawn on a copy the post rippled into', () => {
      const wrapper = mountComponent(
        { isHomeGroup: false },
        {
          groups: [{ groupid: 456, collection: 'Approved' }],
          type: 'Offer',
          outcomes: [],
        }
      )
      const labels = wrapper.findAll('.spin-button').map((btn) => btn.text())
      expect(labels.some((l) => l.includes('Mark as TAKEN'))).toBe(false)
      expect(labels.some((l) => l.includes('Mark as RECEIVED'))).toBe(false)
      expect(labels.some((l) => l.includes('Mark as Withdrawn'))).toBe(false)
    })

    it('still offers Withdrawn on the home group when there is no outcome', () => {
      const wrapper = mountComponent(
        { isHomeGroup: true },
        {
          groups: [{ groupid: 456, collection: 'Approved' }],
          type: 'Offer',
          outcomes: [],
        }
      )
      const labels = wrapper.findAll('.spin-button').map((btn) => btn.text())
      expect(labels.some((l) => l.includes('Mark as Withdrawn'))).toBe(true)
      expect(labels.some((l) => l.includes('Mark as TAKEN'))).toBe(true)
    })
  })

  // Discourse 10239: on a copy the post rippled into, the buttons whose only effect is a
  // message to the poster come back once the poster is an ordinary member there. Outcomes
  // and whole-post deletes still belong to the home community.
  describe('rippled-in copy where the poster can be written to', () => {
    const approvedOffer = {
      groups: [{ groupid: 456, collection: 'Approved' }],
      type: 'Offer',
      outcomes: [],
    }
    const labels = (wrapper) =>
      wrapper
        .findAll('.mod-message-button, .spin-button')
        .map((btn) => btn.text())

    it('offers Blank Reply', () => {
      const wrapper = mountComponent(
        { isHomeGroup: false, canMessagePoster: true },
        approvedOffer
      )
      expect(labels(wrapper).some((l) => l.includes('Blank Reply'))).toBe(true)
    })

    it('does not offer Blank Reply when the poster cannot be written to', () => {
      const wrapper = mountComponent(
        { isHomeGroup: false, canMessagePoster: false },
        approvedOffer
      )
      expect(labels(wrapper).some((l) => l.includes('Blank Reply'))).toBe(false)
    })

    it('still does not offer outcomes or whole-post deletes', () => {
      const wrapper = mountComponent(
        { isHomeGroup: false, canMessagePoster: true },
        approvedOffer
      )
      const l = labels(wrapper)
      expect(l.some((x) => x.includes('Mark as TAKEN'))).toBe(false)
      expect(l.some((x) => x.includes('Mark as Withdrawn'))).toBe(false)
      expect(l.some((x) => x.includes('Delete as Spam'))).toBe(false)
    })

    it('offers the standard messages that write to the poster', () => {
      const wrapper = mountComponent(
        { isHomeGroup: false, canMessagePoster: true },
        approvedOffer
      )
      expect(wrapper.vm.validActions).toContain('Leave Approved Message')
    })

    // Reject now composes a message to the poster, so a moderator who just wants the post
    // gone needs a Delete that says nothing (Discourse 9808/867). It acts on this copy only.
    it('offers a plain Delete on an approved copy', () => {
      const wrapper = mountComponent(
        { isHomeGroup: false, canMessagePoster: true },
        approvedOffer
      )
      expect(labels(wrapper)).toContain('Delete')
    })

    it('offers a plain Delete on a pending copy', () => {
      const wrapper = mountComponent({
        isHomeGroup: false,
        canMessagePoster: true,
      })
      expect(labels(wrapper)).toContain('Delete')
    })

    it('offers no Delete on a copy where the poster cannot be written to', () => {
      const wrapper = mountComponent({
        isHomeGroup: false,
        canMessagePoster: false,
      })
      expect(labels(wrapper)).not.toContain('Delete')
    })
  })

  describe('editreview buttons', () => {
    it('shows accept edit button when editreview is true', () => {
      const wrapper = mountComponent({ editreview: true })
      expect(wrapper.find('.mod-message-button.approveedits').exists()).toBe(
        true
      )
    })

    it('shows reject edit button when editreview is true', () => {
      const wrapper = mountComponent({ editreview: true })
      expect(wrapper.find('.mod-message-button.revertedits').exists()).toBe(
        true
      )
    })

    it('shows blank reply button when editreview is true', () => {
      const wrapper = mountComponent({ editreview: true })
      expect(wrapper.find('.mod-message-button.leave').exists()).toBe(true)
    })

    it('hides pending buttons when editreview is true', () => {
      const wrapper = mountComponent(
        { editreview: true },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      // Should not show the normal pending buttons like approve
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(false)
    })
  })

  describe('hasCollection helper', () => {
    it('returns true when message has Pending collection', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.vm.pending).toBe(true)
      expect(wrapper.vm.approved).toBe(false)
    })

    it('returns true when message has Approved collection', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Approved' }] }
      )
      expect(wrapper.vm.pending).toBe(false)
      expect(wrapper.vm.approved).toBe(true)
    })

    it('returns false when message has no matching collection', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Other' }] }
      )
      expect(wrapper.vm.pending).toBe(false)
      expect(wrapper.vm.approved).toBe(false)
    })

    // A post that rippled to several groups has one row per group, each with its own
    // collection. The buttons must describe the copy being administered, not whichever
    // other group still has the post waiting (Discourse 10102).
    it('reads the collection of the group being administered, not any group', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          groups: [
            { groupid: 456, collection: 'Approved' },
            { groupid: 789, collection: 'Pending' },
          ],
        }
      )
      expect(wrapper.vm.pending).toBe(false)
      expect(wrapper.vm.approved).toBe(true)
    })

    it('falls back to any group when no group is being administered', () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [
            { groupid: 456, collection: 'Approved' },
            { groupid: 789, collection: 'Pending' },
          ],
        }
      )
      expect(wrapper.vm.pending).toBe(true)
      expect(wrapper.vm.approved).toBe(true)
    })
  })

  describe('validActions computed', () => {
    it('returns pending actions for pending messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.vm.validActions).toContain('Reject')
      expect(wrapper.vm.validActions).toContain('Leave')
      expect(wrapper.vm.validActions).toContain('Delete')
      expect(wrapper.vm.validActions).toContain('Edit')
      expect(wrapper.vm.validActions).toContain('Hold Message')
      expect(wrapper.vm.validActions).toContain('Approve')
    })

    it('excludes Approve when cantpost is true', () => {
      const wrapper = mountComponent(
        { cantpost: true },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.vm.validActions).not.toContain('Approve')
    })

    it('returns approved actions for approved messages', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Approved' }] }
      )
      expect(wrapper.vm.validActions).toContain('Leave Approved Message')
      expect(wrapper.vm.validActions).toContain('Delete Approved Message')
      expect(wrapper.vm.validActions).toContain('Edit')
    })

    it('returns empty array for other collections', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Other' }] }
      )
      expect(wrapper.vm.validActions).toEqual([])
    })
  })

  describe('filtered computed', () => {
    it('returns empty array when modconfig is null', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.filtered).toEqual([])
    })

    it('filters standard messages by valid actions', () => {
      const modConfig = createModConfig({
        stdmsgs: [
          { id: 1, title: 'Approve', action: 'Approve', rarelyused: 0 },
          { id: 2, title: 'Other', action: 'SomeOtherAction', rarelyused: 0 },
        ],
      })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent(
        { modconfigid: 1 },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.vm.filtered.length).toBe(1)
      expect(wrapper.vm.filtered[0].action).toBe('Approve')
    })

    it('excludes rarely used messages by default', () => {
      const modConfig = createModConfig({
        stdmsgs: [
          { id: 1, title: 'Common', action: 'Approve', rarelyused: 0 },
          { id: 2, title: 'Rare', action: 'Reject', rarelyused: 1 },
        ],
      })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent(
        { modconfigid: 1 },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.vm.filtered.length).toBe(1)
      expect(wrapper.vm.filtered[0].title).toBe('Common')
    })
  })

  describe('rareToShow computed', () => {
    it('returns count of rarely used messages not shown', () => {
      const modConfig = createModConfig({
        stdmsgs: [
          { id: 1, title: 'Common', action: 'Approve', rarelyused: 0 },
          { id: 2, title: 'Rare1', action: 'Reject', rarelyused: 1 },
          { id: 3, title: 'Rare2', action: 'Delete', rarelyused: 1 },
        ],
      })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent(
        { modconfigid: 1 },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.vm.rareToShow).toBe(2)
    })

    it('returns 0 when no rarely used messages', () => {
      const modConfig = createModConfig({
        stdmsgs: [
          { id: 1, title: 'Common1', action: 'Approve', rarelyused: 0 },
          { id: 2, title: 'Common2', action: 'Reject', rarelyused: 0 },
        ],
      })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent(
        { modconfigid: 1 },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.vm.rareToShow).toBe(0)
    })
  })

  describe('showRare toggle', () => {
    it('shows button to reveal rare messages when there are some', () => {
      const modConfig = createModConfig({
        stdmsgs: [
          { id: 1, title: 'Common', action: 'Approve', rarelyused: 0 },
          { id: 2, title: 'Rare', action: 'Reject', rarelyused: 1 },
        ],
      })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent(
        { modconfigid: 1 },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.text()).toContain('+1...')
    })

    it('hides rare button when no rare messages exist', () => {
      const modConfig = createModConfig({
        stdmsgs: [{ id: 1, title: 'Common', action: 'Approve', rarelyused: 0 }],
      })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent(
        { modconfigid: 1 },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.text()).not.toContain('+')
    })

    it('shows all messages when showRare is toggled', async () => {
      const modConfig = createModConfig({
        stdmsgs: [
          { id: 1, title: 'Common', action: 'Approve', rarelyused: 0 },
          { id: 2, title: 'Rare', action: 'Reject', rarelyused: 1 },
        ],
      })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent(
        { modconfigid: 1 },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      // Initially should have 1 filtered
      expect(wrapper.vm.filtered.length).toBe(1)

      // Toggle showRare
      wrapper.vm.showRare = true
      await wrapper.vm.$nextTick()

      // Now should have 2 filtered
      expect(wrapper.vm.filtered.length).toBe(2)
    })
  })

  describe('outcome method', () => {
    it('calls messageStore.updateMT with Taken outcome', () => {
      const wrapper = mountComponent({}, { id: 999 })
      const callback = vi.fn()
      wrapper.vm.outcome(callback, 'Taken')
      expect(mockMessageStore.updateMT).toHaveBeenCalledWith({
        action: 'Outcome',
        id: 999,
        outcome: 'Taken',
      })
      expect(callback).toHaveBeenCalled()
    })

    it('calls messageStore.updateMT with Received outcome', () => {
      const wrapper = mountComponent({ messageid: 888 }, { id: 888 })
      wrapper.vm.outcome(null, 'Received')
      expect(mockMessageStore.updateMT).toHaveBeenCalledWith({
        action: 'Outcome',
        id: 888,
        outcome: 'Received',
      })
    })

    it('calls messageStore.updateMT with Withdrawn outcome', () => {
      const wrapper = mountComponent({ messageid: 777 }, { id: 777 })
      wrapper.vm.outcome(null, 'Withdrawn')
      expect(mockMessageStore.updateMT).toHaveBeenCalledWith({
        action: 'Outcome',
        id: 777,
        outcome: 'Withdrawn',
      })
    })
  })

  describe('allowAutoSend state', () => {
    it('defaults to true', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.allowAutoSend).toBe(true)
    })
  })

  describe('groupid prop passthrough', () => {
    it('passes groupid to all child ModMessageButton components', () => {
      // Update the stub to also accept groupid prop and have a name for findAllComponents
      const stubs = {
        ...commonStubs,
        ModMessageButton: {
          name: 'ModMessageButton',
          ...commonStubs.ModMessageButton,
          props: [...commonStubs.ModMessageButton.props, 'groupid'],
        },
      }

      // The buttons describe the copy on the group being administered, so the pending
      // row has to be on that group for any button to render.
      const messageData = createMessage({
        groups: [{ groupid: 789, collection: 'Pending' }],
      })
      mockMessageStore.byId.mockImplementation((id) =>
        id === messageData.id ? messageData : null
      )

      const wrapper = mount(ModMessageButtons, {
        props: { messageid: messageData.id, groupid: 789 },
        global: { stubs },
      })

      const buttons = wrapper.findAllComponents({ name: 'ModMessageButton' })
      expect(buttons.length).toBeGreaterThan(0)
      buttons.forEach((btn) => {
        expect(btn.props('groupid')).toBe(789)
      })
    })

    it('passes null groupid by default', () => {
      const stubs = {
        ...commonStubs,
        ModMessageButton: {
          name: 'ModMessageButton',
          ...commonStubs.ModMessageButton,
          props: [...commonStubs.ModMessageButton.props, 'groupid'],
        },
      }

      const messageData = createMessage({
        groups: [{ groupid: 456, collection: 'Pending' }],
      })
      mockMessageStore.byId.mockImplementation((id) =>
        id === messageData.id ? messageData : null
      )

      const wrapper = mount(ModMessageButtons, {
        props: { messageid: messageData.id },
        global: { stubs },
      })

      const buttons = wrapper.findAllComponents({ name: 'ModMessageButton' })
      expect(buttons.length).toBeGreaterThan(0)
      buttons.forEach((btn) => {
        expect(btn.props('groupid')).toBeNull()
      })
    })
  })

  describe('edge cases', () => {
    it('handles message with no groups', () => {
      const wrapper = mountComponent({}, { groups: undefined })
      expect(wrapper.vm.pending).toBe(false)
      expect(wrapper.vm.approved).toBe(false)
    })

    it('handles message with empty groups array', () => {
      const wrapper = mountComponent({}, { groups: [] })
      expect(wrapper.vm.pending).toBe(false)
      expect(wrapper.vm.approved).toBe(false)
    })

    it('handles modconfig with empty stdmsgs', () => {
      const modConfig = createModConfig({ stdmsgs: [] })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent({ modconfigid: 1 })
      expect(wrapper.vm.filtered).toEqual([])
    })
  })

  describe('oversight context (the Check queue)', () => {
    const live = (extra = {}) => [
      { groupid: 456, collection: 'Approved', ...extra },
    ]
    const pullBackBtn = (wrapper) => {
      const btn = wrapper.find('[data-testid="oversight-pullback"]')
      return btn.exists() ? btn : undefined
    }
    const checkedBtn = (wrapper) => {
      const btn = wrapper.find('[data-testid="oversight-checked"]')
      return btn.exists() ? btn : undefined
    }

    it('shows Pull back and Checked when oversight=true and the message is Approved', () => {
      const wrapper = mountComponent({ oversight: true }, { groups: live() })
      expect(pullBackBtn(wrapper)).toBeDefined()
      expect(checkedBtn(wrapper)).toBeDefined()
      expect(pullBackBtn(wrapper).text()).toContain('Pull back')
      expect(checkedBtn(wrapper).text()).toContain('Checked')
    })

    it('does not call the button Reject: it does not reject, it pulls the post back', () => {
      const wrapper = mountComponent({ oversight: true }, { groups: live() })
      expect(pullBackBtn(wrapper).text()).not.toContain('Reject')
    })

    it('shows neither when oversight is false (default) for Approved', () => {
      const wrapper = mountComponent({}, { groups: live() })
      expect(pullBackBtn(wrapper)).toBeUndefined()
      expect(checkedBtn(wrapper)).toBeUndefined()
    })

    // The Check list is copies with no approvedby, posted directly, on a trial
    // community. A copy outside that - one a moderator approved by hand, a rippled-in
    // copy, or a neighbouring community outside the trial - was never in Check and must
    // not be pulled back from it, even when the card shows both copies of a cross-post.
    it('shows neither on a copy a moderator approved by hand', () => {
      const wrapper = mountComponent(
        { oversight: true, groupid: 456 },
        { groups: live({ approvedby: 77 }) }
      )
      expect(pullBackBtn(wrapper)).toBeUndefined()
      expect(checkedBtn(wrapper)).toBeUndefined()
    })

    it('shows neither on a rippled-in copy', () => {
      const wrapper = mountComponent(
        { oversight: true, groupid: 456 },
        { groups: live({ rippled_in: 1 }) }
      )
      expect(pullBackBtn(wrapper)).toBeUndefined()
      expect(checkedBtn(wrapper)).toBeUndefined()
    })

    it('shows neither on a community outside the trial', () => {
      mockIsTrialGroup.mockReturnValue(false)
      const wrapper = mountComponent(
        { oversight: true, groupid: 456 },
        { groups: live() }
      )
      mockIsTrialGroup.mockReturnValue(true)
      expect(pullBackBtn(wrapper)).toBeUndefined()
      expect(checkedBtn(wrapper)).toBeUndefined()
      expect(mockIsTrialGroup).toHaveBeenCalledWith(456)
    })

    it('shows neither for Pending even with oversight=true', () => {
      const wrapper = mountComponent(
        { oversight: true },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(pullBackBtn(wrapper)).toBeUndefined()
      expect(checkedBtn(wrapper)).toBeUndefined()
    })

    // While the mods surface is held the dangerous buttons are hidden. Pulling a post back
    // withdraws copies, and a check lifts the hold on spreading: neither is for a lockdown.
    it('hides both while the moderators surface is held', () => {
      mockModsHeld.value = true
      const wrapper = mountComponent(
        { oversight: true, groupid: 456 },
        { groups: live() }
      )
      expect(pullBackBtn(wrapper)).toBeUndefined()
      expect(checkedBtn(wrapper)).toBeUndefined()
    })

    describe('Pull back', () => {
      const open = async () => {
        const wrapper = mountComponent(
          { oversight: true, groupid: 456 },
          { id: 123, groups: live() }
        )
        await pullBackBtn(wrapper).trigger('click')
        return wrapper
      }

      it('asks first, and says what it does, before touching the post', async () => {
        const wrapper = await open()
        expect(mockMessageStore.rejectFromOversight).not.toHaveBeenCalled()
        const modal = wrapper.find('.confirm-modal')
        expect(modal.exists()).toBe(true)
        expect(modal.find('.confirm-title').text()).toContain(
          'Pull this post back'
        )
        const text = modal.text()
        expect(text).toContain('held by you')
        expect(text).toContain('Pending queue')
        expect(text).toContain('already approved or checked')
        expect(text).toContain('not told')
      })

      // Spreading is paused, not ended: approving the post again lets it carry on, and
      // rejecting it ends it. The question says both, and never that approving is too late.
      it('says spreading is paused until they decide, and what each answer does', async () => {
        const wrapper = await open()
        const text = wrapper.find('.confirm-modal').text()
        expect(text).toContain('paused until you decide')
        expect(text).toContain('approve the post again it carries on spreading')
        expect(text).toContain('reject it, spreading ends')
        expect(text).not.toContain('stops spreading')
        expect(text).not.toContain('does not start')
      })

      it('does not use a dash as punctuation', async () => {
        const wrapper = await open()
        expect(wrapper.find('.confirm-modal').text()).not.toMatch(
          /[\u2013\u2014]/
        )
      })

      it('stops the spinner as soon as the question is up', async () => {
        await open()
        expect(mockSpinnerFinished).toHaveBeenCalled()
      })

      it('calls rejectFromOversight with messageid and groupid once confirmed, then refreshes the counts', async () => {
        const wrapper = await open()
        await wrapper.find('.confirm-yes').trigger('click')
        await flushPromises()
        expect(mockMessageStore.rejectFromOversight).toHaveBeenCalledWith(
          123,
          456
        )
        expect(mockCheckWork).toHaveBeenCalledWith(true)
        expect(wrapper.find('[data-testid="oversight-note"]').exists()).toBe(
          false
        )
      })

      it('says so when the server pulled nothing back', async () => {
        mockMessageStore.rejectFromOversight.mockResolvedValue(0)
        const wrapper = await open()
        await wrapper.find('.confirm-yes').trigger('click')
        await flushPromises()
        const note = wrapper.find('[data-testid="oversight-note"]')
        expect(note.exists()).toBe(true)
        expect(note.text()).toContain('Nothing was pulled back')
        expect(note.text()).toContain('changed since')
        expect(mockCheckWork).toHaveBeenCalledWith(true)
      })

      it('says so when the request fails, and can be tried again', async () => {
        mockMessageStore.rejectFromOversight.mockRejectedValue(new Error('500'))
        const wrapper = await open()
        await wrapper.find('.confirm-yes').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="oversight-note"]').text()).toContain(
          "didn't go through"
        )
        expect(pullBackBtn(wrapper).attributes('disabled')).toBeUndefined()
      })

      it('does not ask again until the first answer is back', async () => {
        let release
        mockMessageStore.rejectFromOversight.mockReturnValue(
          new Promise((resolve) => {
            release = resolve
          })
        )
        const wrapper = await open()
        await wrapper.find('.confirm-yes').trigger('click')
        await flushPromises()
        expect(pullBackBtn(wrapper).attributes('disabled')).toBeDefined()
        release(1)
        await flushPromises()
        expect(pullBackBtn(wrapper).attributes('disabled')).toBeUndefined()
      })
    })

    describe('Checked', () => {
      it('marks just that post, for the community the page is on, and refreshes the counts', async () => {
        const wrapper = mountComponent(
          { oversight: true, groupid: 456, checkGroupid: 456 },
          { id: 123, groups: live() }
        )
        await checkedBtn(wrapper).trigger('click')
        await flushPromises()
        expect(mockMessageStore.markCheckedPost).toHaveBeenCalledWith(123, 456)
        expect(mockCheckWork).toHaveBeenCalledWith(true)
        expect(mockSpinnerFinished).toHaveBeenCalled()
      })

      it('asks for all my communities when the page is not filtered', async () => {
        const wrapper = mountComponent(
          { oversight: true, groupid: 456 },
          { id: 123, groups: live() }
        )
        await checkedBtn(wrapper).trigger('click')
        await flushPromises()
        expect(mockMessageStore.markCheckedPost).toHaveBeenCalledWith(123, 0)
      })

      it('says so when it does not go through, and always stops the spinner', async () => {
        mockMessageStore.markCheckedPost.mockRejectedValue(new Error('500'))
        const wrapper = mountComponent(
          { oversight: true, groupid: 456 },
          { id: 123, groups: live() }
        )
        await checkedBtn(wrapper).trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="oversight-note"]').text()).toContain(
          "didn't go through"
        )
        expect(mockSpinnerFinished).toHaveBeenCalled()
        expect(mockCheckWork).not.toHaveBeenCalled()
      })
    })
  })

  // A TN post placed on a community its poster never chose: the moderator keeps the queue
  // actions but loses everything that would write to the poster, because there is nobody
  // on the other end who agreed to hear from them. See modmessaging in the Go API.
  describe('a post whose poster never joined Freegle', () => {
    function mountUnaddressed(props = {}, messageOverrides = {}) {
      mockModConfigStore.configsById = { 1: createModConfig() }
      return mountComponent(
        { modconfigid: 1, modMessagingAllowed: false, ...props },
        messageOverrides
      )
    }

    it('still offers Approve, Delete and Hold', () => {
      const labels = mountUnaddressed()
        .findAll('.mod-message-button')
        .map((b) => b.text())

      expect(labels).toContain('Approve')
      expect(labels).toContain('Delete')
      expect(labels).toContain('Delete as Spam')
      expect(labels).toContain('Hold')
    })

    it('offers no standard messages - every one of them writes to the poster', () => {
      const withStdmsg = mountUnaddressed()
        .findAll('.mod-message-button')
        .filter((b) => /^\d+$/.test(b.attributes('data-stdmsgid') || ''))

      expect(withStdmsg.length).toBe(0)
      expect(mountUnaddressed().vm.filtered).toEqual([])
    })

    it('offers the standard messages for an ordinary post, so the check above means something', () => {
      mockModConfigStore.configsById = { 1: createModConfig() }
      const wrapper = mountComponent({ modconfigid: 1 })

      expect(wrapper.vm.filtered.length).toBeGreaterThan(0)
    })

    it('hides the autosend toggle, which now controls nothing', () => {
      expect(mountUnaddressed().find('.our-toggle').exists()).toBe(false)
    })

    it('tells Reject to send the poster nothing', () => {
      const reject = mountUnaddressed()
        .findAll('.mod-message-button')
        .find((b) => b.text() === 'Reject')

      expect(reject.attributes('data-no-member-message')).toBe('true')
    })

    it('offers no Blank Reply on an approved copy either', () => {
      const labels = mountUnaddressed(
        {},
        { groups: [{ groupid: 456, collection: 'Approved' }] }
      )
        .findAll('.mod-message-button')
        .map((b) => b.text())

      expect(labels).not.toContain('Blank Reply')
      expect(labels).toContain('Delete')
    })

    it('offers Blank Reply on an approved ordinary post', () => {
      const labels = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Approved' }] }
      )
        .findAll('.mod-message-button')
        .map((b) => b.text())

      expect(labels).toContain('Blank Reply')
    })
  })

  describe('when mods is held (lockdown)', () => {
    // plans/active/2026-09-27-lockdown-switch.md section 11.3: the Go gate
    // allows only a bare Approve (no subject/body/stdmsgid) while mods is
    // held, and refuses everything else. useLockdown() already folds in the
    // Support/Admin exemption (tested in useLockdown.spec.js), so from this
    // component's point of view modsHeld true always means "hide it".
    beforeEach(() => {
      mockModsHeld.value = true
    })

    it('still shows Approve for a pending message', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(true)
    })

    it('still shows Approve for a spam-collection message', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Spam' }] }
      )
      expect(wrapper.find('.mod-message-button.approve').exists()).toBe(true)
    })

    it('hides reject, delete, hold and spam for a pending message', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          groups: [{ groupid: 456, collection: 'Pending', heldby: null }],
        }
      )
      expect(wrapper.find('.mod-message-button.reject').exists()).toBe(false)
      expect(wrapper.find('.mod-message-button.delete').exists()).toBe(false)
      expect(wrapper.find('.mod-message-button.hold').exists()).toBe(false)
      expect(wrapper.find('.mod-message-button.spam').exists()).toBe(false)
    })

    it('hides release even when the copy on this group is already held', () => {
      const wrapper = mountComponent(
        { groupid: 456 },
        {
          groups: [{ groupid: 456, collection: 'Pending', heldby: 1 }],
        }
      )
      expect(wrapper.find('.mod-message-button.release').exists()).toBe(false)
    })

    it('hides standard message buttons and the rare-message reveal', () => {
      const modConfig = createModConfig({
        stdmsgs: [
          { id: 1, title: 'Common', action: 'Approve', rarelyused: 0 },
          { id: 2, title: 'Rare', action: 'Reject', rarelyused: 1 },
        ],
      })
      mockModConfigStore.configsById = { 1: modConfig }

      const wrapper = mountComponent(
        { modconfigid: 1 },
        { groups: [{ groupid: 456, collection: 'Pending' }] }
      )
      const stdmsgButtons = wrapper
        .findAll('.mod-message-button')
        .filter((b) => /^\d+$/.test(b.attributes('data-stdmsgid') || ''))

      expect(stdmsgButtons.length).toBe(0)
      expect(wrapper.text()).not.toContain('+1...')
    })

    it('hides the autosend toggle', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.our-toggle').exists()).toBe(false)
    })

    it('shows no action buttons for an approved message', () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 456, collection: 'Approved' }], outcomes: [] }
      )
      const labels = wrapper.findAll('.mod-message-button').map((b) => b.text())

      expect(labels).not.toContain('Blank Reply')
      expect(wrapper.find('.mod-message-button.delete').exists()).toBe(false)
      expect(wrapper.find('.mod-message-button.spam').exists()).toBe(false)
      expect(wrapper.findAll('.spin-button').length).toBe(0)
    })

    it('shows no action buttons in edit review', () => {
      const wrapper = mountComponent({ editreview: true })

      expect(wrapper.find('.mod-message-button.approveedits').exists()).toBe(
        false
      )
      expect(wrapper.find('.mod-message-button.revertedits').exists()).toBe(
        false
      )
      const labels = wrapper.findAll('.mod-message-button').map((b) => b.text())
      expect(labels).not.toContain('Blank Reply')
    })
  })

  // A post pending on several of the moderator's communities: Approve, Reject, Delete and
  // the standard messages act on all of them; Hold, Release and Delete as Spam stay on the
  // community being looked at.
  describe('acting on several communities', () => {
    beforeEach(() => {
      mockModConfigStore.configsById = { 1: createModConfig() }
    })

    function groupidsOf(wrapper, label) {
      const button = wrapper
        .findAll('.mod-message-button')
        .find((b) => b.text() === label)
      return button?.attributes('data-groupids')
    }

    it('hands the communities to Approve, Reject, Delete and the standard messages only', () => {
      const wrapper = mountComponent({
        groupid: 456,
        groupids: [456, 457],
        modconfigid: 1,
      })

      expect(groupidsOf(wrapper, 'Approve')).toBe('456,457')
      expect(groupidsOf(wrapper, 'Reject')).toBe('456,457')
      expect(groupidsOf(wrapper, 'Delete')).toBe('456,457')
      expect(groupidsOf(wrapper, 'Reject Message')).toBe('456,457')
      expect(groupidsOf(wrapper, 'Hold')).toBe('')
      expect(groupidsOf(wrapper, 'Delete as Spam')).toBe('')
    })

    it('hands nothing on when there is one community', () => {
      const wrapper = mountComponent({ groupid: 456, modconfigid: 1 })

      expect(groupidsOf(wrapper, 'Approve')).toBe('')
    })
  })
})
