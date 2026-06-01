import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import {
  useReplyStateMachine,
  ReplyState,
  ReplyEvent,
} from '~/composables/useReplyStateMachine'

// ============================================================
// vi.hoisted() — spy functions must be available before vi.mock() factories run
// ============================================================

const {
  mockClearReply,
  mockMessageFetch,
  mockFetchMeFn,
  mockReplyToPostFn,
} = vi.hoisted(() => ({
  mockClearReply: vi.fn(),
  mockMessageFetch: vi.fn(),
  mockFetchMeFn: vi.fn(),
  mockReplyToPostFn: vi.fn(),
}))

// ============================================================
// REPLY STORE MOCK — getters/setters allow per-test mutation
// ============================================================

let mockReplyMsgId = null
let mockReplyMessage = null
let mockReplyingAt = null
let mockMachineState = null
let mockReplyIsNewUser = false

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
    clearReply: mockClearReply,
  }),
}))

// ============================================================
// MESSAGE STORE MOCK
// ============================================================

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: mockMessageFetch,
    byId: vi.fn().mockReturnValue(null),
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

  mount(Wrapper, {
    global: { mocks: { $api: { user: { add: mockUserAdd } } } },
  })

  return { result, mockUserAdd }
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

describe('onLoginSuccess', () => {
  it('from AUTHENTICATING with reply text: resumes to join-group → completed', async () => {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups: [{ groupid: 100 }] })
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
    await result.submit(callback)
    expect(result.state.value).toBe(ReplyState.COMPOSING)
    expect(callback).toHaveBeenCalled()
  })

  it('falls back to COMPOSING when formRef.validate throws', async () => {
    const { result } = mountComposable()
    result.startTyping()
    result.setRefs({
      form: { validate: vi.fn().mockRejectedValue(new Error('Validate error')) },
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
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups: [{ groupid: 100 }] })
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
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups: [{ groupid: 100 }] })
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

    mockFetchMeFn.mockImplementation(async () => {
      mockMeValue = { id: 99 }
      mockMyidValue = 99
    })
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups: [{ groupid: 100 }] })
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
    const mockUserAdd = vi.fn().mockRejectedValue(new Error('Registration failed'))

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
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups: [{ groupid: 200 }] })
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
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups: [{ groupid: 100 }] })
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

  it('crosspost: joins first group when not a member of any (Discourse #9733)', async () => {
    // Bug: the loop set groupToJoin on every iteration, so a non-member of any group
    // ended up being joined to the LAST group, not the first (Southwark/Lewisham mix-up).
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = {} // not a member of any group
    mockMessageFetch.mockResolvedValue({
      id: MSG_ID,
      groups: [{ groupid: 300 }, { groupid: 400 }], // crossposted to two groups
    })
    mockReplyToPostFn.mockResolvedValue(MSG_ID)

    const { result } = mountComposable()
    result.setRefs({ form: makeFormRef(true), chatButton: makeChatButtonRef() })
    await doLoggedInSubmit(result)

    // Must join the FIRST group (300), not the last (400)
    expect(mockAuthStore.joinGroup).toHaveBeenCalledWith(10, 300, false)
    expect(result.state.value).toBe(ReplyState.COMPLETED)
  })
})

describe('handleCreateChat (via submit with logged-in user)', () => {
  async function setupLoggedIn() {
    mockMeValue = { id: 10 }
    mockMyidValue = 10
    mockMyGroupsValue = { 0: { id: 100 } }
    mockMessageFetch.mockResolvedValue({ id: MSG_ID, groups: [{ groupid: 100 }] })
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
