import {
  describe,
  it,
  expect,
  vi,
  beforeEach,
  afterAll,
  beforeAll,
} from 'vitest'
import { shallowMount } from '@vue/test-utils'
import { ref } from 'vue'
import ModStdMessageModal from '~/modtools/components/ModStdMessageModal.vue'

// Store original console.warn to restore after each test
// The global setup throws on Vue warnings, but this component uses template refs on stubs
// which produces unavoidable warnings in test environment
const originalConsoleWarn = console.warn

beforeAll(() => {
  console.warn = (...args) => {
    const message = typeof args[0] === 'string' ? args[0] : ''
    if (message.includes('Template ref')) {
      return
    }
    if (message.includes('callWithAsyncErrorHandling')) {
      return
    }
    if (message.includes('[Vue warn]')) {
      throw new Error(
        `Vue warning should not occur in tests: ${args.join(' ')}`
      )
    }
    originalConsoleWarn.apply(console, args)
  }
})

afterAll(() => {
  console.warn = originalConsoleWarn
})

// Mock stores
const mockModGroupStore = {
  fetchIfNeedBeMT: vi.fn(),
  get: vi.fn(),
}

const mockMessageStore = {
  approve: vi.fn(),
  reject: vi.fn(),
  reply: vi.fn(),
  delete: vi.fn(),
  hold: vi.fn(),
  patch: vi.fn(),
  byId: vi.fn(),
}

const mockMemberStore = {
  reply: vi.fn(),
  delete: vi.fn(),
  get: vi.fn(),
}

const mockUserStore = {
  edit: vi.fn(),
  byId: vi.fn(),
  fetch: vi.fn(),
}

const mockStdmsgStore = {
  byId: vi.fn(),
}

const mockHide = vi.fn()
const mockShow = vi.fn()

vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => mockModGroupStore,
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/stdmsg', () => ({
  useStdmsgStore: () => mockStdmsgStore,
}))

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: mockShow,
    hide: mockHide,
  }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref({ id: 999, displayname: 'Mod User' }),
  }),
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({
    checkWorkDeferGetMessages: vi.fn(),
  }),
}))

vi.mock('~/composables/useKeywords', () => ({
  setupKeywords: () => ({
    typeOptions: [
      { value: 'Offer', text: 'Offer' },
      { value: 'Wanted', text: 'Wanted' },
    ],
  }),
}))

vi.mock('~/constants', () => ({
  SUBJECT_REGEX: /^(Offer|Wanted): (.+) \((.+)\)$/i,
}))

describe('ModStdMessageModal', () => {
  const createMessage = (overrides = {}) => ({
    id: 101,
    subject: 'Offer: Test item (Location)',
    textbody: 'This is the message body.',
    fromuser: {
      id: 456,
      userid: 456,
      displayname: 'Test User',
      emails: [
        { email: 'test@example.com', preferred: true },
        { email: 'user@users.ilovefreegle.org', preferred: false },
      ],
    },
    groups: [{ groupid: 123 }],
    type: 'Offer',
    item: { name: 'Test item' },
    location: { name: 'Location' },
    ...overrides,
  })

  const createMember = (overrides = {}) => ({
    userid: 789,
    id: 789,
    displayname: 'Member User',
    email: 'member@example.com',
    groupid: 123,
    joincomment: 'Joined to post offers',
    ...overrides,
  })

  const createStdmsg = (overrides = {}) => ({
    id: 1,
    action: 'Approve',
    title: 'Standard Approve',
    body: 'Thank you for your post.',
    subjpref: 'Re:',
    subjsuff: '',
    insert: 'Top',
    newmodstatus: 'UNCHANGED',
    newdelstatus: 'UNCHANGED',
    edittext: null,
    ...overrides,
  })

  function mountComponent(props = {}, { message, member, stdmsgData } = {}) {
    const mockElement = { style: {} }
    vi.spyOn(window.document, 'getElementById').mockReturnValue(mockElement)

    const resolvedStdmsg = stdmsgData || createStdmsg()
    const resolvedMessage = message !== undefined ? message : createMessage()

    // Set up stdmsg store mock
    mockStdmsgStore.byId.mockImplementation((id) => {
      if (id === resolvedStdmsg.id) return resolvedStdmsg
      return null
    })

    // Set up message store mock
    mockMessageStore.byId.mockImplementation((id) => {
      if (resolvedMessage && id === resolvedMessage.id) return resolvedMessage
      return null
    })

    // Set up member store mock
    if (member) {
      mockMemberStore.get.mockImplementation((id) => {
        if (id === member.id) return member
        return null
      })
    }

    // Set up user store mock
    const fromuser = resolvedMessage?.fromuser
    mockUserStore.byId.mockImplementation((id) => {
      if (fromuser && typeof fromuser === 'object' && id === fromuser.id)
        return fromuser
      if (member && id === member.userid) return member
      return null
    })

    // Build final props
    const finalProps = { ...props }
    if (!('stdmsgid' in finalProps) && !('stdmsgaction' in finalProps)) {
      finalProps.stdmsgid = resolvedStdmsg.id
    }
    if (resolvedMessage && !('messageid' in finalProps)) {
      finalProps.messageid = resolvedMessage.id
    }
    if (member && !('membershipid' in finalProps)) {
      finalProps.membershipid = member.id
    }

    return shallowMount(ModStdMessageModal, {
      props: finalProps,
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="modal" :title="title"><slot name="default" /><slot name="footer" /></div>',
            props: ['title', 'id', 'noStacking', 'noCloseOnBackdrop', 'size'],
          },
          'b-form-input': {
            template:
              '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" :rows="rows" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'rows'],
          },
          'b-form-select': {
            template:
              '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
            props: ['modelValue', 'options', 'size'],
          },
          'b-input-group': {
            template: '<div class="input-group"><slot /></div>',
          },
          'b-button': {
            template:
              '<button :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size'],
          },
          'v-icon': {
            template: '<i :class="icon" />',
            props: ['icon'],
          },
          NoticeMessage: {
            template: '<div class="notice" :class="variant"><slot /></div>',
            props: ['variant'],
          },
          SpinButton: {
            name: 'SpinButton',
            template:
              '<button class="spin-button" :data-label="label"><slot /></button>',
            props: [
              'label',
              'iconName',
              'spinclass',
              'variant',
              'flex',
              'iconClass',
            ],
            emits: ['handle'],
          },
          PostCode: {
            template: '<input class="postcode" :value="value" />',
            props: ['value', 'find'],
            emits: ['selected'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockModGroupStore.get.mockReturnValue({
      id: 123,
      namedisplay: 'Test Group',
      modsemail: 'mods@testgroup.org',
      groupemail: 'group@testgroup.org',
      url: 'https://test.ilovefreegle.org',
      membercount: 1000,
      modcount: 5,
      settings: {
        reposts: { offer: 7, wanted: 14 },
      },
    })
    mockModGroupStore.fetchIfNeedBeMT.mockResolvedValue({})
    mockMessageStore.approve.mockResolvedValue({})
    mockMessageStore.reject.mockResolvedValue({})
    mockMessageStore.reply.mockResolvedValue({})
    mockMessageStore.delete.mockResolvedValue({})
    mockMessageStore.hold.mockResolvedValue({})
    mockMessageStore.patch.mockResolvedValue({})
    mockMemberStore.reply.mockResolvedValue({})
    mockMemberStore.delete.mockResolvedValue({})
    mockUserStore.edit.mockResolvedValue({})
  })

  describe('rendering', () => {
    it('renders modal with correct structure and content', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.modal').attributes('title')).toContain(
        'Offer: Test item (Location)'
      )
      expect(wrapper.text()).toContain('From:')
      expect(wrapper.text()).toContain('To:')
      expect(wrapper.text()).toContain('Mod User')
      expect(wrapper.text()).toContain('Test User')
      expect(wrapper.text()).toContain('test@example.com')
      expect(wrapper.text()).not.toContain('users.ilovefreegle.org')
      expect(wrapper.find('input').exists()).toBe(true)
      expect(wrapper.find('textarea').exists()).toBe(true)
      expect(wrapper.text()).toContain('Cancel')
    })
  })

  describe('processLabel computed', () => {
    it.each([
      ['Approve', 'Send and Approve'],
      ['Approve Member', 'Send and Approve'],
      ['Reject', 'Send and Reject'],
      ['Leave', 'Send and Leave'],
      ['Delete', 'Send and Delete'],
      ['Edit', 'Save Edit'],
      ['Hold Message', 'Send and Hold'],
      ['Unknown', 'Send'],
    ])('returns correct label for %s action', (action, expected) => {
      const wrapper = mountComponent(
        {},
        { stdmsgData: createStdmsg({ action }) }
      )
      expect(wrapper.vm.processLabel).toBe(expected)
    })
  })

  describe('modstatus computed', () => {
    it.each([
      ['UNCHANGED', 'Unchanged'],
      ['MODERATED', 'Moderated'],
      ['DEFAULT', 'Group Settings'],
      ['PROHIBITED', "Can't Post"],
    ])('returns correct display text for %s status', (status, expected) => {
      const wrapper = mountComponent(
        {},
        { stdmsgData: createStdmsg({ newmodstatus: status }) }
      )
      expect(wrapper.vm.modstatus).toBe(expected)
    })
  })

  describe('emailfrequency computed', () => {
    it.each([
      ['DIGEST', 24],
      ['NONE', 0],
      ['SINGLE', -1],
      ['ANNOUNCEMENT', 0],
    ])(
      'returns correct frequency for %s delivery status',
      (status, expected) => {
        const wrapper = mountComponent(
          {},
          { stdmsgData: createStdmsg({ newdelstatus: status }) }
        )
        expect(wrapper.vm.emailfrequency).toBe(expected)
      }
    )
  })

  describe('warning computed', () => {
    it.each([
      ['Please use Yahoo Groups', 'Yahoo Groups'],
      ['Use the republisher tool', 'Republisher'],
      ['Try the messagemaker', 'Message Maker'],
      ['Visit the cafe section', 'ChitChat'],
      ['Post to the newsfeed', 'ChitChat'],
      ['Use freegledirect', 'Freegle Direct'],
      ['Visit www.freegle.in', "won't work with the www"],
      ['Visit http://example.com', 'https://'],
    ])('warns about deprecated terms in: %s', async (body, expectedWarning) => {
      const wrapper = mountComponent()
      wrapper.vm.body = body
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.warning).toContain(expectedWarning)
    })

    it.each([['This is a clean message'], ['Contact me at user@yahoo.com']])(
      'returns null for valid content: %s',
      async (body) => {
        const wrapper = mountComponent()
        wrapper.vm.body = body
        await wrapper.vm.$nextTick()
        expect(wrapper.vm.warning).toBeNull()
      }
    )
  })

  describe('groupid computed', () => {
    it('returns groupid from message groups', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.groupid).toBe(123)
    })

    it('returns null when message has no groups', () => {
      const wrapper = mountComponent(
        {},
        { message: createMessage({ groups: [] }) }
      )
      expect(wrapper.vm.groupid).toBeNull()
    })

    it('returns groupid from member when no message', () => {
      const member = createMember({ groupid: 789 })
      const wrapper = mountComponent(
        { messageid: null },
        { message: null, member }
      )
      expect(wrapper.vm.groupid).toBe(789)
    })
  })

  describe('user computed', () => {
    it('returns fromuser from message', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.user.displayname).toBe('Test User')
    })

    it('returns member user when no message', () => {
      const member = createMember({ displayname: 'Member User' })
      const wrapper = mountComponent(
        { messageid: null },
        { message: null, member }
      )
      expect(wrapper.vm.user.displayname).toBe('Member User')
    })
  })

  describe('toEmail computed', () => {
    it('returns preferred email from fromuser.emails', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.toEmail).toBe('test@example.com')
    })

    it('skips ilovefreegle emails', () => {
      const wrapper = mountComponent(
        {},
        {
          message: createMessage({
            fromuser: {
              id: 456,
              displayname: 'Test User',
              emails: [
                { email: 'user@users.ilovefreegle.org', preferred: true },
                { email: 'real@example.com', preferred: false },
              ],
            },
          }),
        }
      )
      expect(wrapper.vm.toEmail).toBe('real@example.com')
    })

    it('returns member email when no message', () => {
      const member = createMember({ email: 'member@test.com' })
      const wrapper = mountComponent(
        { messageid: null },
        { message: null, member }
      )
      expect(wrapper.vm.toEmail).toBe('member@test.com')
    })
  })

  describe('modal movement', () => {
    it('adjusts margins in all directions', () => {
      const wrapper = mountComponent()
      const initialLeft = wrapper.vm.margLeft
      const initialTop = wrapper.vm.margTop

      wrapper.vm.moveLeft()
      expect(wrapper.vm.margLeft).toBe(initialLeft - 10)

      wrapper.vm.moveRight()
      expect(wrapper.vm.margLeft).toBe(initialLeft)

      wrapper.vm.moveUp()
      expect(wrapper.vm.margTop).toBe(initialTop - 10)

      wrapper.vm.moveDown()
      expect(wrapper.vm.margTop).toBe(initialTop)
    })
  })

  describe('Edit action specific', () => {
    it('shows edit-specific UI without From/To fields', () => {
      const wrapper = mountComponent(
        {},
        { stdmsgData: createStdmsg({ action: 'Edit' }) }
      )
      expect(wrapper.text()).not.toContain('From:')
      expect(wrapper.text()).not.toContain('To:')
      expect(wrapper.find('select').exists()).toBe(true)
      expect(wrapper.find('.postcode').exists()).toBe(true)
    })
  })

  describe('status change indicators', () => {
    it('shows status change indicators for modstatus, delstatus, and hold actions', () => {
      let wrapper = mountComponent(
        {},
        { stdmsgData: createStdmsg({ newmodstatus: 'MODERATED' }) }
      )
      expect(wrapper.text()).toContain('Change moderation status to')
      expect(wrapper.text()).toContain('Moderated')

      wrapper = mountComponent(
        {},
        { stdmsgData: createStdmsg({ newdelstatus: 'DIGEST' }) }
      )
      expect(wrapper.text()).toContain('Change email frequency to')

      wrapper = mountComponent(
        {},
        { stdmsgData: createStdmsg({ action: 'Hold Message' }) }
      )
      expect(wrapper.text()).toContain('Hold message')
    })
  })

  describe('props', () => {
    it('accepts stdmsgid prop and resolves from store', () => {
      const stdmsgData = createStdmsg({ action: 'Reject' })
      const wrapper = mountComponent(
        { stdmsgid: stdmsgData.id },
        { stdmsgData }
      )
      expect(wrapper.vm.stdmsg.action).toBe('Reject')
    })

    it('accepts stdmsgaction prop for inline actions', () => {
      const wrapper = mountComponent({
        stdmsgaction: 'Leave Member',
      })
      expect(wrapper.vm.stdmsg.action).toBe('Leave Member')
    })
  })

  describe('exposed methods', () => {
    it.each(['fillin', 'show', 'modal'])('exposes %s', (exposed) => {
      const wrapper = mountComponent()
      expect(wrapper.vm[exposed]).toBeDefined()
    })
  })

  describe('member mode', () => {
    it('uses member displayname in title when no message', () => {
      const member = createMember({
        displayname: 'Test Member',
        email: 'member@test.com',
      })
      const wrapper = mountComponent(
        { messageid: null },
        { message: null, member }
      )
      expect(wrapper.find('.modal').attributes('title')).toContain(
        'Message to Test Member'
      )
      expect(wrapper.vm.toEmail).toBe('member@test.com')
    })
  })

  describe('Edit action - empty content handling', () => {
    it('saves empty content without validation blocking it', async () => {
      const originalMessage = createMessage({
        id: 101,
        subject: 'Offer: Test item (Location)',
        textbody: 'Original message content that should not be restored',
      })

      const wrapper = mountComponent(
        {},
        {
          message: originalMessage,
          stdmsgData: createStdmsg({ action: 'Edit' }),
        }
      )

      // Simulate fillin() being called to populate initial values
      await wrapper.vm.fillin()
      await wrapper.vm.$nextTick()

      // At this point, body should contain the original message text
      expect(wrapper.vm.body).toContain('Original message content')

      // User edits the message to be empty (the bug: empty content gets reverted)
      wrapper.vm.body = ''
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.body).toBe('')

      // User clicks Save
      await wrapper.vm.process()
      await wrapper.vm.$nextTick()

      // The component should have called patch() with the empty textbody
      // If the bug exists, patch() may not be called, or may be called with original content
      expect(mockMessageStore.patch).toHaveBeenCalled()
      expect(mockMessageStore.patch).toHaveBeenCalledWith(
        expect.objectContaining({
          id: 101,
          textbody: '', // Should send empty content to backend
        })
      )
    })

    it('does not revert body after successful edit with non-empty content', async () => {
      const originalMessage = createMessage({
        id: 102,
        subject: 'Offer: Test item (Location)',
        textbody: 'Original content here',
      })

      const wrapper = mountComponent(
        {},
        {
          message: originalMessage,
          stdmsgData: createStdmsg({ action: 'Edit' }),
        }
      )

      await wrapper.vm.fillin()
      await wrapper.vm.$nextTick()

      const initialBody = wrapper.vm.body
      expect(initialBody).toContain('Original content')

      // User changes the text to something different
      const newContent = 'Completely new content'
      wrapper.vm.body = newContent
      await wrapper.vm.$nextTick()

      // Before save, body should be the new content
      expect(wrapper.vm.body).toBe(newContent)

      // User clicks Save
      await wrapper.vm.process()
      await wrapper.vm.$nextTick()

      // After save, body should NOT revert to the original
      // This test fails if body is reset to initialBody
      expect(wrapper.vm.body).not.toBe(initialBody)
      expect(wrapper.vm.body).toBe(newContent)
    })

    it('should allow and properly save empty content on edit', async () => {
      // Test for bug: when editing an approved post and clearing content,
      // the system should allow saving empty content, not revert to original
      const message = createMessage({
        id: 103,
        subject: 'Offer: Items for free',
        textbody: 'Available: 5 boxes, 10 bags',
      })

      const wrapper = mountComponent(
        {},
        {
          message,
          stdmsgData: createStdmsg({ action: 'Edit' }),
        }
      )

      await wrapper.vm.fillin()
      await wrapper.vm.$nextTick()

      // At this point, body has the original message text
      const bodyBeforeEdit = wrapper.vm.body
      expect(bodyBeforeEdit).toEqual(expect.stringContaining('Available: 5 boxes'))

      // User deletes all content (clears the textarea)
      wrapper.vm.body = ''
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.body).toBe('')

      // User clicks Save with empty content
      await wrapper.vm.process()
      await wrapper.vm.$nextTick()

      // KEY ASSERTION: patch() must be called with empty textbody
      // If the bug exists, patch() will either:
      // 1. Not be called at all (validation silently blocks it), OR
      // 2. Be called with the original content instead of empty string
      expect(mockMessageStore.patch).toHaveBeenCalled()
      const patchCall = mockMessageStore.patch.mock.calls[0]
      expect(patchCall[0]).toEqual(
        expect.objectContaining({
          id: 103,
          textbody: '', // BUG: if this is not empty, the save is not working correctly
        })
      )
    })

    it('should not revert edited content when fillin is called after save', async () => {
      // Bug scenario: If fillin() is called again after save (e.g., modal is reused
      // or component reactivity triggers refresh), the body should not be overwritten
      // with the original message content for Edit actions.
      const message = createMessage({
        id: 104,
        subject: 'Offer: Test',
        textbody: 'Original content that was edited',
      })

      const editStdmsg = createStdmsg({ action: 'Edit' })
      const wrapper = mountComponent(
        {},
        {
          message,
          stdmsgData: editStdmsg,
        }
      )

      // Initial fill-in
      await wrapper.vm.fillin()
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.body).toContain('Original content')

      // User edits the body to be empty
      wrapper.vm.body = ''
      await wrapper.vm.$nextTick()

      // Simulate a situation where fillin() is called again
      // (this could happen if modal is reused, or if there's unexpected reactivity)
      // After a successful save with empty content, if fillin() is called again,
      // it should NOT reset body back to the original message text
      // because for Edit actions, fillin() always populates body from message.textbody
      // which creates the reversion bug
      await wrapper.vm.fillin()
      await wrapper.vm.$nextTick()

      // BUG MANIFESTATION: body is reset to original message text
      // This test will FAIL with the current code because fillin() always
      // sets body to message.textbody for Edit actions
      // The body should remain empty or be explicitly managed, not auto-reverted
      expect(wrapper.vm.body).toBe('')
    })
  })
})
