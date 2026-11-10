import {
  describe,
  it,
  expect,
  vi,
  beforeEach,
  afterAll,
  beforeAll,
} from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModStdMessageModal from '~/modtools/components/ModStdMessageModal.vue'
import ModStdMessageSegments from '~/modtools/components/ModStdMessageSegments.vue'

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
          AutoHeightTextarea: {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'rows', 'maxRows', 'placeholder'],
          },
          // The real editor, so the tests see the boxes and buttons a moderator does.
          ModStdMessageSegments,
        },
      },
    })
  }

  // The laid-out editor for the post text (or the message, for actions other
  // than Edit and Message).
  const postEditor = (wrapper) =>
    wrapper.findAllComponents(ModStdMessageSegments)[0]

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
      ['Edit and Message', 'Save Edit and Send'],
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
    it('should not revert edited content when fillin is called after save', async () => {
      const wrapper = mountComponent(
        {},
        { stdmsgData: createStdmsg({ action: 'Edit', body: null }) }
      )

      // Initial fill-in from the message
      await wrapper.vm.fillin()
      expect(wrapper.vm.body).toBe('This is the message body.')

      // User deletes all content
      wrapper.vm.body = ''
      expect(wrapper.vm.body).toBe('')

      // After save, if fillin is called again, it should NOT revert the body
      // (This simulates a scenario where fillin might be called in a save handler)
      await wrapper.vm.fillin()

      // Body should remain empty, not revert to original message content
      expect(wrapper.vm.body).toBe('')
    })
  })

  describe('segmented editor — editable fixed prose', () => {
    const directiveStdmsg = () =>
      createStdmsg({
        action: 'Approve',
        body: 'Hello,\n\nThanks for using $groupname.\n\n<editthis>say something specific</editthis>\n\nCheers from the team.',
        insert: 'Top',
      })

    it('renders fixed prose as editable textareas, not static spans', async () => {
      const wrapper = mountComponent({}, { stdmsgData: directiveStdmsg() })
      await wrapper.vm.fillin()
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.useSegmentedEditor).toBe(true)
      // The whole message is editable now: fixed prose renders as a textarea, so
      // there is no longer a static (read-only) span for it.
      expect(wrapper.find('span.seg-text').exists()).toBe(false)
      expect(wrapper.find('textarea.seg-text').exists()).toBe(true)
    })

    // Discourse 10102/14: without a community the server applied a Support user's hold to
    // every copy of the post, so another community saw it "Held by" someone not its moderator.
    it('holds only the copy for the community it was sent from', async () => {
      const wrapper = mountComponent(
        {},
        { stdmsgData: createStdmsg({ action: 'Hold Message' }) }
      )
      await wrapper.vm.fillin()
      // Long enough to get past the "reply too short" check.
      wrapper.vm.body +=
        ' We are holding this post while we check a couple of details with you.'
      await wrapper.vm.process()

      expect(mockMessageStore.hold).toHaveBeenCalled()
      const arg = mockMessageStore.hold.mock.calls[0][0]
      expect(arg.groupid).toBeDefined()
      expect(arg.groupid).not.toBeNull()
    })

    it('includes edits to the fixed prose in the message that is sent', async () => {
      const wrapper = mountComponent({}, { stdmsgData: directiveStdmsg() })
      await wrapper.vm.fillin()

      // Personalise the must-fill box and tweak a bit of the surrounding prose.
      const editthis = wrapper.vm.segments.find((s) => s.type === 'editthis')
      editthis.value = 'Hope you find what you are looking for soon'
      const textSeg = wrapper.vm.segments.find((s) => s.type === 'text')
      textSeg.content += ' EDITED-PROSE-MARKER and a few more words.'

      await wrapper.vm.process()

      expect(mockMessageStore.approve).toHaveBeenCalled()
      // approve(messageid, groupid, subject, stdmsgid, body) — body is arg 5.
      const bodyArg = mockMessageStore.approve.mock.calls[0][4]
      expect(bodyArg).toContain('EDITED-PROSE-MARKER')
      expect(bodyArg).toContain('Hope you find what you are looking for soon')
      // Directive tags never reach the recipient.
      expect(bodyArg).not.toContain('<editthis>')
    })
  })

  // Shape of the standard messages moderators actually configure: a must-fill
  // box, then two optional bits separated by single line breaks.
  const optionalsStdmsg = () =>
    createStdmsg({
      action: 'Leave',
      body:
        'Hello,\n\nThanks for using $groupname.  <editthis></editthis>\n' +
        '<optional>You can edit your post at $editlink</optional>\n' +
        '<optional>Please get back to us if you have any questions.</optional>\n\n' +
        'With kind regards,\n$myname',
      insert: 'Top',
    })

  const optionalIndexes = (wrapper) =>
    wrapper.vm.segments
      .map((seg, i) => (seg.type === 'optional' ? i : -1))
      .filter((i) => i >= 0)

  describe('segmented editor — a send that is refused', () => {
    it('stops the send button spinning', async () => {
      const wrapper = mountComponent({}, { stdmsgData: optionalsStdmsg() })
      await wrapper.vm.fillin()

      const callback = vi.fn()
      await wrapper.vm.process(callback)

      expect(mockMessageStore.reply).not.toHaveBeenCalled()
      // Without this the button span on until SpinButton's 20s "callback
      // forgotten" timeout, which reads as the send having hung.
      expect(callback).toHaveBeenCalled()
    })

    it('says nothing has been sent and marks what is outstanding', async () => {
      const wrapper = mountComponent({}, { stdmsgData: optionalsStdmsg() })
      await wrapper.vm.fillin()

      await wrapper.vm.process(vi.fn())
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.directiveWarning).toContain('Nothing has been sent yet')
      expect(wrapper.vm.directiveWarning).toContain('Keep or Remove')
      // One must-fill box plus two undecided optionals.
      expect(wrapper.vm.blockedSegments).toHaveLength(3)
      expect(wrapper.find('.seg-blocked').exists()).toBe(true)
    })

    it('drops each mark as that bit is dealt with', async () => {
      const wrapper = mountComponent({}, { stdmsgData: optionalsStdmsg() })
      await wrapper.vm.fillin()
      await wrapper.vm.process(vi.fn())
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.blockedSegments).toHaveLength(3)

      const [first, second] = optionalIndexes(wrapper)
      postEditor(wrapper).vm.decideOptional(first, false)
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.blockedSegments).toHaveLength(2)

      postEditor(wrapper).vm.decideOptional(second, true)
      await wrapper.vm.$nextTick()
      // The wording keeps up, rather than still asking for the optionals once
      // they have both been decided.
      expect(wrapper.vm.blockedSegments).toHaveLength(1)
      expect(wrapper.vm.directiveWarning).not.toContain('Keep or Remove')
      expect(wrapper.vm.directiveWarning).toContain('highlighted box')

      wrapper.vm.segments.find((s) => s.type === 'editthis').value =
        'Please could you add the size to your post.'
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.blockedSegments).toHaveLength(0)
      expect(wrapper.vm.directiveWarning).toBe(null)
    })
  })

  describe('segmented editor — optional sections', () => {
    it('lets you undo a Keep and reword the wording you kept', async () => {
      const wrapper = mountComponent({}, { stdmsgData: optionalsStdmsg() })
      await wrapper.vm.fillin()

      wrapper.vm.segments.find((s) => s.type === 'editthis').value =
        'Please could you add the size to your post.'

      const [first, second] = optionalIndexes(wrapper)
      postEditor(wrapper).vm.decideOptional(first, false)
      postEditor(wrapper).vm.decideOptional(second, false)
      await wrapper.vm.$nextTick()

      // Kept wording is still an editable box, not fixed text.
      expect(wrapper.findAll('.seg-optional-kept textarea').length).toBe(2)

      wrapper.vm.segments[first].content =
        'You can edit your post on your My Posts page.'
      // Keep used to be final - you could only undo a Remove.
      postEditor(wrapper).vm.decideOptional(second, true)

      await wrapper.vm.process()

      const bodyArg = mockMessageStore.reply.mock.calls[0][0].body
      expect(bodyArg).toContain('You can edit your post on your My Posts page.')
      expect(bodyArg).not.toContain(
        'Please get back to us if you have any questions.'
      )
    })
  })

  describe('segmented editor — paragraph spacing', () => {
    it('shows the line break between blocks as a toggle, not an empty box', async () => {
      const wrapper = mountComponent({}, { stdmsgData: optionalsStdmsg() })
      await wrapper.vm.fillin()
      await wrapper.vm.$nextTick()

      // The single "\n" between the two optionals rendered as a full-height
      // empty textarea, which read as a field someone had forgotten to fill in.
      expect(wrapper.find('.seg-gap-toggle').exists()).toBe(true)
    })

    it('adds a blank line between paragraphs when toggled', async () => {
      const wrapper = mountComponent({}, { stdmsgData: optionalsStdmsg() })
      await wrapper.vm.fillin()

      wrapper.vm.segments.find((s) => s.type === 'editthis').value =
        'Please could you add the size to your post.'
      optionalIndexes(wrapper).forEach((i) =>
        postEditor(wrapper).vm.decideOptional(i, false)
      )

      const gaps = wrapper.vm.segments
        .map((seg, i) => (postEditor(wrapper).vm.isGap(seg) ? i : -1))
        .filter((i) => i >= 0)
      expect(gaps.length).toBeGreaterThan(0)
      expect(
        postEditor(wrapper).vm.isParagraphGap(wrapper.vm.segments[gaps[0]])
      ).toBe(false)

      gaps.forEach((i) => postEditor(wrapper).vm.toggleGap(i))
      expect(
        postEditor(wrapper).vm.isParagraphGap(wrapper.vm.segments[gaps[0]])
      ).toBe(true)

      await wrapper.vm.process()

      const bodyArg = mockMessageStore.reply.mock.calls[0][0].body
      expect(bodyArg).toContain(
        'add the size to your post.\n\nYou can edit your post at'
      )
    })

    it('leaves an inline space between blocks alone', async () => {
      const inline = createStdmsg({
        action: 'Leave',
        body: 'Hello. <optional>One.</optional> <optional>Two.</optional>\n\nBye.',
        insert: 'Top',
      })
      const wrapper = mountComponent({}, { stdmsgData: inline })
      await wrapper.vm.fillin()
      await wrapper.vm.$nextTick()

      // The single space between the two optionals is wording, not paragraph
      // spacing, so it must not become a blank-line control.
      expect(
        wrapper.vm.segments.some((s) => postEditor(wrapper).vm.isGap(s))
      ).toBe(false)
      expect(wrapper.find('.seg-gap-toggle').exists()).toBe(false)
    })

    it('takes the blank line back out again', async () => {
      const wrapper = mountComponent({}, { stdmsgData: optionalsStdmsg() })
      await wrapper.vm.fillin()

      const gap = wrapper.vm.segments.findIndex((seg) =>
        postEditor(wrapper).vm.isGap(seg)
      )
      postEditor(wrapper).vm.toggleGap(gap)
      expect(
        postEditor(wrapper).vm.isParagraphGap(wrapper.vm.segments[gap])
      ).toBe(true)
      postEditor(wrapper).vm.toggleGap(gap)
      expect(
        postEditor(wrapper).vm.isParagraphGap(wrapper.vm.segments[gap])
      ).toBe(false)
    })
  })

  describe('editLink — TrashNothing awareness', () => {
    const tnMessage = () =>
      createMessage({
        fromuser: {
          id: 456,
          displayname: 'TN User',
          tnuserid: 5555,
          emails: [],
        },
      })

    it('uses the Freegle myposts link for a normal member', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.isTnUser).toBe(false)
      expect(wrapper.vm.editLink).toBe('https://www.ilovefreegle.org/myposts')
    })

    it('uses the TrashNothing posts link when the member has a tnuserid', () => {
      const wrapper = mountComponent({}, { message: tnMessage() })
      expect(wrapper.vm.isTnUser).toBe(true)
      expect(wrapper.vm.editLink).toBe('https://trashnothing.com/user/posts')
    })

    it('detects TrashNothing from a trashnothing.com from-address', () => {
      const wrapper = mountComponent(
        {},
        { message: createMessage({ fromaddr: 'someone@trashnothing.com' }) }
      )
      expect(wrapper.vm.isTnUser).toBe(true)
      expect(wrapper.vm.editLink).toBe('https://trashnothing.com/user/posts')
    })

    it('substitutes $editlink with the TrashNothing link for a TN member', async () => {
      const wrapper = mountComponent({}, { message: tnMessage() })
      const out = await wrapper.vm.substitutionStrings('Edit here: $editlink')
      expect(out).toBe('Edit here: https://trashnothing.com/user/posts')
    })

    it('substitutes $editlink with the Freegle link for a normal member', async () => {
      const wrapper = mountComponent()
      const out = await wrapper.vm.substitutionStrings('Edit here: $editlink')
      expect(out).toBe('Edit here: https://www.ilovefreegle.org/myposts')
    })
  })
  // A post pending on several of a moderator's communities: the card hands the modal every
  // community to act on, and an Approve, Reject or Delete acts on all of them while the
  // member is written to once.
  describe('acting on several communities', () => {
    const LONG =
      ' Thanks - this has been checked and everything is in order now.'

    async function send(wrapper) {
      await wrapper.vm.fillin()
      wrapper.vm.body += LONG
      await wrapper.vm.process()
    }

    it('approves on every community and says so', async () => {
      const wrapper = mountComponent({ groupid: 123, groupids: [123, 124] })
      expect(
        wrapper.find('[data-test="stdmsg-several-communities"]').text()
      ).toContain('2 of your communities')

      await send(wrapper)

      const args = mockMessageStore.approve.mock.calls[0]
      expect(args[0]).toBe(101)
      expect(args[1]).toBe(123)
      expect(args[5]).toEqual([123, 124])
    })

    it('rejects on every community', async () => {
      const wrapper = mountComponent(
        { groupid: 123, groupids: [123, 124] },
        { stdmsgData: createStdmsg({ action: 'Reject' }) }
      )
      await send(wrapper)

      expect(mockMessageStore.reject.mock.calls[0][5]).toEqual([123, 124])
    })

    it('deletes from every community', async () => {
      const wrapper = mountComponent(
        { groupid: 123, groupids: [123, 124] },
        { stdmsgData: createStdmsg({ action: 'Delete' }) }
      )
      await send(wrapper)

      expect(mockMessageStore.delete.mock.calls[0][0].groupids).toEqual([
        123, 124,
      ])
    })

    it('calls the store exactly as before for one community', async () => {
      const wrapper = mountComponent({ groupid: 123 })
      expect(
        wrapper.find('[data-test="stdmsg-several-communities"]').exists()
      ).toBe(false)

      await send(wrapper)

      expect(mockMessageStore.approve.mock.calls[0]).toHaveLength(5)
    })

    it('leaves a reply to the member on its own community', async () => {
      const wrapper = mountComponent(
        { groupid: 123, groupids: [123, 124] },
        { stdmsgData: createStdmsg({ action: 'Leave' }) }
      )
      expect(
        wrapper.find('[data-test="stdmsg-several-communities"]').exists()
      ).toBe(false)

      await send(wrapper)

      expect(mockMessageStore.reply.mock.calls[0][0].groupid).toBe(123)
    })
  })

  // A moderator removing themselves through Delete Member is refused by the server with a
  // 403 and a message saying how to leave. It used to vanish, so nothing at all seemed to
  // happen (Discourse 10242).
  describe('removing yourself is refused', () => {
    const MSG =
      'You are a moderator of this community, so leaving would drop that role. To leave, change your own role to Member first, or ask another owner to.'

    async function sendRefused(error) {
      mockMemberStore.delete.mockRejectedValue(error)
      const member = createMember()
      const wrapper = mountComponent(
        { messageid: null, groupid: 123 },
        {
          message: null,
          member,
          stdmsgData: createStdmsg({ action: 'Delete Approved Member' }),
        }
      )
      await wrapper.vm.fillin()
      wrapper.vm.body += ' Thanks - this has been checked and all is in order.'
      const callback = vi.fn()
      await wrapper
        .findComponent({ name: 'SpinButton' })
        .vm.$emit('handle', callback)
      await flushPromises()
      return { wrapper, callback }
    }

    it('shows the server message and stops the spinner', async () => {
      const e = new Error('API Error POST /memberships -> status: 403')
      e.response = { status: 403, data: { error: 403, message: MSG } }
      const { wrapper, callback } = await sendRefused(e)

      expect(wrapper.find('.notice').text()).toContain(
        'change your own role to Member first'
      )
      expect(callback).toHaveBeenCalled()
    })
  })

  describe('Edit and Message', () => {
    const knivesStdmsg = (overrides = {}) =>
      createStdmsg({
        id: 7,
        action: 'Edit and Message',
        title: 'Knives',
        body: 'You must be 18 or over to collect.',
        insert: 'Bottom',
        subjpref: 'Re:',
        replybody: 'Hello $membername, we have added a note to your post.',
        ...overrides,
      })

    it('shows the post fields and the message to the poster', async () => {
      const wrapper = mountComponent({}, { stdmsgData: knivesStdmsg() })
      await wrapper.vm.fillin()
      await flushPromises()

      expect(wrapper.find('.postcode').exists()).toBe(true)
      expect(wrapper.text()).toContain('Post will read')
      expect(
        wrapper.find('[data-test="stdmsg-reply-section"]').text()
      ).toContain('Message to Test User')
    })

    it('adds the configured text to the post and fills in the message', async () => {
      const wrapper = mountComponent({}, { stdmsgData: knivesStdmsg() })
      await wrapper.vm.fillin()

      expect(wrapper.vm.body).toBe(
        'This is the message body.\n\nYou must be 18 or over to collect.'
      )
      expect(wrapper.vm.subject).toBe('Offer: Test item (Location)')
      expect(wrapper.vm.replySubject).toBe('Re: Offer: Test item (Location)')
      expect(wrapper.vm.replyBody).toBe(
        'Hello Test User, we have added a note to your post.'
      )
    })

    it('sends one patch with the edit and the message, as edited', async () => {
      const wrapper = mountComponent({}, { stdmsgData: knivesStdmsg() })
      await wrapper.vm.fillin()
      wrapper.vm.replyBody = 'Short note.'

      await wrapper.vm.process()

      expect(mockMessageStore.reply).not.toHaveBeenCalled()
      expect(mockMessageStore.patch).toHaveBeenCalledTimes(1)
      expect(mockMessageStore.patch).toHaveBeenCalledWith({
        id: 101,
        groupid: 123,
        msgtype: 'Offer',
        item: 'Test item',
        location: 'Location',
        textbody:
          'This is the message body.\n\nYou must be 18 or over to collect.',
        replysubject: 'Re: Offer: Test item (Location)',
        replybody: 'Short note.',
        stdmsgid: 7,
      })
      expect(wrapper.vm.replyTooShort).toBe(false)
      expect(mockHide).toHaveBeenCalled()
    })

    it('refuses an empty message without saving the edit', async () => {
      const wrapper = mountComponent({}, { stdmsgData: knivesStdmsg() })
      await wrapper.vm.fillin()
      wrapper.vm.replyBody = '   '

      const callback = vi.fn()
      await wrapper.vm.process(callback)

      expect(mockMessageStore.patch).not.toHaveBeenCalled()
      expect(wrapper.vm.directiveWarning).toContain('empty')
      expect(callback).toHaveBeenCalled()
    })

    it('refuses an un-personalised "edit this" in the message', async () => {
      const wrapper = mountComponent(
        {},
        {
          stdmsgData: knivesStdmsg({
            replybody: 'We added a note. <editthis>Say why</editthis>',
          }),
        }
      )
      await wrapper.vm.fillin()

      await wrapper.vm.process(vi.fn())

      expect(mockMessageStore.patch).not.toHaveBeenCalled()
      expect(wrapper.vm.directiveWarning).toContain('message to the poster')
    })

    // Reported 2026-11-09: an <optional> bit worked in the post text but not in the
    // message to the poster, which showed the raw tags in a plain box.
    describe('optional and fill-in bits in the message to the poster', () => {
      const optionalReply = () =>
        knivesStdmsg({
          replybody:
            'Hello $membername, we have added a note to your post.\n\n' +
            '<optional>Firewood must be dry and untreated.</optional>\n\n' +
            'Thanks.',
        })

      const replySection = (wrapper) =>
        wrapper.find('[data-test="stdmsg-reply-section"]')

      const clickInReply = async (wrapper, label) => {
        const button = replySection(wrapper)
          .findAll('button')
          .find((b) => b.text() === label)
        expect(button).toBeDefined()
        await button.trigger('click')
      }

      it('offers Keep and Remove rather than showing the tags', async () => {
        const wrapper = mountComponent({}, { stdmsgData: optionalReply() })
        await wrapper.vm.fillin()
        await flushPromises()

        const section = replySection(wrapper)
        expect(section.find('.seg-optional-undecided').exists()).toBe(true)
        expect(section.find('[data-test="stdmsg-reply-body"]').exists()).toBe(
          false
        )
        expect(section.html()).not.toContain('&lt;optional&gt;')
      })

      it('refuses to send until the optional bit is kept or removed', async () => {
        const wrapper = mountComponent({}, { stdmsgData: optionalReply() })
        await wrapper.vm.fillin()
        await flushPromises()

        const callback = vi.fn()
        await wrapper.vm.process(callback)
        await flushPromises()

        expect(mockMessageStore.patch).not.toHaveBeenCalled()
        expect(wrapper.vm.directiveWarning).toContain('Nothing has been saved')
        expect(wrapper.vm.directiveWarning).toContain('Keep or Remove')
        expect(wrapper.vm.directiveWarning).toContain('message to the poster')
        expect(replySection(wrapper).find('.seg-blocked').exists()).toBe(true)
        expect(callback).toHaveBeenCalled()
      })

      it('sends kept wording without its tags', async () => {
        const wrapper = mountComponent({}, { stdmsgData: optionalReply() })
        await wrapper.vm.fillin()
        await flushPromises()

        await clickInReply(wrapper, 'Keep')
        await wrapper.vm.process()

        expect(mockMessageStore.patch).toHaveBeenCalledTimes(1)
        expect(mockMessageStore.patch.mock.calls[0][0].replybody).toBe(
          'Hello Test User, we have added a note to your post.\n\n' +
            'Firewood must be dry and untreated.\n\n' +
            'Thanks.'
        )
      })

      it('leaves out a removed optional bit', async () => {
        const wrapper = mountComponent({}, { stdmsgData: optionalReply() })
        await wrapper.vm.fillin()
        await flushPromises()

        await clickInReply(wrapper, 'Remove')
        await wrapper.vm.process()

        expect(mockMessageStore.patch.mock.calls[0][0].replybody).toBe(
          'Hello Test User, we have added a note to your post.\n\nThanks.'
        )
      })

      it('sends a filled-in box in place of its placeholder', async () => {
        const wrapper = mountComponent(
          {},
          {
            stdmsgData: knivesStdmsg({
              replybody: 'We added a note. <editthis>Say why</editthis>',
            }),
          }
        )
        await wrapper.vm.fillin()
        await flushPromises()

        const box = replySection(wrapper).find('textarea.seg-editthis')
        expect(box.exists()).toBe(true)
        await box.setValue('Knives must be handed over in person.')
        await wrapper.vm.process()

        expect(mockMessageStore.patch.mock.calls[0][0].replybody).toBe(
          'We added a note. Knives must be handed over in person.'
        )
      })

      it('handles optional bits in the post and the message separately', async () => {
        const wrapper = mountComponent(
          {},
          {
            stdmsgData: knivesStdmsg({
              body: '<optional>Collection in person only.</optional>',
              replybody: optionalReply().replybody,
            }),
          }
        )
        await wrapper.vm.fillin()
        await flushPromises()

        // Deciding the message's optional leaves the post's one outstanding.
        await clickInReply(wrapper, 'Keep')
        await wrapper.vm.process(vi.fn())
        await flushPromises()

        expect(mockMessageStore.patch).not.toHaveBeenCalled()
        expect(wrapper.vm.directiveWarning).not.toContain(
          'message to the poster'
        )
        expect(replySection(wrapper).find('.seg-blocked').exists()).toBe(false)
        expect(wrapper.find('.seg-blocked').exists()).toBe(true)
      })
    })

    it('shows the server refusal and keeps the modal open', async () => {
      mockMessageStore.patch.mockRejectedValueOnce({
        response: {
          data: {
            message:
              'Only the community this was posted on can message the freegler about it',
          },
        },
      })
      const wrapper = mountComponent({}, { stdmsgData: knivesStdmsg() })
      await wrapper.vm.fillin()

      const callback = vi.fn()
      await wrapper.vm.process(callback)

      expect(wrapper.vm.sendError).toContain('Only the community')
      expect(mockHide).not.toHaveBeenCalled()
      expect(callback).toHaveBeenCalled()
    })
  })
})
