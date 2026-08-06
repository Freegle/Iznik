import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import ModSupportAIAssistant from '~/modtools/components/ModSupportAIAssistant.vue'

// Mock the marked library
vi.mock('marked', () => ({
  marked: vi.fn((text) => `<p>${text}</p>`),
}))
// isomorphic-dompurify is aliased to a stub in vitest.config.mts (identity
// sanitize), matching how other heavy external libs are handled.

// Mock the user store
const mockUserStore = {
  list: {},
  clear: vi.fn(),
  fetchMT: vi.fn().mockResolvedValue({}),
  searchUsers: vi.fn().mockResolvedValue([]),
}

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

// Mock the auth store - provides the moderator's JWT forwarded to the helper
const mockAuthStore = {
  auth: { jwt: 'test-jwt-123' },
}

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

// Mock fetch globally
const mockFetch = vi.fn()
global.fetch = mockFetch

// Builds a fetch Response-like object whose SSE body yields the given
// `data: {...}` chunks (as JSON-serialisable objects) one at a time, each
// only released once the previous one has been consumed. This lets tests
// observe transcript state mid-stream, before the final `result` event.
function sseResponse(events) {
  let idx = 0
  return {
    ok: true,
    status: 200,
    body: {
      getReader: () => ({
        read: () => {
          if (idx < events.length) {
            const chunk = `data: ${JSON.stringify(events[idx])}\n\n`
            idx += 1
            return Promise.resolve({
              done: false,
              value: new TextEncoder().encode(chunk),
            })
          }
          return Promise.resolve({ done: true, value: undefined })
        },
      }),
    },
  }
}

describe('ModSupportAIAssistant', () => {
  beforeEach(() => {
    vi.clearAllMocks()

    // Reset mock store state
    mockUserStore.list = {}
    mockUserStore.clear.mockClear()
    mockUserStore.fetchMT.mockClear()
    mockUserStore.searchUsers.mockClear()
    mockUserStore.searchUsers.mockResolvedValue([])
    mockAuthStore.auth.jwt = 'test-jwt-123'

    mockFetch.mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({}),
    })
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  function mountComponent(props = {}) {
    return mount(ModSupportAIAssistant, {
      props: { ...props },
      global: {
        stubs: {
          'b-modal': {
            template: '<div class="modal" v-if="modelValue"><slot /></div>',
            props: ['modelValue', 'title'],
          },
          'b-form': {
            template:
              '<form @submit.prevent="$emit(\'submit\')"><slot /></form>',
            emits: ['submit'],
          },
          'b-form-input': {
            template:
              '<input :value="modelValue" :placeholder="placeholder" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" @keyup.enter="$emit(\'keyup\', $event)" />',
            props: ['modelValue', 'placeholder', 'disabled'],
            emits: ['update:modelValue', 'keyup'],
          },
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'rows', 'placeholder', 'disabled'],
            emits: ['update:modelValue'],
          },
          'b-form-checkbox': {
            template:
              '<label class="checkbox"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
            props: ['modelValue', 'switch', 'size'],
            emits: ['update:modelValue'],
          },
          'b-input-group': {
            template: '<div class="input-group"><slot /></div>',
          },
          'b-form-group': {
            template: '<div class="form-group"><slot /></div>',
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
            props: ['variant', 'size', 'disabled', 'type'],
            emits: ['click'],
          },
          'b-spinner': {
            template: '<span class="spinner" />',
            props: ['small'],
          },
          'b-row': {
            template: '<div class="row"><slot /></div>',
          },
          'b-col': {
            template: '<div class="col"><slot /></div>',
          },
          'v-icon': {
            template: '<span class="icon" />',
            props: ['icon', 'class', 'scale'],
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
            props: ['variant'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders the main container', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.log-analysis-container').exists()).toBe(true)
    })

    it('renders the header with AI Support Helper title', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.log-analysis-header').exists()).toBe(true)
      expect(wrapper.text()).toContain('AI Support Helper')
    })

    it('shows member selection by default', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Select a member to investigate')
    })

    it('shows Debug checkbox', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Debug')
    })
  })

  describe('mandatory member selection', () => {
    it('does not show the chat until a member is selected', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).not.toContain(
        'What would you like to investigate?'
      )
      expect(wrapper.find('.chat-input-area').exists()).toBe(false)
    })

    it('has no Skip button', () => {
      const wrapper = mountComponent()
      const buttons = wrapper.findAll('button')
      const skipButton = buttons.find((b) => b.text().includes('Skip'))
      expect(skipButton).toBeFalsy()
    })

    it('shows the query box once a member is selected', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectUser({
        id: 1,
        email: 'test@example.com',
        displayname: 'Test User',
      })
      await nextTick()

      expect(wrapper.text()).toContain('What would you like to investigate?')
    })

    it('shows the selected member as a header chip with a change affordance', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectUser({
        id: 42,
        email: 'test@example.com',
        displayname: 'Test User',
      })
      await nextTick()

      const chip = wrapper.find('.selected-user-chip')
      expect(chip.exists()).toBe(true)
      expect(chip.text()).toContain('Test User')
      expect(chip.text()).toContain('42')
      expect(chip.text()).toContain('test@example.com')

      const buttons = chip.findAll('button')
      expect(buttons.some((b) => b.text().includes('Change'))).toBe(true)
    })

    it('changeUser resets state so member selection is required again', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'test' }]
      wrapper.vm.query = 'test query'
      wrapper.vm.claudeSessionId = 'sess-1'
      await nextTick()

      wrapper.vm.changeUser()
      await nextTick()

      expect(wrapper.vm.selectedUser).toBeNull()
      expect(wrapper.vm.messages).toEqual([])
      expect(wrapper.vm.query).toBe('')
      expect(wrapper.vm.claudeSessionId).toBeNull()
      expect(wrapper.text()).toContain('Select a member to investigate')
    })
  })

  describe('user search', () => {
    it('has a search input field', () => {
      const wrapper = mountComponent()
      const inputs = wrapper.findAll('input')
      expect(inputs.length).toBeGreaterThan(0)
    })

    it('has a Search button', () => {
      const wrapper = mountComponent()
      const buttons = wrapper.findAll('button')
      const searchButton = buttons.find((b) => b.text().includes('Search'))
      expect(searchButton).toBeTruthy()
    })

    it('calls searchUsers when search button is clicked', async () => {
      const wrapper = mountComponent()

      wrapper.vm.userSearch = 'test@example.com'
      await nextTick()

      mockUserStore.searchUsers.mockImplementation(() => {
        mockUserStore.list = {
          1: { id: 1, email: 'test@example.com', displayname: 'Test User' },
        }
        return [{ id: 1, email: 'test@example.com', displayname: 'Test User' }]
      })

      const buttons = wrapper.findAll('button')
      const searchButton = buttons.find((b) => b.text().includes('Search'))
      await searchButton.trigger('click')
      await flushPromises()

      expect(mockUserStore.clear).toHaveBeenCalled()
      expect(mockUserStore.searchUsers).toHaveBeenCalledWith('test@example.com')
    })

    it('does not search when search term is empty', async () => {
      const wrapper = mountComponent()

      wrapper.vm.userSearch = ''
      await wrapper.vm.searchUsers()

      expect(mockUserStore.searchUsers).not.toHaveBeenCalled()
    })

    it('displays search results', async () => {
      const wrapper = mountComponent()

      wrapper.vm.searchResults = [
        {
          id: 1,
          email: 'user1@example.com',
          displayname: 'User One',
          lastaccess: '2024-01-01',
        },
        {
          id: 2,
          email: 'user2@example.com',
          displayname: 'User Two',
          lastaccess: '2024-01-02',
        },
      ]
      await nextTick()

      expect(wrapper.text()).toContain('User One')
      expect(wrapper.text()).toContain('user1@example.com')
    })

    it('shows no results message when no users found', async () => {
      const wrapper = mountComponent()

      wrapper.vm.noResults = true
      wrapper.vm.userSearch = 'nonexistent'
      await nextTick()

      expect(wrapper.text()).toContain('No users found matching')
    })

    it('auto-selects user when only one result', async () => {
      const wrapper = mountComponent()

      wrapper.vm.userSearch = 'test'
      mockUserStore.searchUsers.mockImplementation(() => {
        mockUserStore.list = {
          1: { id: 1, email: 'test@example.com', displayname: 'Test User' },
        }
        return [{ id: 1, email: 'test@example.com', displayname: 'Test User' }]
      })

      await wrapper.vm.searchUsers()
      await flushPromises()

      expect(wrapper.vm.selectedUser).toEqual(
        expect.objectContaining({ id: 1 })
      )
    })
  })

  describe('user selection', () => {
    it('selects a user when clicked', async () => {
      const wrapper = mountComponent()

      const testUser = {
        id: 1,
        email: 'test@example.com',
        displayname: 'Test User',
      }
      wrapper.vm.selectUser(testUser)
      await nextTick()

      expect(wrapper.vm.selectedUser).toEqual(testUser)
    })

    it('clears search results after selecting user', async () => {
      const wrapper = mountComponent()

      wrapper.vm.searchResults = [{ id: 1 }]
      wrapper.vm.userSearch = 'test'

      wrapper.vm.selectUser({ id: 1 })
      await nextTick()

      expect(wrapper.vm.searchResults).toEqual([])
      expect(wrapper.vm.userSearch).toBe('')
    })
  })

  describe('query input', () => {
    it('shows query textarea after user is selected', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1, email: 'test@example.com' }
      await nextTick()

      expect(wrapper.text()).toContain('What would you like to investigate?')
    })

    it('disables Analyze button when query is empty', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.query = ''
      await nextTick()

      const buttons = wrapper.findAll('button')
      const analyzeButton = buttons.find((b) => b.text().includes('Analyze'))
      expect(analyzeButton.attributes('disabled')).toBeDefined()
    })

    it('enables Analyze button when query has content', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.query = 'Why is this user having problems?'
      wrapper.vm.isProcessing = false
      await nextTick()

      const buttons = wrapper.findAll('button')
      const analyzeButton = buttons.find((b) => b.text().includes('Analyze'))
      expect(analyzeButton.attributes('disabled')).toBeUndefined()
    })
  })

  describe('messages display', () => {
    it('shows chat interface when messages exist', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [
        { role: 'user', content: 'Hello' },
        { role: 'assistant', content: 'Hi there' },
      ]
      await nextTick()

      expect(wrapper.find('.log-analysis-messages').exists()).toBe(true)
    })

    it('displays user messages with correct styling', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'Hello' }]
      await nextTick()

      expect(wrapper.find('.message-user').exists()).toBe(true)
    })

    it('displays assistant messages with correct styling', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'assistant', content: 'Hi there' }]
      await nextTick()

      expect(wrapper.find('.message-assistant').exists()).toBe(true)
    })

    it('shows a spinner when processing', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'test' }]
      wrapper.vm.isProcessing = true
      await nextTick()

      expect(wrapper.find('.spinner').exists()).toBe(true)
    })

    it('shows a default placeholder while waiting for the first transcript step', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'test' }]
      wrapper.vm.isProcessing = true
      wrapper.vm.transcript = []
      await nextTick()

      expect(wrapper.text()).toContain('Starting investigation')
    })

    it('renders each transcript event as an ordered step, styling tool steps differently', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'test' }]
      wrapper.vm.isProcessing = true
      wrapper.vm.transcript = [
        { type: 'status', text: 'Investigating (driver=api)…' },
        { type: 'thinking', text: 'Looking at recent messages' },
        { type: 'tool', text: 'db_query {"sql":"select ..."}' },
      ]
      await nextTick()

      const steps = wrapper.findAll('.transcript-step')
      expect(steps.length).toBe(3)
      expect(steps[0].classes()).toContain('transcript-status')
      expect(steps[1].classes()).toContain('transcript-thinking')
      expect(steps[2].classes()).toContain('transcript-tool')
      expect(steps[2].text()).toContain('db_query')
    })

    it('shows cancel button when processing', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'test' }]
      wrapper.vm.isProcessing = true
      await nextTick()

      const buttons = wrapper.findAll('button')
      const cancelButton = buttons.find((b) => b.text().includes('Cancel'))
      expect(cancelButton).toBeTruthy()
    })

    it('shows message cost when available', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [
        {
          role: 'assistant',
          content: 'Response',
          costUsd: 0.0025,
        },
      ]
      await nextTick()

      expect(wrapper.text()).toContain('Cost: $0.0025')
    })

    it('shows token counts from usage when available', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [
        {
          role: 'assistant',
          content: 'Response',
          costUsd: 0.0025,
          usage: { inputTokens: 1234, outputTokens: 567 },
        },
      ]
      await nextTick()

      expect(wrapper.text()).toContain('1,234 in / 567 out tokens')
    })
  })

  describe('computed properties', () => {
    it('calculates totalCost correctly', async () => {
      const wrapper = mountComponent()

      wrapper.vm.messages = [
        { role: 'assistant', content: 'test1', costUsd: 0.001 },
        { role: 'user', content: 'test2' },
        { role: 'assistant', content: 'test3', costUsd: 0.002 },
      ]
      await nextTick()

      expect(wrapper.vm.totalCost).toBeCloseTo(0.003, 4)
    })

    it('returns 0 for totalCost when no messages with cost', async () => {
      const wrapper = mountComponent()

      wrapper.vm.messages = [
        { role: 'user', content: 'test' },
        { role: 'assistant', content: 'reply' },
      ]
      await nextTick()

      expect(wrapper.vm.totalCost).toBe(0)
    })

    it('calculates totalTokens across assistant messages', async () => {
      const wrapper = mountComponent()

      wrapper.vm.messages = [
        {
          role: 'assistant',
          content: 'test1',
          usage: { inputTokens: 100, outputTokens: 50 },
        },
        { role: 'user', content: 'test2' },
        {
          role: 'assistant',
          content: 'test3',
          usage: { inputTokens: 200, outputTokens: 20 },
        },
      ]
      await nextTick()

      expect(wrapper.vm.totalTokens).toEqual({
        inputTokens: 300,
        outputTokens: 70,
      })
    })
  })

  describe('utility methods', () => {
    it('formatDate formats date correctly', () => {
      const wrapper = mountComponent()
      const result = wrapper.vm.formatDate('2024-01-15T10:30:00Z')
      expect(result).toContain('2024')
    })

    it('formatDate returns empty string for falsy input', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.formatDate(null)).toBe('')
      expect(wrapper.vm.formatDate('')).toBe('')
    })

    it('formatTokenCounts formats input/output tokens', () => {
      const wrapper = mountComponent()
      expect(
        wrapper.vm.formatTokenCounts({ inputTokens: 1234, outputTokens: 567 })
      ).toBe('1,234 in / 567 out tokens')
    })

    it('formatTokenCounts returns empty string when no usage', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.formatTokenCounts(null)).toBe('')
      expect(
        wrapper.vm.formatTokenCounts({ inputTokens: 0, outputTokens: 0 })
      ).toBe('')
    })

    it('cancelQuery stops processing and clears the transcript', async () => {
      const wrapper = mountComponent()
      wrapper.vm.isProcessing = true
      wrapper.vm.transcript = [{ type: 'status', text: 'Investigating...' }]

      wrapper.vm.cancelQuery()
      await nextTick()

      expect(wrapper.vm.isProcessing).toBe(false)
      expect(wrapper.vm.transcript).toEqual([])
    })
  })

  describe('debug mode', () => {
    it('shows debug panel when debugMode is true and has entries', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'test' }]
      wrapper.vm.debugMode = true
      wrapper.vm.debugLog = [
        { type: 'request', label: 'Test', data: {}, timestamp: '10:00:00' },
      ]
      await nextTick()

      expect(wrapper.find('.debug-panel').exists()).toBe(true)
      expect(wrapper.text()).toContain('Debug: Data Flow')
    })

    it('hides debug panel when debugMode is false', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'test' }]
      wrapper.vm.debugMode = false
      wrapper.vm.debugLog = [{ type: 'request', label: 'Test', data: {} }]
      await nextTick()

      expect(wrapper.find('.debug-panel').exists()).toBe(false)
    })

    it('addDebugEntry adds entry when debug mode is on', () => {
      const wrapper = mountComponent()

      wrapper.vm.debugMode = true
      wrapper.vm.addDebugEntry('request', 'Test Label', { foo: 'bar' })

      expect(wrapper.vm.debugLog.length).toBe(1)
      expect(wrapper.vm.debugLog[0].label).toBe('Test Label')
      expect(wrapper.vm.debugLog[0].type).toBe('request')
    })

    it('addDebugEntry does not add when debug mode is off', () => {
      const wrapper = mountComponent()

      wrapper.vm.debugMode = false
      wrapper.vm.addDebugEntry('request', 'Test Label', { foo: 'bar' })

      expect(wrapper.vm.debugLog.length).toBe(0)
    })
  })

  describe('scrollToBottom', () => {
    it('scrolls messages container to bottom', async () => {
      const wrapper = mountComponent()

      const mockContainer = {
        scrollTop: 0,
        scrollHeight: 500,
      }
      wrapper.vm.messagesContainer = mockContainer

      wrapper.vm.scrollToBottom()
      await nextTick()

      expect(mockContainer.scrollTop).toBe(500)
    })
  })

  describe('session cost display', () => {
    it('shows session cost when totalCost > 0', async () => {
      const wrapper = mountComponent()

      wrapper.vm.messages = [
        { role: 'assistant', content: 'test', costUsd: 0.005 },
      ]
      await nextTick()

      expect(wrapper.text()).toContain('Session: $')
    })

    it('shows session token totals alongside the cost', async () => {
      const wrapper = mountComponent()

      wrapper.vm.messages = [
        {
          role: 'assistant',
          content: 'test',
          costUsd: 0.005,
          usage: { inputTokens: 100, outputTokens: 50 },
        },
      ]
      await nextTick()

      expect(wrapper.text()).toContain('100 in / 50 out tokens')
    })

    // On a Claude subscription nothing is billed per query, so the server sends
    // a cost of 0. Showing "$0.0000" - or a dollar figure at all - is meaningless
    // and alarming, but the token counts are still useful.
    it('shows no money at all when the cost is zero', async () => {
      const wrapper = mountComponent()

      wrapper.vm.messages = [
        {
          role: 'assistant',
          content: 'test',
          costUsd: 0,
          usage: { inputTokens: 100, outputTokens: 50 },
        },
      ]
      await nextTick()

      expect(wrapper.text()).not.toContain('Session: $')
      expect(wrapper.text()).not.toContain('$0.0000')
      expect(wrapper.text()).toContain('100 in / 50 out tokens')
    })

    it('still shows token totals when no cost is reported at all', async () => {
      const wrapper = mountComponent()

      wrapper.vm.messages = [
        {
          role: 'assistant',
          content: 'test',
          usage: { inputTokens: 20, outputTokens: 7 },
        },
      ]
      await nextTick()

      expect(wrapper.text()).not.toContain('Session: $')
      expect(wrapper.text()).toContain('20 in / 7 out tokens')
    })
  })

  describe('follow-up input', () => {
    it('shows follow-up input area when in chat mode', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [
        { role: 'user', content: 'test' },
        { role: 'assistant', content: 'response' },
      ]
      await nextTick()

      expect(wrapper.find('.chat-input-area').exists()).toBe(true)
    })

    it('has placeholder for follow-up question', async () => {
      const wrapper = mountComponent()

      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.messages = [{ role: 'user', content: 'test' }]
      await nextTick()

      const input = wrapper.find('.chat-input-area input')
      expect(input.exists()).toBe(true)
      expect(input.attributes('placeholder')).toContain('follow-up')
    })
  })

  describe('moderator authentication', () => {
    it('forwards the moderator JWT as a Bearer token to the log-analysis endpoint', async () => {
      const wrapper = mountComponent()
      mockFetch.mockReset()
      mockFetch.mockResolvedValue(
        sseResponse([{ type: 'result', analysis: 'done', costUsd: 0 }])
      )

      await wrapper.vm.queryLogsForUser('why is this user blocked?')

      const call = mockFetch.mock.calls.find(([url]) =>
        String(url).includes('/api/log-analysis')
      )
      expect(call).toBeTruthy()
      expect(call[1].headers.Authorization).toBe('Bearer test-jwt-123')
    })

    it('omits the Authorization header when there is no JWT', async () => {
      mockAuthStore.auth.jwt = null
      const wrapper = mountComponent()
      mockFetch.mockReset()
      mockFetch.mockResolvedValue(
        sseResponse([{ type: 'result', analysis: 'done', costUsd: 0 }])
      )

      await wrapper.vm.queryLogsForUser('test')

      const call = mockFetch.mock.calls.find(([url]) =>
        String(url).includes('/api/log-analysis')
      )
      expect(call).toBeTruthy()
      expect(call[1].headers.Authorization).toBeUndefined()
    })
  })

  describe('SSE transcript accumulation', () => {
    it('appends a transcript step for each thinking/tool/status event, then clears it when result arrives', async () => {
      const wrapper = mountComponent()
      wrapper.vm.selectedUser = {
        id: 1,
        email: 'a@example.com',
        displayname: 'A',
      }
      await nextTick()

      let releaseSecondChunk
      const secondChunk = new Promise((resolve) => {
        releaseSecondChunk = resolve
      })

      let callCount = 0
      mockFetch.mockReset()
      mockFetch.mockResolvedValue({
        ok: true,
        status: 200,
        body: {
          getReader: () => ({
            read: () => {
              callCount += 1
              if (callCount === 1) {
                return Promise.resolve({
                  done: false,
                  value: new TextEncoder().encode(
                    'data: {"type":"tool","message":"db_query select..."}\n\n'
                  ),
                })
              }
              if (callCount === 2) {
                return secondChunk
              }
              return Promise.resolve({ done: true, value: undefined })
            },
          }),
        },
      })

      wrapper.vm.query = 'Why blocked?'
      const submitPromise = wrapper.vm.submitQuery()

      // Let the first SSE chunk (a tool step) be processed while the
      // request is still in flight.
      await flushPromises()

      expect(wrapper.vm.transcript).toEqual([
        { type: 'tool', text: 'db_query select...' },
      ])

      // Release the result event, completing the stream.
      releaseSecondChunk({
        done: false,
        value: new TextEncoder().encode(
          'data: {"type":"result","analysis":"All good","costUsd":0.01,"usage":{"inputTokens":10,"outputTokens":20}}\n\n'
        ),
      })

      await submitPromise
      await flushPromises()

      expect(wrapper.vm.transcript).toEqual([])
      expect(wrapper.vm.isProcessing).toBe(false)
    })
  })

  describe('SSE result handling', () => {
    it('renders the final answer, cost and token usage when a result event arrives', async () => {
      const wrapper = mountComponent()
      wrapper.vm.selectedUser = {
        id: 7,
        email: 'user@example.com',
        displayname: 'User Seven',
      }
      await nextTick()

      mockFetch.mockReset()
      mockFetch.mockResolvedValue(
        sseResponse([
          {
            type: 'result',
            analysis: 'They are blocked because of X.',
            costUsd: 0.012,
            usage: { inputTokens: 100, outputTokens: 40 },
            claudeSessionId: 'sess-1',
          },
        ])
      )

      wrapper.vm.query = 'Why blocked?'
      await wrapper.vm.submitQuery()
      await flushPromises()

      const last = wrapper.vm.messages[wrapper.vm.messages.length - 1]
      expect(last.role).toBe('assistant')
      expect(last.content).toBe('They are blocked because of X.')
      expect(last.costUsd).toBe(0.012)
      expect(wrapper.vm.claudeSessionId).toBe('sess-1')

      expect(wrapper.text()).toContain('Cost: $0.0120')
      expect(wrapper.text()).toContain('100 in / 40 out tokens')
    })

    it('does not submit when no member is selected', async () => {
      const wrapper = mountComponent()
      mockFetch.mockReset()

      wrapper.vm.query = 'General question'
      await wrapper.vm.submitQuery()
      await flushPromises()

      expect(mockFetch).not.toHaveBeenCalled()
      expect(wrapper.vm.messages).toEqual([])
    })
  })
  describe('refer to geeks', () => {
    // Gets the component into the state a volunteer is actually in when they
    // give up: a member selected and a conversation on screen.
    async function withConversation() {
      const wrapper = mountComponent()
      wrapper.vm.selectedUser = {
        id: 35909200,
        displayname: 'Edward Hibbert',
        email: 'edward@ehibbert.org.uk',
      }
      wrapper.vm.deviceSummary = {
        devices: [{ isApp: true, appVersion: '3.2.28' }],
      }
      wrapper.vm.messages = [
        { role: 'user', content: 'Why is his reply stuck?' },
        {
          role: 'assistant',
          content: 'It is held by rippling.',
          costUsd: 0.18,
          usage: { inputTokens: 100, outputTokens: 40 },
        },
      ]
      await nextTick()
      return wrapper
    }

    it('offers the referral only once there is something to refer', async () => {
      const wrapper = mountComponent()
      wrapper.vm.selectedUser = { id: 1 }
      await nextTick()
      expect(wrapper.find('.referral-bar').exists()).toBe(false)

      const withChat = await withConversation()
      expect(withChat.find('.referral-bar').exists()).toBe(true)
      expect(withChat.text()).toContain('Refer to geeks')
    })

    it('shows a live progress bar while a snapshot is building', async () => {
      const wrapper = mountComponent()
      wrapper.vm.selectedUser = { id: 1 }
      wrapper.vm.isProcessing = true
      wrapper.vm.toolProgress = {
        tool: 'get_user_dump',
        percent: 42,
        section: 'loki_logs',
        etaMs: 30000,
      }
      await nextTick()

      const bar = wrapper.find('[data-testid="dump-progress"]')
      expect(bar.exists()).toBe(true)
      expect(bar.text()).toContain('loki_logs')
      expect(bar.text()).toContain('42%')
      expect(bar.text()).toContain('30s left')

      wrapper.vm.toolProgress = null
      await nextTick()
      expect(wrapper.find('[data-testid="dump-progress"]').exists()).toBe(false)
    })

    it('hides the referral while the assistant is still working', async () => {
      const wrapper = await withConversation()
      expect(wrapper.find('.referral-bar').exists()).toBe(true)

      // A past referral's "Sent to the geeks" notice must hide too - under a
      // running investigation it reads as if the new question was referred.
      wrapper.vm.referralResult = { ok: true, ref: 'SR-4F2QW' }
      await nextTick()
      expect(wrapper.text()).toContain('Sent to the geeks')

      // "Not solved, or found a bug?" is premature mid-investigation - the
      // bar only belongs on screen when the assistant has finished.
      wrapper.vm.isProcessing = true
      await nextTick()
      expect(wrapper.find('.referral-bar').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Sent to the geeks')

      wrapper.vm.isProcessing = false
      await nextTick()
      expect(wrapper.find('.referral-bar').exists()).toBe(true)
      expect(wrapper.text()).toContain('Sent to the geeks')
    })

    it('sends the whole investigation, the note and the member', async () => {
      const wrapper = await withConversation()
      mockFetch.mockReset()
      mockFetch.mockResolvedValue({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ ok: true, ref: 'SR-4F2QW' }),
      })

      wrapper.vm.referralNote = 'Badge keeps generating support mail.'
      await wrapper.vm.sendReferral()
      await flushPromises()

      expect(mockFetch).toHaveBeenCalledTimes(1)
      const [url, opts] = mockFetch.mock.calls[0]
      expect(url).toContain('/api/refer-to-geeks')
      expect(opts.method).toBe('POST')
      expect(opts.headers.Authorization).toBe('Bearer test-jwt-123')

      const body = JSON.parse(opts.body)
      expect(body.note).toBe('Badge keeps generating support mail.')
      expect(body.member.id).toBe(35909200)
      expect(body.member.email).toBe('edward@ehibbert.org.uk')
      expect(body.deviceSummary.devices).toHaveLength(1)
      expect(body.messages).toHaveLength(2)
      expect(body.messages[0].role).toBe('user')
      // The email must show what was on screen, so the rendered HTML goes too.
      expect(body.messages[1].html).toContain('It is held by rippling.')
      expect(body.totals.inputTokens).toBe(100)
      // No money: the helper runs on a subscription, so a dollar figure would
      // be a notional price nobody is charged.
      expect(body.totals.costUsd).toBeUndefined()
      expect(body.messages[1].costUsd).toBeUndefined()
    })

    it('shows the tracking reference once it has gone', async () => {
      const wrapper = await withConversation()
      mockFetch.mockReset()
      mockFetch.mockResolvedValue({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ ok: true, ref: 'SR-4F2QW' }),
      })

      wrapper.vm.referralNote = 'Please look.'
      await wrapper.vm.sendReferral()
      await flushPromises()
      await nextTick()

      expect(wrapper.vm.referralResult).toEqual({
        ok: true,
        ref: 'SR-4F2QW',
      })
      expect(wrapper.text()).toContain('SR-4F2QW')
      expect(wrapper.vm.showReferralModal).toBe(false)
      expect(wrapper.vm.referralNote).toBe('')
    })

    it('will not send without the volunteer saying why', async () => {
      const wrapper = await withConversation()
      mockFetch.mockReset()

      wrapper.vm.referralNote = '   '
      await wrapper.vm.sendReferral()
      await flushPromises()

      expect(mockFetch).not.toHaveBeenCalled()
      expect(wrapper.vm.referralResult).toBeNull()
    })

    it('reports a failure instead of claiming it was sent', async () => {
      const wrapper = await withConversation()
      mockFetch.mockReset()
      mockFetch.mockResolvedValue({
        ok: false,
        status: 502,
        json: () =>
          Promise.resolve({ message: 'Could not send: relay refused' }),
      })

      wrapper.vm.openReferral()
      wrapper.vm.referralNote = 'Please look.'
      await wrapper.vm.sendReferral()
      await flushPromises()

      expect(wrapper.vm.referralError).toBe('Could not send: relay refused')
      expect(wrapper.vm.referralResult).toBeNull()
      // The modal stays open so they can see what went wrong and retry.
      expect(wrapper.vm.showReferralModal).toBe(true)
      // The note stays put so they do not have to retype it.
      expect(wrapper.vm.referralNote).toBe('Please look.')
    })

    it('reports a network failure too', async () => {
      const wrapper = await withConversation()
      mockFetch.mockReset()
      mockFetch.mockRejectedValue(new Error('boom'))

      wrapper.vm.referralNote = 'Please look.'
      await wrapper.vm.sendReferral()
      await flushPromises()

      expect(wrapper.vm.referralError).toContain('boom')
      expect(wrapper.vm.referralResult).toBeNull()
      expect(wrapper.vm.referring).toBe(false)
    })

    it('clears referral state when the member is changed', async () => {
      const wrapper = await withConversation()
      wrapper.vm.referralNote = 'something'
      wrapper.vm.referralResult = { ok: true, ref: 'SR-4F2QW' }

      wrapper.vm.changeUser()
      await nextTick()

      expect(wrapper.vm.referralNote).toBe('')
      expect(wrapper.vm.referralResult).toBeNull()
    })
  })
})
