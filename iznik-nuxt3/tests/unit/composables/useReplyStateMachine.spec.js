import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref, customRef } from 'vue'
import {
  useReplyStateMachine,
  ReplyState,
  ReplyEvent,
} from '~/composables/useReplyStateMachine'
import { action as mockAction } from '~/composables/useClientLog'

// ============================================================
// vi.hoisted() — spy functions must be available before vi.mock() factories run
// ============================================================

const {
  mockClearReply,
  mockMessageFetch,
  mockMessageById,
  mockFetchMeFn,
  mockReplyToPostFn,
  mockSaveDraft,
  mockClearDraft,
  mockGroupFetch,
} = vi.hoisted(() => ({
  mockClearReply: vi.fn(),
  mockMessageFetch: vi.fn(),
  mockMessageById: vi.fn(),
  mockFetchMeFn: vi.fn(),
  mockReplyToPostFn: vi.fn(),
  mockSaveDraft: vi.fn(),
  mockClearDraft: vi.fn(),
  mockGroupFetch: vi.fn(),
}))

// ============================================================
// REPLY STORE MOCK — getters/setters allow per-test mutation
// ============================================================

let mockReplyMsgId = null
let mockReplyMessage = null
let mockReplyingAt = null
let mockMachineState = null
let mockReplyIsNewUser = false
let mockDraftMsgId = null
let mockDraftMessage = null
let mockDraftCollect = null
let mockDraftEmail = null
let mockDraftAt = null

vi.mock('~/stores/reply', () => ({
  useReplyStore: () => ({
    get replyMsgId() {
      return mockReplyMsgId
    },
    set replyMsgId(v) {
      mockReplyMsgId = v
    },
    get replyMessage() {
      return mockReplyMessage
    },
    set replyMessage(v) {
      mockReplyMessage = v
    },
    get replyingAt() {
      return mockReplyingAt
    },
    set replyingAt(v) {
      mockReplyingAt = v
    },
    get machineState() {
      return mockMachineState
    },
    set machineState(v) {
      mockMachineState = v
    },
    get isNewUser() {
      return mockReplyIsNewUser
    },
    set isNewUser(v) {
      mockReplyIsNewUser = v
    },
    get draftMsgId() {
      return mockDraftMsgId
    },
    get draftMessage() {
      return mockDraftMessage
    },
    get draftCollect() {
      return mockDraftCollect
    },
    get draftEmail() {
      return mockDraftEmail
    },
    get draftAt() {
      return mockDraftAt
    },
    clearReply: mockClearReply,
    saveDraft: mockSaveDraft,
    clearDraft: mockClearDraft,
  }),
}))

// ============================================================
// MESSAGE STORE MOCK
// ============================================================

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: mockMessageFetch,
    byId: mockMessageById,
  }),
}))

// ============================================================
// GROUP STORE MOCK — used to look up lat/lng for the "closest group" join pick
// ============================================================

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    fetch: mockGroupFetch,
  }),
}))

// ============================================================
// useMe MOCK — lazy getters avoid TDZ for mutable state
// ============================================================

let mockMeValue = null
let mockMyidValue = null
let mockMyGroupsValue = {}

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: {
      get value() {
        return mockMeValue
      },
    },
    myid: {
      get value() {
        return mockMyidValue
      },
    },
    myGroups: {
      get value() {
        return mockMyGroupsValue
      },
    },
    fetchMe: mockFetchMeFn,
  }),
  fetchMe: mockFetchMeFn,
}))

// ============================================================
// useReplyToPost MOCK
// ============================================================

vi.mock('~/composables/useReplyToPost', () => ({
  useReplyToPost: () => ({
    replyToPost: mockReplyToPostFn,
  }),
}))

// ============================================================
// useClientLog MOCK
// ============================================================

vi.mock('~/composables/useClientLog', () => ({
  action: vi.fn(),
}))

// ============================================================
// Auth store managed per-test via globalThis.__mockAuthStore
// storeToRefs(authStore) iterates raw store keys and crashes on `null` values,
// so forceLogin/loggedInEver are Vue refs and null-valued props are omitted.
// ============================================================

let mockAuthStore
let mockForceLogin
let mockLoggedInEver

// ============================================================
// TEST HELPERS
// ============================================================

const MSG_ID = 42

function makeFormRef(valid = true) {
  return { validate: vi.fn().mockResolvedValue({ valid }) }
}

function makeChatButtonRef() {
  return { openChat: vi.fn().mockResolvedValue(undefined) }
}

function mountComposable(messageId = MSG_ID, userAddImpl) {
  let result
  const mockUserAdd =
    userAddImpl || vi.fn().mockResolvedValue({ password: null })

  const Wrapper = defineComponent({
    setup() {
      result = useReplyStateMachine(messageId)
      return {}
    },
    render() {
      return null
    },
  })

  const wrapper = mount(Wrapper, {
    global: { mocks: { $api: { user: { add: mockUserAdd } } } },
  })

  return { result, mockUserAdd, wrapper }
}

// ============================================================
// SETUP / TEARDOWN
// ============================================================

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()

  // Use Vue refs for forceLogin/loggedInEver so storeToRefs() can pick them up.
  // Omit null-valued properties — storeToRefs iterates raw keys and crashes on null.
  mockForceLogin = ref(false)
  mockLoggedInEver = ref(false)
  mockAuthStore = {
    forceLogin: mockForceLogin,
    loggedInEver: mockLoggedInEver,
    joinGroup: vi.fn().mockResolvedValue(undefined),
    setAuth: vi.fn(),
    fetchUser: vi.fn().mockResolvedValue(undefined),
  }
  globalThis.__mockAuthStore = mockAuthStore

  // Reply store
  mockReplyMsgId = null
  mockReplyMessage = null
  mockReplyingAt = null
  mockMachineState = null
  mockReplyIsNewUser = false
  mockDraftMsgId = null
  mockDraftMessage = null
  mockDraftCollect = null
  mockDraftEmail = null
  mockDraftAt = null
  mockSaveDraft.mockImplementation(({ msgId, message, collect, email }) => {
    mockDraftMsgId = msgId
    mockDraftMessage = message
    mockDraftCollect = collect
    mockDraftEmail = email
    mockDraftAt = Date.now()
  })
  mockClearDraft.mockImplementation(() => {
    mockDraftMsgId = null
    mockDraftMessage = null
    mockDraftCollect = null
    mockDraftEmail = null
    mockDraftAt = null
  })

  // useMe
  mockMeValue = null
  mockMyidValue = null
  mockMyGroupsValue = {}

  // Other mocks
  mockFetchMeFn.mockResolvedValue(undefined)
  mockMessageFetch.mockResolvedValue({
    id: MSG_ID,
    groups: [{ groupid: 100 }],
  })
  // Default: no group data (tests that care about distance configure this themselves).
  mockGroupFetch.mockResolvedValue(null)
  // Default: the post is reply-eligible (no reach block) unless a test overrides byId.
  mockMessageById.mockReturnValue(null)
  mockReplyToPostFn.mockResolvedValue(MSG_ID)
})

afterEach(() => {
  globalThis.__mockAuthStore = undefined
  vi.useRealTimers()
})

// ============================================================
// TESTS
// ============================================================

describe('ReplyState and ReplyEvent enums', () => {
  it('ReplyState has all expected values', () => {
    expect(ReplyState.IDLE).toBe('IDLE')
    expect(ReplyState.COMPOSING).toBe('COMPOSING')
    expect(ReplyState.VALIDATING).toBe('VALIDATING')
    expect(ReplyState.AUTHENTICATING).toBe('AUTHENTICATING')
    expect(ReplyState.JOINING_GROUP).toBe('JOINING_GROUP')
    expect(ReplyState.CREATING_CHAT).toBe('CREATING_CHAT')
    expect(ReplyState.SENDING).toBe('SENDING')
    expect(ReplyState.SHOWING_WELCOME).toBe('SHOWING_WELCOME')
    expect(ReplyState.COMPLETED).toBe('COMPLETED')
    expect(ReplyState.ERROR).toBe('ERROR')
  })

  it('ReplyEvent has all expected values', () => {
    expect(ReplyEvent.START_TYPING).toBe('START_TYPING')
    expect(ReplyEvent.SUBMIT).toBe('SUBMIT')
    expect(ReplyEvent.VALIDATION_PASSED).toBe('VALIDATION_PASSED')
    expect(ReplyEvent.VALIDATION_FAILED).toBe('VALIDATION_FAILED')
    expect(ReplyEvent.REGISTRATION_SUCCESS).toBe('REGISTRATION_SUCCESS')
    expect(ReplyEvent.LOGIN_SUCCESS).toBe('LOGIN_SUCCESS')
    expect(ReplyEvent.ERROR_OCCURRED).toBe('ERROR_OCCURRED')
    expect(ReplyEvent.AUTH_ERROR).toBe('AUTH_ERROR')
    expect(ReplyEvent.RETRY).toBe('RETRY')
    expect(ReplyEvent.CANCEL).toBe('CANCEL')
    expect(ReplyEvent.RESTORED).toBe('RESTORED')
    expect(ReplyEvent.TIMEOUT).toBe('TIMEOUT')
  })
})

describe('computed: canSend', () => {
  const cases = [
    ['IDLE', true],
    ['COMPOSING', true],
    ['ERROR', true],
    ['VALIDATING', false],
    ['AUTHENTICATING', false],
    ['JOINING_GROUP', false],
    ['CREATING_CHAT', false],
    ['SENDING', false],
    ['SHOWING_WELCOME', false],
    ['COMPLETED', false],
  ]

  cases.forEach(([state, expected]) => {
    it(`is ${expected} when state is ${state}`, () => {
      const { result } = mountComposable()
      result.state.value = ReplyState[state]
      expect(result.canSend.value).toBe(expected)
    })
  })
})

describe('computed: isProcessing', () => {
  const cases = [
    ['VALIDATING', true],
    ['AUTHENTICATING', true],
    ['JOINING_GROUP', true],
    ['CREATING_CHAT', true],
    ['SENDING', true],
    ['IDLE', false],
    ['COMPOSING', false],
    ['COMPLETED', false],
    ['ERROR', false],
  ]

  cases.forEach(([state, expected]) => {
    it(`is ${expected} when state is ${state}`, () => {
      const { result } = mountComposable()
      result.state.value = ReplyState[state]
      expect(result.isProcessing.value).toBe(expected)
    })
  })
})

describe('computed: showWelcomeModal', () => {
  it('is true only when state is SHOWING_WELCOME', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.SHOWING_WELCOME
    expect(result.showWelcomeModal.value).toBe(true)
  })

  it.each(['IDLE', 'COMPOSING', 'COMPLETED', 'ERROR'])(
    'is false when state is %s',
    (state) => {
      const { result } = mountComposable()
      result.state.value = ReplyState[state]
      expect(result.showWelcomeModal.value).toBe(false)
    }
  )
})

describe('computed: isComplete', () => {
  it('is true when state is COMPLETED', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.COMPLETED
    expect(result.isComplete.value).toBe(true)
  })

  it.each(['IDLE', 'COMPOSING', 'ERROR'])(
    'is false when state is %s',
    (state) => {
      const { result } = mountComposable()
      result.state.value = ReplyState[state]
      expect(result.isComplete.value).toBe(false)
    }
  )
})

describe('startTyping', () => {
  it('transitions IDLE → COMPOSING', () => {
    const { result } = mountComposable()
    expect(result.state.value).toBe(ReplyState.IDLE)
    result.startTyping()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('is a no-op when already COMPOSING', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.COMPOSING
    result.startTyping()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('is a no-op when state is ERROR', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.ERROR
    result.startTyping()
    expect(result.state.value).toBe(ReplyState.ERROR)
  })
})

describe('retry', () => {
  it('transitions to COMPOSING from ERROR and clears error', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.ERROR
    result.error.value = 'Something went wrong'
    result.retry()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(result.error.value).toBeNull()
  })

  it('can be called from any state', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.IDLE
    result.retry()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })
})

describe('reset', () => {
  it('resets all state and clears persisted state', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.ERROR
    result.error.value = 'oh no'
    result.isNewUser.value = true
    result.newUserPassword.value = 'pass123'

    result.reset()

    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(result.error.value).toBeNull()
    expect(result.isNewUser.value).toBe(false)
    expect(result.newUserPassword.value).toBeNull()
    expect(mockClearReply).toHaveBeenCalledOnce()
  })
})

describe('fallbackToComposing', () => {
  it.each(['VALIDATING', 'JOINING_GROUP', 'CREATING_CHAT', 'ERROR'])(
    'always transitions to COMPOSING from %s',
    (state) => {
      const { result } = mountComposable()
      result.state.value = ReplyState[state]
      result.fallbackToComposing('test')
      expect(result.state.value).toBe(ReplyState.COMPOSING)
    }
  )
})

describe('closeWelcomeModal', () => {
  it('transitions SHOWING_WELCOME → COMPLETED', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.SHOWING_WELCOME
    result.closeWelcomeModal()
    expect(result.state.value).toBe(ReplyState.COMPLETED)
    expect(mockClearReply).toHaveBeenCalled()
  })

  it('is a no-op when state is not SHOWING_WELCOME', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.COMPOSING
    result.closeWelcomeModal()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })
})

describe('getDebugInfo', () => {
  it('returns an object with all expected keys', () => {
    const { result } = mountComposable()
    const info = result.getDebugInfo()
    expect(info).toMatchObject({
      state: expect.any(String),
      previousState: null,
      error: null,
      isNewUser: false,
      hasReply: false,
      hasCollect: false,
      hasEmail: false,
      emailValid: false,
      isLoggedIn: false,
      myid: null,
      initialized: expect.any(Boolean),
    })
  })

  it('reflects logged-in user state', () => {
    mockMeValue = { id: 10, name: 'Alice' }
    mockMyidValue = 10
    const { result } = mountComposable()
    const info = result.getDebugInfo()
    expect(info.isLoggedIn).toBe(true)
    expect(info.myid).toBe(10)
  })
})

describe('setRefs', () => {
  it('stores refs and triggers initialize on first call', () => {
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(), chatButton: makeChatButtonRef() })
    expect(result.state.value).toBe(ReplyState.IDLE)
  })

  it('does not re-initialize on subsequent calls', () => {
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef() })
    result.state.value = ReplyState.COMPOSING
    result.setRefs({ form: makeFormRef() })
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })
})

describe('initialize', () => {
  it('starts IDLE when there is no saved reply', () => {
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
  })

  it('is idempotent — second call is a no-op', () => {
    const { result } = mountComposable()
    result.initialize()
    result.state.value = ReplyState.COMPOSING
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('starts IDLE when saved reply belongs to a different message', () => {
    mockReplyMsgId = 999
    mockReplyMessage = 'Hello'
    const { result } = mountComposable(MSG_ID)
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
  })

  it('discards a stale reply (>24h) and starts IDLE', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Old reply'
    mockReplyingAt = Date.now() - 25 * 60 * 60 * 1000
    mockMachineState = ReplyState.COMPOSING
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(mockClearReply).toHaveBeenCalled()
  })

  it('restores SHOWING_WELCOME state', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Hi there'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.SHOWING_WELCOME
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.SHOWING_WELCOME)
    expect(result.isNewUser.value).toBe(true)
  })

  it('clears COMPLETED saved state and starts IDLE', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Done'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.COMPLETED
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(mockClearReply).toHaveBeenCalled()
  })

  it('restores ERROR state as COMPOSING so user can retry', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'My reply'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.ERROR
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(result.replyText.value).toBe('My reply')
  })

  it('restores COMPOSING state to COMPOSING', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Half written'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.COMPOSING
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(result.replyText.value).toBe('Half written')
  })

  it('restores VALIDATING state as COMPOSING', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Validating reply'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.VALIDATING
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('restores AUTHENTICATING + logged-in as COMPOSING (auth completed)', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Auth reply'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.AUTHENTICATING
    mockMeValue = { id: 5 }
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('restores AUTHENTICATING + not logged-in as COMPOSING (awaiting_login)', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Auth reply'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.AUTHENTICATING
    mockMeValue = null
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('restores JOINING_GROUP + logged-in as COMPOSING (mid-process resume)', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Join reply'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.JOINING_GROUP
    mockMeValue = { id: 7 }
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('restores JOINING_GROUP + not logged-in as COMPOSING (auth_state_mismatch)', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Join reply'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.JOINING_GROUP
    mockMeValue = null
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('falls back to COMPOSING for an unknown saved state', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Unknown'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = 'TOTALLY_UNKNOWN_STATE'
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('restores reply text split on collect-times separator', () => {
    const replyPart = 'I would like this item'
    const collectPart = 'Monday morning, Tuesday evening'
    mockReplyMsgId = MSG_ID
    mockReplyMessage = `${replyPart}\r\n\r\nPossible collection times: ${collectPart}`
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.COMPOSING
    const { result } = mountComposable()
    result.initialize()
    expect(result.replyText.value).toBe(replyPart)
    expect(result.collectText.value).toBe(collectPart)
  })
})

describe('draft persistence (typing survives close/reopen)', () => {
  it('persists the draft shortly after typing', async () => {
    const { result } = mountComposable()
    result.initialize()

    result.replyText.value = 'I have one you can have'
    await flushPromises()
    vi.advanceTimersByTime(400)

    expect(mockSaveDraft).toHaveBeenCalledWith(
      expect.objectContaining({
        msgId: MSG_ID,
        message: 'I have one you can have',
      })
    )
  })

  it('debounces rapid typing into a single save', async () => {
    const { result } = mountComposable()
    result.initialize()

    result.replyText.value = 'I hav'
    await flushPromises()
    vi.advanceTimersByTime(200)
    result.replyText.value = 'I have one'
    await flushPromises()
    vi.advanceTimersByTime(200)
    expect(mockSaveDraft).not.toHaveBeenCalled()

    vi.advanceTimersByTime(200)
    expect(mockSaveDraft).toHaveBeenCalledTimes(1)
    expect(mockSaveDraft).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'I have one' })
    )
  })

  it('persists collection time and email alongside the message', async () => {
    const { result } = mountComposable()
    result.initialize()

    result.replyText.value = 'Yes please'
    result.collectText.value = 'Weekday evenings'
    result.email.value = 'someone@example.com'
    await flushPromises()
    vi.advanceTimersByTime(400)

    expect(mockSaveDraft).toHaveBeenCalledWith({
      msgId: MSG_ID,
      message: 'Yes please',
      collect: 'Weekday evenings',
      email: 'someone@example.com',
    })
  })

  it('clears the draft when the user empties every field', async () => {
    const { result } = mountComposable()
    result.initialize()

    result.replyText.value = 'Changed my mind'
    await flushPromises()
    vi.advanceTimersByTime(400)
    expect(mockSaveDraft).toHaveBeenCalled()

    result.replyText.value = ''
    await flushPromises()
    vi.advanceTimersByTime(400)
    expect(mockClearDraft).toHaveBeenCalled()
  })

  it('flushes a pending draft when the pane unmounts', async () => {
    const { result, wrapper } = mountComposable()
    result.initialize()

    result.replyText.value = 'about to close the pane'
    await flushPromises()
    wrapper.unmount()

    expect(mockSaveDraft).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'about to close the pane' })
    )
  })

  it('does not persist a draft while the reply is being sent', async () => {
    const { result } = mountComposable()
    result.initialize()
    result.state.value = ReplyState.SENDING

    result.replyText.value = 'mid-send edit'
    await flushPromises()
    vi.advanceTimersByTime(400)

    expect(mockSaveDraft).not.toHaveBeenCalled()
  })

  it('restores a draft on reopen as COMPOSING without sending anything', () => {
    mockDraftMsgId = MSG_ID
    mockDraftMessage = 'Half written'
    mockDraftCollect = 'Saturday morning'
    mockDraftEmail = 'me@example.com'
    mockDraftAt = Date.now() - 60 * 1000

    const { result } = mountComposable()
    result.initialize()

    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(result.replyText.value).toBe('Half written')
    expect(result.collectText.value).toBe('Saturday morning')
    expect(result.email.value).toBe('me@example.com')
    expect(mockReplyToPostFn).not.toHaveBeenCalled()
  })

  it('discards a stale draft (>24h) and starts IDLE', () => {
    mockDraftMsgId = MSG_ID
    mockDraftMessage = 'Ancient draft'
    mockDraftAt = Date.now() - 25 * 60 * 60 * 1000

    const { result } = mountComposable()
    result.initialize()

    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(result.replyText.value).toBe('')
    expect(mockClearDraft).toHaveBeenCalled()
  })

  it('ignores a draft belonging to a different message', () => {
    mockDraftMsgId = 999
    mockDraftMessage = 'Draft for another post'
    mockDraftAt = Date.now() - 60 * 1000

    const { result } = mountComposable(MSG_ID)
    result.initialize()

    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(result.replyText.value).toBe('')
  })

  it('prefers a pending-send reply over a draft when both exist', () => {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Committed at submit time'
    mockReplyingAt = Date.now() - 60 * 1000
    mockMachineState = ReplyState.COMPOSING
    mockDraftMsgId = MSG_ID
    mockDraftMessage = 'Older draft'
    mockDraftAt = Date.now() - 120 * 1000

    const { result } = mountComposable()
    result.initialize()

    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(result.replyText.value).toBe('Committed at submit time')
  })
})

describe('onLoginSuccess', () => {
  it('from AUTHENTICATING with reply text: resumes to join-group → completed', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 100 }],
    })
    mockReplyToPostFn.mockResolvedValue(MSG_ID)

    const { result } = mountComposable()
    // Call initialize() first so setRefs() below doesn't re-initialize and reset state
    result.initialize()
    result.state.value = ReplyState.AUTHENTICATING
    result.replyText.value = 'Hello there'
    result.setRefs({ chatButton: makeChatButtonRef() }) // now skips re-initialization

    await result.onLoginSuccess()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.COMPLETED)
    expect(mockClearReply).toHaveBeenCalled()
  })

  it('from COMPOSING with reply text: persists state, no state change', async () => {
    mockMeValue = { id: 10 }
    const { result } = mountComposable()
    result.initialize()
    result.state.value = ReplyState.COMPOSING
    result.replyText.value = 'Draft text'

    await result.onLoginSuccess()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('from COMPOSING without reply text: no-op', async () => {
    mockMeValue = { id: 10 }
    const { result } = mountComposable()
    result.initialize()
    result.state.value = ReplyState.COMPOSING
    result.replyText.value = ''

    await result.onLoginSuccess()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('from IDLE: no-op', async () => {
    const { result } = mountComposable()
    result.initialize()
    result.state.value = ReplyState.IDLE

    await result.onLoginSuccess()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.IDLE)
  })

  it('non-auth error in handleJoinGroup during resume transitions to ERROR', async () => {
    // handleJoinGroup catches errors internally and transitions to ERROR without
    // re-throwing, so onLoginSuccess's catch block is not reached.
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMessageFetch.mockRejectedValue(new Error('Network error'))

    const { result } = mountComposable()
    result.initialize()
    result.state.value = ReplyState.AUTHENTICATING
    result.replyText.value = 'Reply text'

    await result.onLoginSuccess()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.ERROR)
  })
})

describe('submit', () => {
  it('is blocked when canSend is false (VALIDATING)', async () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.VALIDATING
    const callback = vi.fn()
    await result.submit(callback)
    expect(callback).toHaveBeenCalled()
    expect(result.state.value).toBe(ReplyState.VALIDATING)
  })

  it('falls back to COMPOSING when formRef is not set', async () => {
    const { result } = mountComposable()
    result.startTyping()
    const callback = vi.fn()
    // The ref never arrives, so submit() waits on the readiness watch until its
    // timeout before falling back. Advance past the timeout (fake timers) so the
    // pending submit settles rather than hanging.
    const pending = result.submit(callback)
    await vi.advanceTimersByTimeAsync(5001)
    await pending
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(callback).toHaveBeenCalled()
  })

  it('waits for a late formRef instead of erroring (CI race tolerance)', async () => {
    // Reproduces the e2e flake: the form ref lags the Send click, so it is
    // briefly null when submit() runs. submit() should wait on the readiness
    // watch and proceed to validate once setRefs() supplies the ref, rather than
    // logging an error and falling back.
    const { result } = mountComposable()
    // Initialise first (without a form ref) so supplying the form during the
    // wait below doesn't re-initialise the machine mid-submit.
    result.setRefs({ chatButton: makeChatButtonRef() })
    result.startTyping()
    const formRef = makeFormRef(true)
    const callback = vi.fn()

    const pending = result.submit(callback) // formRef null here → enters wait
    result.setRefs({ form: formRef }) // ref wires up during the wait → watch fires
    await pending

    expect(formRef.validate).toHaveBeenCalled()
  })

  it('falls back to COMPOSING when formRef.validate throws', async () => {
    const { result } = mountComposable()
    result.startTyping()
    result.setRefs({
      form: {
        validate: vi.fn().mockRejectedValue(new Error('Validate error')),
      },
    })
    const callback = vi.fn()
    await result.submit(callback)
    await flushPromises()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(callback).toHaveBeenCalled()
  })

  it('transitions to COMPOSING when form validation fails', async () => {
    const { result } = mountComposable()
    result.startTyping()
    result.setRefs({ form: makeFormRef(false) })
    const callback = vi.fn()
    await result.submit(callback)
    await flushPromises()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(callback).toHaveBeenCalled()
  })

  it('logged-in + already a member → COMPLETED', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 100 }],
    })
    mockReplyToPostFn.mockResolvedValue(MSG_ID)

    const { result } = mountComposable()
    result.startTyping()
    result.replyText.value = 'My reply message'
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })

    const callback = vi.fn()
    await result.submit(callback)
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.COMPLETED)
    expect(callback).toHaveBeenCalled()
  })

  it('logged-in new user → SHOWING_WELCOME after sending', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 100 }],
    })
    mockReplyToPostFn.mockResolvedValue(MSG_ID)

    const { result } = mountComposable()
    result.startTyping()
    result.isNewUser.value = true
    result.replyText.value = 'New user reply'
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })

    await result.submit()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.SHOWING_WELCOME)
  })

  it('not-logged-in with invalid email → COMPOSING', async () => {
    mockMeValue = null
    const { result } = mountComposable()
    result.startTyping()
    result.replyText.value = 'Hello'
    result.email.value = 'bad-email'
    result.emailValid.value = false
    result.setRefs({ form: makeFormRef(true) })

    const callback = vi.fn()
    await result.submit(callback)
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(callback).toHaveBeenCalled()
  })

  it('not-logged-in, new user: registers, sets auth, shows welcome modal', async () => {
    mockMeValue = null
    const newUserApiResponse = {
      password: 'generated-password',
      jwt: 'test-jwt',
      persistent: { userid: 99 },
    }
    const mockUserAdd = vi.fn().mockResolvedValue(newUserApiResponse)

    mockFetchMeFn.mockImplementation(() => {
      mockMeValue = { id: 99 }
      mockMyidValue = 99
    })
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 100 }],
    })
    mockReplyToPostFn.mockResolvedValue(MSG_ID)

    const { result } = mountComposable(MSG_ID, mockUserAdd)
    result.startTyping()
    result.replyText.value = 'New user message'
    result.email.value = 'newuser@example.com'
    result.emailValid.value = true
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })

    await result.submit()
    await flushPromises()

    expect(mockAuthStore.setAuth).toHaveBeenCalledWith('test-jwt', {
      userid: 99,
    })
    expect(result.isNewUser.value).toBe(true)
    expect(result.state.value).toBe(ReplyState.SHOWING_WELCOME)
  })

  it('not-logged-in, existing user: sets forceLogin for login modal', async () => {
    mockMeValue = null
    const mockUserAdd = vi.fn().mockResolvedValue({ password: null })

    const { result } = mountComposable(MSG_ID, mockUserAdd)
    result.startTyping()
    result.replyText.value = 'Existing user message'
    result.email.value = 'existing@example.com'
    result.emailValid.value = true
    result.setRefs({ form: makeFormRef(true) })

    const callback = vi.fn()
    await result.submit(callback)
    await flushPromises()

    expect(mockForceLogin.value).toBe(true)
    expect(callback).toHaveBeenCalled()
  })

  it('not-logged-in, registration error: forces login as fallback', async () => {
    mockMeValue = null
    const mockUserAdd = vi
      .fn()
      .mockRejectedValue(new Error('Registration failed'))

    const { result } = mountComposable(MSG_ID, mockUserAdd)
    result.startTyping()
    result.replyText.value = 'Test'
    result.email.value = 'test@example.com'
    result.emailValid.value = true
    result.setRefs({ form: makeFormRef(true) })

    const callback = vi.fn()
    await result.submit(callback)
    await flushPromises()

    expect(mockForceLogin.value).toBe(true)
    expect(callback).toHaveBeenCalled()
  })

  it('not-logged-in, 401 auth error: triggers auth error flow → AUTHENTICATING', async () => {
    mockMeValue = null
    const authError = Object.assign(new Error('not logged in'), { status: 401 })
    const mockUserAdd = vi.fn().mockRejectedValue(authError)

    const { result } = mountComposable(MSG_ID, mockUserAdd)
    result.startTyping()
    result.replyText.value = 'Test'
    result.email.value = 'test@example.com'
    result.emailValid.value = true
    result.setRefs({ form: makeFormRef(true) })

    await result.submit()
    await flushPromises()

    expect(mockForceLogin.value).toBe(true)
    expect(result.state.value).toBe(ReplyState.AUTHENTICATING)
  })
})

describe('handleJoinGroup (via submit with logged-in user)', () => {
  async function doLoggedInSubmit(result) {
    result.startTyping()
    result.replyText.value = 'My reply'
    await result.submit()
    await flushPromises()
  }

  it('joins group when user is not already a member', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = {} // no memberships
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 200 }],
    })
    mockReplyToPostFn.mockResolvedValue(MSG_ID)

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    expect(mockAuthStore.joinGroup).toHaveBeenCalledWith(10, 200, false)
    expect(result.state.value).toBe(ReplyState.COMPLETED)
  })

  it('skips joining when already a member', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 100 }],
    })
    mockReplyToPostFn.mockResolvedValue(MSG_ID)

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    expect(mockAuthStore.joinGroup).not.toHaveBeenCalled()
    expect(result.state.value).toBe(ReplyState.COMPLETED)
  })

  it('transitions to ERROR when message has no groups', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups: [] })

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    expect(result.state.value).toBe(ReplyState.ERROR)
    expect(result.error.value).toBeTruthy()
  })

  it('transitions to ERROR when message fetch fails', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMessageFetch.mockRejectedValue(new Error('Network error'))

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    expect(result.state.value).toBe(ReplyState.ERROR)
  })

  it('triggers auth error flow when message fetch returns 401', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    const authErr = Object.assign(new Error('session expired'), { status: 401 })
    mockMessageFetch.mockRejectedValue(authErr)

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true) })
    await doLoggedInSubmit(result)

    expect(mockForceLogin.value).toBe(true)
    expect(result.state.value).toBe(ReplyState.AUTHENTICATING)
  })

  it('triggers auth error when myid is null (session expired)', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = null // no ID despite me being set

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true) })
    await doLoggedInSubmit(result)

    expect(mockForceLogin.value).toBe(true)
    expect(result.state.value).toBe(ReplyState.AUTHENTICATING)
  })
})

// ============================================================
// Multi-group posts: which group do we auto-join?
//
// Requirement (Edward, 2026-08-02): only join when the replier has no group in
// common with the post, and when we do join, pick the group CLOSEST to the
// replier - not the post's origin/home group, and not whichever group happens to
// be first or last in msg.groups (API ordering is arbitrary). Regression case:
// Glen replied to a post on Runcton-area Portsmouth_Freegle and was auto-joined
// to Portsmouth, nowhere near him. See
// plans/2026-08-02-reply-join-closest-group.md.
// ============================================================
describe('handleJoinGroup: closest-group selection for multi-group posts', () => {
  async function doLoggedInSubmit(result) {
    result.startTyping()
    result.replyText.value = 'My reply'
    await result.submit()
    await flushPromises()
  }

  // Replier is in central London. GROUP_FAR (Edinburgh) is ~330 miles away,
  // GROUP_MEDIUM (Birmingham) ~100 miles, GROUP_CLOSEST ~1 mile. The message lists
  // them far/closest/medium - so the closest group sits in the MIDDLE of the
  // array, not first (would pass under a naive "first wins" bug) and not last
  // (today's actual bug - groupToJoin is overwritten on every loop iteration and
  // ends up as the last entry).
  const REPLIER_LAT = 51.5074
  const REPLIER_LNG = -0.1278
  const GROUP_FAR = { id: 301, lat: 55.9533, lng: -3.1883 } // Edinburgh
  const GROUP_CLOSEST = { id: 302, lat: 51.51, lng: -0.13 } // Central London
  const GROUP_MEDIUM = { id: 303, lat: 52.4862, lng: -1.8904 } // Birmingham

  function mockGroupFixtures() {
    mockGroupFetch.mockImplementation((id) =>
      Promise.resolve(
        {
          [GROUP_FAR.id]: GROUP_FAR,
          [GROUP_CLOSEST.id]: GROUP_CLOSEST,
          [GROUP_MEDIUM.id]: GROUP_MEDIUM,
        }[id] || null
      )
    )
  }

  it('no join at all when the replier is already a member of one of the groups', async () => {
    mockMeValue = { id: 10, lat: REPLIER_LAT, lng: REPLIER_LNG }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: GROUP_MEDIUM.id } } // member of one of the three
    mockGroupFixtures()
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [
        { groupid: GROUP_FAR.id },
        { groupid: GROUP_CLOSEST.id },
        { groupid: GROUP_MEDIUM.id },
      ],
    })

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    expect(mockAuthStore.joinGroup).not.toHaveBeenCalled()
    expect(result.state.value).toBe(ReplyState.COMPLETED)
  })

  it('joins the group closest to the replier when there is no overlap - not first, not last', async () => {
    mockMeValue = { id: 10, lat: REPLIER_LAT, lng: REPLIER_LNG }
    mockMyidValue = 10
    mockMyGroupsValue = {} // no memberships at all
    mockGroupFixtures()
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [
        { groupid: GROUP_FAR.id }, // first
        { groupid: GROUP_CLOSEST.id }, // middle - the correct pick
        { groupid: GROUP_MEDIUM.id }, // last
      ],
    })

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    expect(mockAuthStore.joinGroup).toHaveBeenCalledWith(
      10,
      GROUP_CLOSEST.id,
      false
    )
    expect(result.state.value).toBe(ReplyState.COMPLETED)
  })

  it('falls back to the last group in the list when the replier has no known location', async () => {
    mockMeValue = { id: 10 } // no lat/lng
    mockMyidValue = 10
    mockMyGroupsValue = {}
    mockGroupFixtures()
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [
        { groupid: GROUP_FAR.id },
        { groupid: GROUP_CLOSEST.id },
        { groupid: GROUP_MEDIUM.id }, // last - previous (arbitrary) behaviour
      ],
    })

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    expect(mockAuthStore.joinGroup).toHaveBeenCalledWith(
      10,
      GROUP_MEDIUM.id,
      false
    )
    expect(result.state.value).toBe(ReplyState.COMPLETED)
    // No location known, so there's nothing to look distances up against.
    expect(mockGroupFetch).not.toHaveBeenCalled()
  })

  it('joins a single-group post directly without any group-store lookup, even with a known location', async () => {
    mockMeValue = { id: 10, lat: REPLIER_LAT, lng: REPLIER_LNG }
    mockMyidValue = 10
    mockMyGroupsValue = {} // no memberships
    mockGroupFixtures()
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: GROUP_CLOSEST.id }],
    })

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    expect(mockAuthStore.joinGroup).toHaveBeenCalledWith(
      10,
      GROUP_CLOSEST.id,
      false
    )
    expect(result.state.value).toBe(ReplyState.COMPLETED)
    // A single-group post has a forced answer - no need to look up distance.
    expect(mockGroupFetch).not.toHaveBeenCalled()
  })
})

describe('handleCreateChat (via submit with logged-in user)', () => {
  function setupLoggedIn() {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 100 }],
    })
  }

  it('transitions to ERROR when replyToPost returns falsy', async () => {
    await setupLoggedIn()
    mockReplyToPostFn.mockResolvedValue(null)

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.ERROR)
    expect(result.error.value).toContain('stale')
  })

  it('transitions to ERROR when replyToPost throws a non-auth error', async () => {
    await setupLoggedIn()
    mockReplyToPostFn.mockRejectedValue(new Error('Chat server down'))

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.ERROR)
  })

  it('triggers auth error when replyToPost throws 403', async () => {
    await setupLoggedIn()
    const authErr = Object.assign(new Error('unauthorized'), { status: 403 })
    mockReplyToPostFn.mockRejectedValue(authErr)

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()

    expect(mockForceLogin.value).toBe(true)
    expect(result.state.value).toBe(ReplyState.AUTHENTICATING)
  })

  it('falls back to COMPOSING when chatButtonRef is still null after 5s wait', async () => {
    await setupLoggedIn()

    const { result } = mountComposable()
    // Only set form ref, no chatButton
    result.setRefs({ form: makeFormRef(true) })
    result.startTyping()
    result.replyText.value = 'Hello'

    const submitPromise = result.submit()
    // Advance past the 5-second wait in handleCreateChat
    await vi.runAllTimersAsync()
    await submitPromise
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })
})

describe('processing timeout', () => {
  it('falls back to COMPOSING after 30s stuck in a processing state', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    // Message fetch never resolves — keeps state machine stuck in JOINING_GROUP
    mockMessageFetch.mockImplementation(() => new Promise(() => {}))

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Test'

    result.submit() // intentionally not awaited — it's stuck

    await flushPromises()
    expect(result.isProcessing.value).toBe(true)

    // Advance past 30s timeout
    await vi.advanceTimersByTimeAsync(31000)

    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })
})

// ============================================================
// Rippling-out reach gate — a post rippled to the member's community but whose reach polygon
// has not yet reached their location: replyeligible=false (read path). The reply is now ACCEPTED
// and HELD server-side rather than blocked, so there is no longer a proactive block. A 403
// "not_in_reach" from the send remains only a deploy-window backstop: it must show the graceful
// "closest first" message and NEVER force a re-login. Regression: Marc Ashby, 2026-07-04
// (Henley post rippled into Reading).
// ============================================================
describe('reach gate (rippling-out reply eligibility)', () => {
  function setupLoggedIn() {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 100 }],
    })
  }

  const CLOSEST = 'closest to it first'

  it('no longer blocks a replyeligible=false reply — it lets the send proceed (held server-side)', async () => {
    await setupLoggedIn()
    // The message the member is replying to is flagged not-yet-reachable by the server.
    mockMessageById.mockReturnValue({ id: MSG_ID, replyeligible: false })

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()

    // The reply is now sent (the server accepts and HOLDS it) rather than blocked with the old
    // "closest first" error, and the member is never bounced to a login.
    expect(mockReplyToPostFn).toHaveBeenCalled()
    expect(result.state.value).not.toBe(ReplyState.ERROR)
    expect(mockForceLogin.value).toBe(false)
  })

  it('does NOT block when replyeligible is true / absent (normal reply proceeds)', async () => {
    await setupLoggedIn()
    mockMessageById.mockReturnValue({ id: MSG_ID }) // no replyeligible => eligible

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()

    expect(mockReplyToPostFn).toHaveBeenCalled()
    expect(
      result.error.value === null || !result.error.value.includes(CLOSEST)
    ).toBe(true)
  })

  it('reactively shows the reach message (not a login) on a 403 not_in_reach from the send', async () => {
    await setupLoggedIn()
    // Server body is { error: 403, message: "not_in_reach" } (see main.go ErrorHandler).
    const reachErr = Object.assign(new Error('Request failed'), {
      status: 403,
      response: { status: 403, data: { error: 403, message: 'not_in_reach' } },
    })
    mockReplyToPostFn.mockRejectedValue(reachErr)

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.ERROR)
    expect(result.error.value).toContain(CLOSEST)
    // The bug being fixed: a reach 403 must NOT force a re-login.
    expect(mockForceLogin.value).toBe(false)
  })

  it('does NOT treat other 403s (e.g. banned) as a reach block', async () => {
    await setupLoggedIn()
    const bannedErr = Object.assign(new Error('Request failed'), {
      status: 403,
      response: {
        status: 403,
        data: { error: 403, message: 'User banned from group' },
      },
    })
    mockReplyToPostFn.mockRejectedValue(bannedErr)

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()

    expect(result.state.value).toBe(ReplyState.ERROR)
    // Not the reach message — a different 403 must not be mislabelled as "closest first".
    expect(result.error.value).not.toContain(CLOSEST)
  })
})

// ============================================================
// Error classification (isNotInReachError / isAuthError), exercised through the
// catch block of handleJoinGroup, handleCreateChat and handleAuthentication.
// ============================================================

describe('error classification', () => {
  const CLOSEST = 'closest to it first'

  function httpError(message, props) {
    return Object.assign(new Error(message), props)
  }

  // outcome: 'reach' = graceful reach message, no login; 'auth' = forced re-login;
  // 'generic' = plain ERROR state with the error text.
  const rows = [
    [
      'top-level status 403 + not_in_reach in Error.message',
      httpError('not_in_reach', { status: 403 }),
      'reach',
    ],
    [
      'response.status 403 + not_in_reach in response.data.message',
      httpError('Request failed', {
        response: {
          status: 403,
          data: { error: 403, message: 'not_in_reach' },
        },
      }),
      'reach',
    ],
    [
      '403 + string response body containing not_in_reach',
      httpError('Request failed', {
        status: 403,
        response: { data: 'not_in_reach' },
      }),
      'reach',
    ],
    [
      '403 + object body on error.data (no response)',
      httpError('Request failed', {
        status: 403,
        data: { message: 'not_in_reach' },
      }),
      'reach',
    ],
    [
      '403 "User banned from group" is not a reach block',
      httpError('Request failed', {
        status: 403,
        response: { status: 403, data: { message: 'User banned from group' } },
      }),
      'generic',
    ],
    [
      '403 with no body at all',
      httpError('Forbidden', { status: 403 }),
      'generic',
    ],
    [
      '403 with null response body',
      httpError('Forbidden', {
        status: 403,
        response: { status: 403, data: null },
      }),
      'generic',
    ],
    [
      'not_in_reach text on a non-403 status',
      httpError('not_in_reach', { status: 500 }),
      'generic',
    ],
    ['plain Error', new Error('boom'), 'generic'],
    ['Error with empty message', new Error(''), 'generic'],
    ['empty-string rejection (falsy error)', '', 'generic'],
    ['status 401', httpError('Request failed', { status: 401 }), 'auth'],
    [
      'response.status 401',
      httpError('Request failed', { response: { status: 401 } }),
      'auth',
    ],
    ['message "not logged in"', new Error('You are not logged in'), 'auth'],
    ['message "unauthorized"', new Error('unauthorized'), 'auth'],
    ['message "session expired"', new Error('session expired'), 'auth'],
    ['message "login required"', new Error('login required'), 'auth'],
    ['string rejection falls back to toString()', 'unauthorized', 'auth'],
  ]

  function expectOutcome(result, outcome) {
    if (outcome === 'reach') {
      expect(result.state.value).toBe(ReplyState.ERROR)
      expect(result.error.value).toContain(CLOSEST)
      expect(mockForceLogin.value).toBe(false)
      expect(mockAction).toHaveBeenCalledWith('reply_blocked_not_in_reach', {
        message_id: MSG_ID,
      })
    } else if (outcome === 'auth') {
      expect(result.state.value).toBe(ReplyState.AUTHENTICATING)
      expect(mockForceLogin.value).toBe(true)
    } else {
      expect(result.state.value).toBe(ReplyState.ERROR)
      expect(result.error.value).not.toContain(CLOSEST)
      expect(mockForceLogin.value).toBe(false)
    }
  }

  async function submitLoggedIn() {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    const callback = vi.fn()
    await result.submit(callback)
    await flushPromises()
    return { result, callback }
  }

  it.each(rows)('handleJoinGroup: %s', async (_name, err, outcome) => {
    mockMessageFetch.mockRejectedValue(err)
    const { result, callback } = await submitLoggedIn()
    expectOutcome(result, outcome)
    expect(callback).toHaveBeenCalledTimes(1)
  })

  it.each(rows)('handleCreateChat: %s', async (_name, err, outcome) => {
    mockReplyToPostFn.mockRejectedValue(err)
    const { result, callback } = await submitLoggedIn()
    expectOutcome(result, outcome)
    expect(callback).toHaveBeenCalledTimes(1)
  })

  describe('handleAuthentication', () => {
    async function submitAnonymous(userAdd) {
      const { result } = mountComposable(MSG_ID, userAdd)
      result.setRefs({
        form: makeFormRef(true),
        chatButton: makeChatButtonRef(),
      })
      result.startTyping()
      result.replyText.value = 'Hello'
      result.email.value = 'a@example.com'
      result.emailValid.value = true
      const callback = vi.fn()
      await result.submit(callback)
      await flushPromises()
      return { result, callback }
    }

    it('not_in_reach shows the reach message without forcing login', async () => {
      const { result, callback } = await submitAnonymous(
        vi.fn().mockRejectedValue(httpError('not_in_reach', { status: 403 }))
      )
      expectOutcome(result, 'reach')
      expect(callback).toHaveBeenCalledTimes(1)
    })

    it('auth error goes back to AUTHENTICATING and forces login', async () => {
      const { result, callback } = await submitAnonymous(
        vi.fn().mockRejectedValue(httpError('nope', { status: 401 }))
      )
      expectOutcome(result, 'auth')
      expect(callback).toHaveBeenCalledTimes(1)
    })

    it('any other error assumes an existing user and forces login', async () => {
      const { result, callback } = await submitAnonymous(
        vi.fn().mockRejectedValue(new Error('network down'))
      )
      expect(mockForceLogin.value).toBe(true)
      expect(result.state.value).toBe(ReplyState.AUTHENTICATING)
      expect(callback).toHaveBeenCalledTimes(1)
    })

    it('existing user (no password in response) forces login', async () => {
      const { result, callback } = await submitAnonymous(
        vi.fn().mockResolvedValue({ password: null })
      )
      expect(mockForceLogin.value).toBe(true)
      expect(result.isNewUser.value).toBe(false)
      expect(callback).toHaveBeenCalledTimes(1)
    })

    it('new user registers, sets auth tokens, fetches me and joins', async () => {
      mockMyidValue = 77
      mockMeValue = null
      const { result } = await submitAnonymous(
        vi.fn().mockResolvedValue({
          password: 'pw',
          jwt: 'jwt',
          persistent: { userid: 77 },
        })
      )
      expect(result.isNewUser.value).toBe(true)
      expect(result.newUserPassword.value).toBe('pw')
      expect(mockLoggedInEver.value).toBe(true)
      expect(mockAuthStore.setAuth).toHaveBeenCalledWith('jwt', { userid: 77 })
      expect(mockFetchMeFn).toHaveBeenCalledWith(true)
      expect(result.state.value).toBe(ReplyState.SHOWING_WELCOME)
    })

    it('new user without jwt/persistent does not set auth', async () => {
      mockMyidValue = 77
      const { result } = await submitAnonymous(
        vi.fn().mockResolvedValue({ password: 'pw' })
      )
      expect(mockAuthStore.setAuth).not.toHaveBeenCalled()
      expect(result.isNewUser.value).toBe(true)
    })
  })
})

// ============================================================
// closestGroupToReplier edge cases
// ============================================================

describe('closestGroupToReplier edge cases', () => {
  async function joinWith({ me, groups, fetchImpl }) {
    mockMeValue = me
    mockMyidValue = 10
    mockMyGroupsValue = {}
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups })
    mockGroupFetch.mockImplementation(
      fetchImpl || (() => Promise.resolve(null))
    )
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()
    return result
  }

  const G = (id, lat, lng) => ({ id, lat, lng })

  it.each([
    [
      'single group: forced, no store lookup',
      { id: 10, lat: 51.5, lng: -0.1 },
      [{ groupid: 5 }],
      null,
      5,
      false,
    ],
    [
      'no replier location: last group',
      { id: 10 },
      [{ groupid: 5 }, { groupid: 6 }],
      null,
      6,
      false,
    ],
    [
      'replier lat/lng both 0: last group',
      { id: 10, lat: 0, lng: 0 },
      [{ groupid: 5 }, { groupid: 6 }],
      null,
      6,
      false,
    ],
    [
      'store returns null for some groups: nearest of the rest',
      { id: 10, lat: 51.5, lng: -0.1 },
      [{ groupid: 5 }, { groupid: 6 }, { groupid: 7 }],
      (id) =>
        Promise.resolve(
          { 5: null, 6: G(6, 55.9, -3.2), 7: G(7, 52.5, -1.9) }[id]
        ),
      7,
      true,
    ],
    [
      'store returns null for all groups: fallback to last',
      { id: 10, lat: 51.5, lng: -0.1 },
      [{ groupid: 5 }, { groupid: 6 }],
      () => Promise.resolve(null),
      6,
      true,
    ],
    [
      'all milesAway null (groups lack lat/lng): fallback to last',
      { id: 10, lat: 51.5, lng: -0.1 },
      [{ groupid: 5 }, { groupid: 6 }],
      (id) => Promise.resolve({ id }),
      6,
      true,
    ],
    [
      'one group without lat/lng is skipped',
      { id: 10, lat: 51.5, lng: -0.1 },
      [{ groupid: 5 }, { groupid: 6 }],
      (id) => Promise.resolve({ 5: { id: 5 }, 6: G(6, 55.9, -3.2) }[id]),
      6,
      true,
    ],
  ])('%s', async (_n, me, groups, fetchImpl, expectedId, expectLookup) => {
    await joinWith({ me, groups, fetchImpl })
    expect(mockAuthStore.joinGroup).toHaveBeenCalledWith(10, expectedId, false)
    expect(mockGroupFetch).toHaveBeenCalledTimes(
      expectLookup ? groups.length : 0
    )
  })

  it('handles a null myGroups (treated as not a member)', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = null
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    await result.submit()
    await flushPromises()
    expect(mockAuthStore.joinGroup).toHaveBeenCalledWith(10, 100, false)
  })
})

// ============================================================
// handleJoinGroup / submit edge cases
// ============================================================

describe('handleJoinGroup guards', () => {
  it('no myid: handleAuthError and callback', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = null
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    const cb = vi.fn()
    await result.submit(cb)
    await flushPromises()
    expect(result.state.value).toBe(ReplyState.AUTHENTICATING)
    expect(mockForceLogin.value).toBe(true)
    expect(cb).toHaveBeenCalledTimes(1)
  })

  it.each([
    ['groups undefined', { id: MSG_ID }],
    ['groups empty', { id: MSG_ID, groups: [] }],
    ['message null', null],
  ])('ERROR when %s', async (_n, msg) => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMessageFetch.mockResolvedValue(msg)
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    const cb = vi.fn()
    await result.submit(cb)
    await flushPromises()
    expect(result.state.value).toBe(ReplyState.ERROR)
    expect(result.error.value).toBe('Message has no groups')
    expect(cb).toHaveBeenCalledTimes(1)
  })
})

describe('submit: email revalidation and late refs', () => {
  function anonymous() {
    const { result } = mountComposable()
    result.startTyping()
    result.replyText.value = 'Hello'
    result.email.value = 'a@example.com'
    return result
  }

  it('awaits the email validator and proceeds when it makes the email valid', async () => {
    const result = anonymous()
    const validator = {
      validate: vi.fn().mockImplementation(async () => {
        result.emailValid.value = true
      }),
      focus: vi.fn(),
    }
    result.setRefs({
      form: makeFormRef(true),
      emailValidator: validator,
      chatButton: makeChatButtonRef(),
    })
    await result.submit()
    await flushPromises()
    expect(validator.validate).toHaveBeenCalled()
    expect(validator.focus).not.toHaveBeenCalled()
    // Reached handleAuthentication (default mock: existing user -> forced login).
    expect(result.state.value).toBe(ReplyState.AUTHENTICATING)
    expect(mockForceLogin.value).toBe(true)
  })

  it('tolerates the email validator throwing, then focuses it and returns to COMPOSING', async () => {
    const result = anonymous()
    const validator = {
      validate: vi.fn().mockRejectedValue(new Error('dns')),
      focus: vi.fn(),
    }
    result.setRefs({ form: makeFormRef(true), emailValidator: validator })
    const cb = vi.fn()
    await result.submit(cb)
    await flushPromises()
    expect(validator.focus).toHaveBeenCalled()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(cb).toHaveBeenCalledTimes(1)
  })

  it('invalid email with no validator ref goes to COMPOSING', async () => {
    const result = anonymous()
    result.setRefs({ form: makeFormRef(true) })
    await result.submit()
    await flushPromises()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it('waits for a late chat button ref instead of failing', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true) })
    result.startTyping()
    result.replyText.value = 'Hello'
    const p = result.submit()
    await flushPromises()
    expect(mockReplyToPostFn).not.toHaveBeenCalled()
    result.setRefs({ chatButton: makeChatButtonRef() })
    await p
    await flushPromises()
    expect(mockReplyToPostFn).toHaveBeenCalled()
    expect(result.state.value).toBe(ReplyState.COMPLETED)
  })
})

// ============================================================
// handleCreateChat outcomes
// ============================================================

describe('handleCreateChat outcomes', () => {
  async function send(replyResult, { newUser = false } = {}) {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockReplyToPostFn.mockResolvedValue(replyResult)
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.setReplySource('browse')
    result.startTyping()
    result.replyText.value = 'Hello'
    if (newUser) result.isNewUser.value = true
    const cb = vi.fn()
    await result.submit(cb)
    await flushPromises()
    return { result, cb }
  }

  it.each([
    ['truthy, existing user', MSG_ID, false, ReplyState.COMPLETED, 'existing'],
    ['truthy, new user', MSG_ID, true, ReplyState.SHOWING_WELCOME, 'new'],
    ['falsy', 0, false, ReplyState.ERROR, null],
    ['null', null, false, ReplyState.ERROR, null],
  ])('%s', async (_n, ret, newUser, expectedState, userType) => {
    const { result, cb } = await send(ret, { newUser })
    expect(result.state.value).toBe(expectedState)
    expect(cb).toHaveBeenCalledTimes(1)
    if (userType) {
      expect(mockAction).toHaveBeenCalledWith('reply_sent', {
        message_id: MSG_ID,
        user_type: userType,
        is_new_user: newUser,
        reply_source: 'browse',
      })
    } else {
      expect(result.error.value).toContain('may be stale')
    }
  })

  it('closeWelcomeModal moves SHOWING_WELCOME to COMPLETED and clears persisted reply', async () => {
    const { result } = await send(MSG_ID, { newUser: true })
    mockClearReply.mockClear()
    result.closeWelcomeModal()
    expect(result.state.value).toBe(ReplyState.COMPLETED)
    expect(result.isComplete.value).toBe(true)
    expect(mockClearReply).toHaveBeenCalled()
  })
})

// ============================================================
// onLoginSuccess
// ============================================================

describe('onLoginSuccess', () => {
  it('falls back to COMPOSING if resuming from AUTHENTICATING throws', async () => {
    mockMyidValue = null // handleJoinGroup -> handleAuthError -> sets forceLogin
    const throwing = customRef(() => ({
      get: () => false,
      set: () => {
        throw new Error('boom')
      },
    }))
    mockAuthStore.forceLogin = throwing
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.startTyping()
    result.replyText.value = 'Hello'
    result.state.value = ReplyState.AUTHENTICATING
    await result.onLoginSuccess()
    await flushPromises()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })
})

// ============================================================
// initialize: remaining branches
// ============================================================

describe('initialize: remaining branches', () => {
  function saved(state) {
    mockReplyMsgId = MSG_ID
    mockReplyMessage = 'Saved text'
    mockReplyingAt = Date.now()
    mockMachineState = state
  }

  it('saved reply with no replyingAt timestamp is stale: discarded and cleared', () => {
    saved(ReplyState.COMPOSING)
    mockReplyingAt = null
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(mockClearReply).toHaveBeenCalled()
  })

  it('saved reply older than 24h is stale', () => {
    saved(ReplyState.COMPOSING)
    mockReplyingAt = Date.now() - 24 * 60 * 60 * 1000 - 1
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(mockClearReply).toHaveBeenCalled()
  })

  it('saved reply just under 24h is resumed', () => {
    saved(ReplyState.COMPOSING)
    mockReplyingAt = Date.now() - 23 * 60 * 60 * 1000
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(result.replyText.value).toBe('Saved text')
  })

  it('splits collection times out of the saved message (and tolerates an empty one)', () => {
    saved(ReplyState.COMPOSING)
    mockReplyMessage = 'Hi\r\n\r\nPossible collection times: '
    const { result } = mountComposable()
    result.initialize()
    expect(result.replyText.value).toBe('Hi')
    expect(result.collectText.value).toBe('')
  })

  it('splits non-empty collection times', () => {
    saved(ReplyState.COMPOSING)
    mockReplyMessage = 'Hi\r\n\r\nPossible collection times: Mon'
    const { result } = mountComposable()
    result.initialize()
    expect(result.collectText.value).toBe('Mon')
  })

  it.each([
    [ReplyState.SHOWING_WELCOME, true, ReplyState.SHOWING_WELCOME],
    [ReplyState.SHOWING_WELCOME, false, ReplyState.SHOWING_WELCOME],
    [ReplyState.COMPLETED, true, ReplyState.IDLE],
    [ReplyState.ERROR, false, ReplyState.COMPOSING],
    [ReplyState.COMPOSING, false, ReplyState.COMPOSING],
    [ReplyState.VALIDATING, true, ReplyState.COMPOSING],
    [ReplyState.AUTHENTICATING, true, ReplyState.COMPOSING],
    [ReplyState.AUTHENTICATING, false, ReplyState.COMPOSING],
    [ReplyState.JOINING_GROUP, true, ReplyState.COMPOSING],
    [ReplyState.JOINING_GROUP, false, ReplyState.COMPOSING],
    [ReplyState.CREATING_CHAT, true, ReplyState.COMPOSING],
    [ReplyState.CREATING_CHAT, false, ReplyState.COMPOSING],
    [ReplyState.SENDING, true, ReplyState.COMPOSING],
    [ReplyState.SENDING, false, ReplyState.COMPOSING],
    ['SOMETHING_ELSE', false, ReplyState.COMPOSING],
    [null, true, ReplyState.COMPOSING],
  ])(
    'saved state %s (logged in: %s) -> %s',
    (savedState, loggedIn, expected) => {
      saved(savedState)
      mockMeValue = loggedIn ? { id: 10 } : null
      const { result } = mountComposable()
      result.initialize()
      expect(result.state.value).toBe(expected)
      expect(result.getDebugInfo().initialized).toBe(true)
      if (savedState === ReplyState.SHOWING_WELCOME) {
        expect(result.isNewUser.value).toBe(true)
      }
    }
  )

  it('restores isNewUser from the store for resumable states', () => {
    saved(ReplyState.COMPOSING)
    mockReplyIsNewUser = true
    const { result } = mountComposable()
    result.initialize()
    expect(result.isNewUser.value).toBe(true)
  })

  it('a saved reply for a different message starts fresh', () => {
    saved(ReplyState.COMPOSING)
    mockReplyMsgId = 999
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(result.replyText.value).toBe('')
    expect(mockClearReply).not.toHaveBeenCalled()
  })

  it('second initialize() is a no-op', () => {
    const { result } = mountComposable()
    result.initialize()
    result.state.value = ReplyState.COMPOSING
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
  })

  it.each([
    ['message only', { m: 'draft', c: null, e: null }],
    ['collect only', { m: null, c: 'Mon', e: null }],
    ['email only', { m: null, c: null, e: 'a@example.com' }],
  ])('resumes a fresh composing draft with %s', (_n, { m, c, e }) => {
    mockDraftMsgId = MSG_ID
    mockDraftMessage = m
    mockDraftCollect = c
    mockDraftEmail = e
    mockDraftAt = Date.now()
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(result.replyText.value).toBe(m || '')
    expect(result.collectText.value).toBe(c || '')
    expect(result.email.value).toBe(e || '')
  })

  it.each([
    ['older than 24h', Date.now() - 25 * 60 * 60 * 1000],
    ['no draftAt', null],
  ])('discards a stale composing draft (%s)', (_n, at) => {
    mockDraftMsgId = MSG_ID
    mockDraftMessage = 'old'
    mockDraftAt = at
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
    expect(mockClearDraft).toHaveBeenCalled()
    expect(result.replyText.value).toBe('')
  })

  it('ignores an empty draft for this message', () => {
    mockDraftMsgId = MSG_ID
    mockDraftAt = Date.now()
    const { result } = mountComposable()
    result.initialize()
    expect(result.state.value).toBe(ReplyState.IDLE)
  })
})

// ============================================================
// Remaining helpers
// ============================================================

describe('helpers and lifecycle', () => {
  it('setReplySource is sent with the submit analytics and saved to the store', async () => {
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(false) })
    result.setReplySource('explore')
    result.startTyping()
    result.replyText.value = 'Hi'
    await result.submit()
    expect(mockAction).toHaveBeenCalledWith(
      'reply_submit',
      expect.objectContaining({ reply_source: 'explore' })
    )
  })

  it('setRefs with only an email validator still initializes once', () => {
    const { result } = mountComposable()
    result.setRefs({ emailValidator: { validate: vi.fn() } })
    expect(result.getDebugInfo().initialized).toBe(true)
    result.setRefs({})
    expect(result.getDebugInfo().initialized).toBe(true)
  })

  it('saveReplyToStore (via submit) appends collection times and source', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    result.setReplySource('browse')
    result.startTyping()
    result.replyText.value = 'Hello'
    result.collectText.value = 'Weekends'
    // Make the send fail so the saved reply is not cleared on COMPLETED.
    mockReplyToPostFn.mockResolvedValue(false)
    await result.submit()
    await flushPromises()
    expect(mockReplyMsgId).toBe(MSG_ID)
    expect(mockReplyMessage).toBe(
      'Hello\r\n\r\nPossible collection times: Weekends'
    )
    expect(mockReplyingAt).toBeTruthy()
  })

  it('submit with a form whose validate() throws falls back to COMPOSING', async () => {
    const { result } = mountComposable()
    result.setRefs({
      form: { validate: vi.fn().mockRejectedValue(new Error('x')) },
    })
    result.startTyping()
    const cb = vi.fn()
    await result.submit(cb)
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(cb).toHaveBeenCalledTimes(1)
  })

  it('submit is blocked while processing and calls the callback', async () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.JOINING_GROUP
    const cb = vi.fn()
    await result.submit(cb)
    expect(cb).toHaveBeenCalledTimes(1)
    expect(mockAction).toHaveBeenCalledWith(
      'reply_submit_blocked',
      expect.objectContaining({ reason: 'canSend_false' })
    )
  })

  it('startTyping only acts from IDLE', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.ERROR
    result.startTyping()
    expect(result.state.value).toBe(ReplyState.ERROR)
  })

  it('closeWelcomeModal does nothing outside SHOWING_WELCOME', () => {
    const { result } = mountComposable()
    result.closeWelcomeModal()
    expect(result.state.value).toBe(ReplyState.IDLE)
  })

  it('reset clears everything and the processing timeout', () => {
    const { result } = mountComposable()
    result.state.value = ReplyState.IDLE
    result.isNewUser.value = true
    result.newUserPassword.value = 'pw'
    result.reset()
    expect(result.isNewUser.value).toBe(false)
    expect(result.newUserPassword.value).toBeNull()
    expect(result.error.value).toBeNull()
    expect(result.previousState.value).toBeNull()
    expect(mockClearReply).toHaveBeenCalled()
  })
})
