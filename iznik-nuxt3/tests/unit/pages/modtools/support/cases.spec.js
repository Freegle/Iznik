import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import CasePage from '~/modtools/pages/support/cases/[[id]].vue'

// The real isomorphic-dompurify. The global alias stubs it as an identity function, so
// it is loaded here by file path, which the alias does not match.
vi.mock('isomorphic-dompurify', async () =>
  vi.importActual(
    '../../../../../node_modules/isomorphic-dompurify/dist/index.js'
  )
)

vi.mock('marked', () => ({
  marked: vi.fn((text) => `<p>${text}</p>`),
}))

const mockFetchCase = vi.fn()
const mockSendMessage = vi.fn()
const mockSetStatus = vi.fn()
const mockFetchRun = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    supportcases: {
      fetchCase: mockFetchCase,
      sendMessage: mockSendMessage,
      setStatus: mockSetStatus,
    },
    supportai: { fetchRun: mockFetchRun },
  }),
}))

const mockChatStore = {
  fetchChat: vi.fn().mockResolvedValue(null),
  byChatId: vi.fn().mockReturnValue(null),
}
vi.mock('~/stores/chat', () => ({ useChatStore: () => mockChatStore }))

const meState = {
  supportOrAdmin: ref(true),
  mod: ref(true),
  myid: ref(1),
}
vi.mock('~/composables/useMe', () => ({ useMe: () => meState }))

vi.mock('~/composables/useTimeFormat', () => ({
  timeago: vi.fn(() => '5 minutes ago'),
}))

const mockRouteParams = ref({ id: '7' })
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({ params: mockRouteParams.value, query: {} }),
  }
})

function messages() {
  return [
    {
      id: 1,
      role: 'Referrer',
      userid: 4,
      displayname: 'Rae Referrer',
      body: 'First question',
      draftused: 'No',
      created: '2026-11-07T10:00:00Z',
    },
  ]
}

function supportCase(extra = {}) {
  return {
    viewer: 'Support',
    id: 7,
    created: '2026-11-07T10:00:00Z',
    updated: '2026-11-07T10:00:00Z',
    status: 'Open',
    source: 'referral',
    chatid: 99,
    reason: 'Please help',
    member: { id: 2, displayname: 'Morgan Member' },
    referrer: { id: 4, displayname: 'Rae Referrer' },
    firstpass_status: 'Done',
    firstpass_error: null,
    firstpass_runid: 55,
    brief: {
      version: 2,
      question: 'Why does the photo upload fail?',
      answer: 'An old browser is blocking uploads.',
      classification: 'Bug',
      confidence: { level: 'High', reason: 'Clear errors' },
      checks: ['Looked at the upload errors', 'Checked the browser version'],
      open_questions: ['Which phone do they use?'],
      related_cases: [3, 5],
      draft: {
        to: 'referrer',
        text: 'Hello Rae, try this.',
        link_warnings: ['https://example.invalid/x is not a Freegle page'],
      },
      pool: { fiveHour: 12, sevenDay: 30 },
      images: { sent: 1, skipped: 0 },
    },
    handledby: null,
    handled_at: null,
    messages: messages(),
    ...extra,
  }
}

function referrerCase(extra = {}) {
  return {
    viewer: 'Referrer',
    id: 7,
    created: '2026-11-07T10:00:00Z',
    status: 'Open',
    chatid: 99,
    reason: 'Please help',
    messages: messages(),
    ...extra,
  }
}

const stubs = {
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
  'nuxt-link': {
    template: '<a class="nl" :href="to"><slot /></a>',
    props: ['to'],
  },
  'b-badge': { template: '<span class="badge"><slot /></span>' },
  'b-spinner': { template: '<span class="spinner" />' },
  'b-button': {
    template:
      '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'disabled'],
    emits: ['click'],
  },
  'b-form-textarea': {
    template:
      '<textarea v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue'],
    emits: ['update:modelValue'],
  },
  ModSupportAIRating: {
    template: '<div class="rating" :data-run="runId" />',
    props: ['runId', 'rating', 'comment'],
  },
  ModSupportCaseList: { template: '<div class="case-list-stub" />' },
  ModChatModal: {
    template: '<div class="chat-modal" :data-pov="pov" />',
    props: ['id', 'pov'],
  },
}

function mountPage() {
  return mount(CasePage, { global: { stubs } })
}

describe('support/cases/[[id]].vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockRouteParams.value = { id: '7' }
    meState.supportOrAdmin.value = true
    meState.mod.value = true
    meState.myid.value = 1
    mockFetchRun.mockResolvedValue({
      id: 55,
      input_tokens: 3000,
      output_tokens: 1000,
      rating: 1,
      rating_comment: 'Good',
    })
    mockSendMessage.mockResolvedValue({ ret: 0, status: 'Success', id: 3 })
    mockSetStatus.mockResolvedValue({ ret: 0 })
  })

  describe('Support view', () => {
    beforeEach(() => {
      mockFetchCase.mockResolvedValue(supportCase())
    })

    it('links back to the Cases tab of Support Tools', async () => {
      const wrapper = mountPage()
      await flushPromises()
      const back = wrapper.find('[data-testid="case-back-to-list"]')
      expect(back.exists()).toBe(true)
      expect(back.attributes('href')).toBe('/support?tab=cases')
      expect(back.text()).toContain('Support cases')
    })

    it('shows the header, the question, the answer and the supporting parts', async () => {
      const wrapper = mountPage()
      await flushPromises()

      expect(mockFetchCase).toHaveBeenCalledWith(7)
      expect(wrapper.find('[data-testid="case-support"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('Case 7')
      expect(wrapper.text()).toContain('Morgan Member')
      expect(wrapper.find('[data-testid="case-classification"]').text()).toBe(
        'Bug'
      )
      expect(wrapper.find('[data-testid="case-question"]').text()).toBe(
        'Why does the photo upload fail?'
      )
      expect(wrapper.find('[data-testid="case-answer"]').html()).toContain(
        'An old browser is blocking uploads.'
      )
      expect(wrapper.find('[data-testid="case-confidence"]').text()).toContain(
        'High'
      )
      expect(
        wrapper.find('[data-testid="case-openquestions"]').text()
      ).toContain('Which phone do they use?')
      const related = wrapper.findAll('[data-testid="case-related"] a')
      expect(related.map((l) => l.attributes('href'))).toEqual([
        '/support/cases/3',
        '/support/cases/5',
      ])
      const checks = wrapper.find('[data-testid="case-checks"]')
      expect(checks.element.tagName).toBe('DETAILS')
      expect(checks.text()).toContain('What was checked')
      expect(checks.text()).toContain('Checked the browser version')
      expect(wrapper.find('[data-testid="case-pool"]').text()).toContain('12%')
      expect(wrapper.find('[data-testid="case-tokens"]').text()).toContain(
        '4.0k tokens'
      )
      expect(wrapper.find('.rating').attributes('data-run')).toBe('55')
      expect(
        wrapper.find('[data-testid="case-linkwarnings"]').text()
      ).toContain('not a Freegle page')
    })

    it('sanitises the answer before rendering it as html', async () => {
      mockFetchCase.mockResolvedValue(
        supportCase({
          brief: {
            ...supportCase().brief,
            answer:
              'Safe <script>alert(1)</script><img src=x onerror=alert(1)>',
          },
        })
      )
      const wrapper = mountPage()
      await flushPromises()
      const html = wrapper.find('[data-testid="case-answer"]').html()
      expect(html).toContain('Safe')
      expect(html).not.toContain('<script')
      expect(html).not.toContain('onerror')
    })

    it('sanitises the answer with the real sanitiser and the draft is plain text', async () => {
      mockFetchCase.mockResolvedValue(
        supportCase({
          brief: {
            ...supportCase().brief,
            answer:
              'Fine <script>alert(1)</script><img src=x onerror=alert(1)>',
            draft: {
              ...supportCase().brief.draft,
              text: 'Hi <script>alert(1)</script> there',
            },
          },
        })
      )
      const wrapper = mountPage()
      await flushPromises()
      const answer = wrapper.find('[data-testid="case-answer"]')
      expect(answer.element.querySelector('script')).toBeNull()
      expect(answer.html()).not.toContain('onerror')
      const draft = wrapper.find('[data-testid="draft-text"]')
      expect(draft.element.querySelector('script')).toBeNull()
      expect(draft.text()).toContain('Hi <script>')
      expect(draft.element.tagName).toBe('DIV')
    })

    it('forbids images and other loaders and makes links safe', async () => {
      mockFetchCase.mockResolvedValue(
        supportCase({
          brief: {
            ...supportCase().brief,
            answer:
              '<img src="https://evil.invalid/?d=secret"><picture><source srcset="https://evil.invalid/a"></picture><video src="https://evil.invalid/v"></video><audio src="https://evil.invalid/a"></audio><iframe src="https://evil.invalid/f"></iframe><style>p{background:url(https://evil.invalid/s)}</style><p style="background:url(https://evil.invalid/b)">Text <a href="https://example.org/x">link</a></p>',
          },
        })
      )
      const wrapper = mountPage()
      await flushPromises()
      const answer = wrapper.find('[data-testid="case-answer"]')
      for (const tag of [
        'img',
        'picture',
        'source',
        'video',
        'audio',
        'iframe',
        'style',
      ]) {
        expect(answer.element.querySelector(tag)).toBeNull()
      }
      expect(answer.html()).not.toContain('evil.invalid')
      expect(answer.html()).not.toContain('style=')
      const a = answer.element.querySelector('a')
      expect(a.getAttribute('href')).toBe('https://example.org/x')
      expect(a.getAttribute('rel')).toBe('noopener noreferrer')
    })

    it('hides View chat when the case has no chat', async () => {
      mockFetchCase.mockResolvedValue(supportCase({ chatid: null }))
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.find('[data-testid="case-view-chat"]').exists()).toBe(
        false
      )
    })

    it('says so when fetching the chat throws', async () => {
      mockChatStore.fetchChat.mockRejectedValueOnce(new Error('500'))
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="case-view-chat"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('.chat-modal').exists()).toBe(false)
      expect(wrapper.text()).toContain('Could not open the chat')
      expect(wrapper.find('[data-testid="case-brief"]').exists()).toBe(true)
    })

    it.each([
      [429, 'sent a lot of replies'],
      [409, 'Another case for this chat is already open'],
      [500, 'Please try again'],
    ])('explains a failed reply with status %s', async (status, text) => {
      mockSendMessage.mockRejectedValue({ response: { status } })
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="case-reply"]').setValue('Hello')
      await wrapper.find('[data-testid="case-send"]').trigger('click')
      await flushPromises()
      expect(wrapper.text()).toContain(text)
      if (status !== 500) {
        expect(wrapper.text()).not.toContain('that reply was not sent')
      }
    })

    it('renders no version 1 sections', async () => {
      const wrapper = mountPage()
      await flushPromises()
      const text = wrapper.text()
      expect(text).not.toContain('Unusual')
      expect(text).not.toContain('Could not find')
      expect(text).not.toContain('Based on')
      expect(text).not.toContain('First pass')
      expect(wrapper.find('[data-testid="case-unusual"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="case-summary"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid^="fact-"]').exists()).toBe(false)
    })

    it('shows the classification Other and omits empty optional parts', async () => {
      mockFetchCase.mockResolvedValue(
        supportCase({
          brief: {
            ...supportCase().brief,
            classification: 'Other',
            open_questions: [],
            related_cases: [],
            checks: [],
          },
        })
      )
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.find('[data-testid="case-classification"]').text()).toBe(
        'Other'
      )
      expect(wrapper.find('[data-testid="case-openquestions"]').exists()).toBe(
        false
      )
      expect(wrapper.find('[data-testid="case-related"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="case-checks"]').exists()).toBe(false)
    })

    it('reads the header as one sentence with single spaces', async () => {
      const wrapper = mountPage()
      await flushPromises()
      const p = wrapper.find('.small.text-muted').text()
      expect(p).toContain(
        'Referred 5 minutes ago by Rae Referrer about Morgan Member (#2), from a referral.'
      )
    })

    it('loads the case when the member logs in after the page opened', async () => {
      meState.supportOrAdmin.value = false
      meState.mod.value = false
      meState.myid.value = undefined
      const wrapper = mountPage()
      await flushPromises()
      expect(mockFetchCase).not.toHaveBeenCalled()
      meState.supportOrAdmin.value = true
      meState.mod.value = true
      meState.myid.value = 3
      await flushPromises()
      expect(mockFetchCase).toHaveBeenCalledWith(7)
      expect(wrapper.find('[data-testid="case-support"]').exists()).toBe(true)
    })

    it('records Unchanged when the draft is sent as it was', async () => {
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="draft-use"]').trigger('click')
      expect(wrapper.find('[data-testid="case-reply"]').element.value).toBe(
        'Hello Rae, try this.'
      )
      await wrapper.find('[data-testid="case-send"]').trigger('click')
      await flushPromises()
      expect(mockSendMessage).toHaveBeenCalledWith(
        7,
        'Hello Rae, try this.',
        'Unchanged'
      )
    })

    it('records Edited when the draft is changed before sending', async () => {
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="draft-use"]').trigger('click')
      await wrapper
        .find('[data-testid="case-reply"]')
        .setValue('Hello Rae, try something else.')
      await wrapper.find('[data-testid="case-send"]').trigger('click')
      await flushPromises()
      expect(mockSendMessage).toHaveBeenCalledWith(
        7,
        'Hello Rae, try something else.',
        'Edited'
      )
    })

    it('records No for a reply typed from scratch, then reloads and clears the box', async () => {
      const wrapper = mountPage()
      await flushPromises()
      await wrapper
        .find('[data-testid="case-reply"]')
        .setValue('  Typed myself  ')
      await wrapper.find('[data-testid="case-send"]').trigger('click')
      await flushPromises()
      expect(mockSendMessage).toHaveBeenCalledWith(7, 'Typed myself', 'No')
      expect(mockFetchCase).toHaveBeenCalledTimes(2)
      expect(wrapper.find('[data-testid="case-reply"]').element.value).toBe('')
    })

    it('does not send an empty reply', async () => {
      const wrapper = mountPage()
      await flushPromises()
      expect(
        wrapper.find('[data-testid="case-send"]').attributes('disabled')
      ).toBeDefined()
      expect(mockSendMessage).not.toHaveBeenCalled()
    })

    it('keeps the reply and says so when sending fails', async () => {
      mockSendMessage.mockRejectedValue(new Error('500'))
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="case-reply"]').setValue('Hello')
      await wrapper.find('[data-testid="case-send"]').trigger('click')
      await flushPromises()
      expect(wrapper.text()).toContain('that reply was not sent')
      expect(wrapper.find('[data-testid="case-reply"]').element.value).toBe(
        'Hello'
      )
    })

    it('marks an open case handled', async () => {
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="case-handle"]').trigger('click')
      await flushPromises()
      expect(mockSetStatus).toHaveBeenCalledWith(7, 'Handled')
    })

    it('offers Reopen on a handled case', async () => {
      mockFetchCase.mockResolvedValue(
        supportCase({
          status: 'Handled',
          handledby: { id: 9, displayname: 'Sam Support' },
          handled_at: '2026-11-07T11:00:00Z',
        })
      )
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.find('[data-testid="case-handle"]').exists()).toBe(false)
      expect(wrapper.text()).toContain('Handled by Sam Support')
      await wrapper.find('[data-testid="case-reopen"]').trigger('click')
      await flushPromises()
      expect(mockSetStatus).toHaveBeenCalledWith(7, 'Open')
    })

    it('says so when the status cannot be changed', async () => {
      mockSetStatus.mockRejectedValue(new Error('500'))
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="case-handle"]').trigger('click')
      await flushPromises()
      expect(wrapper.text()).toContain('Could not change the status')
      expect(wrapper.find('[data-testid="case-action-error"]').exists()).toBe(
        true
      )
      // The case stays on screen.
      expect(wrapper.find('[data-testid="case-brief"]').exists()).toBe(true)
    })

    it('copies the draft to the clipboard', async () => {
      const writeText = vi.fn().mockResolvedValue()
      Object.defineProperty(navigator, 'clipboard', {
        value: { writeText },
        configurable: true,
      })
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="draft-copy"]').trigger('click')
      await flushPromises()
      expect(writeText).toHaveBeenCalledWith('Hello Rae, try this.')
      expect(wrapper.find('[data-testid="draft-copy"]').text()).toBe('Copied')
    })

    it('does not claim to have copied when the clipboard refuses', async () => {
      const writeText = vi.fn().mockRejectedValue(new Error('denied'))
      Object.defineProperty(navigator, 'clipboard', {
        value: { writeText },
        configurable: true,
      })
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="draft-copy"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-testid="draft-copy"]').text()).toBe('Copy')
    })

    it('opens the chat from the first user', async () => {
      mockChatStore.byChatId.mockReturnValue({ id: 99, user1: { id: 2 } })
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="case-view-chat"]').trigger('click')
      await flushPromises()
      expect(mockChatStore.fetchChat).toHaveBeenCalledWith(99)
      expect(wrapper.find('.chat-modal').attributes('data-pov')).toBe('2')
    })

    it('says so when the chat cannot be opened', async () => {
      mockChatStore.byChatId.mockReturnValue(null)
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="case-view-chat"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('.chat-modal').exists()).toBe(false)
      expect(wrapper.text()).toContain('Could not open the chat')
      expect(wrapper.find('[data-testid="case-brief"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="case-handle"]').exists()).toBe(true)
    })

    it.each(['Pending', 'Running'])(
      'shows plainly that the first pass is %s',
      async (st) => {
        mockFetchCase.mockResolvedValue(
          supportCase({
            firstpass_status: st,
            brief: null,
            firstpass_runid: null,
          })
        )
        const wrapper = mountPage()
        await flushPromises()
        expect(
          wrapper.find('[data-testid="case-firstpass-waiting"]').exists()
        ).toBe(true)
        expect(wrapper.find('[data-testid="case-brief"]').exists()).toBe(false)
      }
    )

    it.each(['Failed', 'Skipped'])(
      'gives the reason when the first pass %s',
      async (st) => {
        mockFetchCase.mockResolvedValue(
          supportCase({
            firstpass_status: st,
            firstpass_error: 'The AI helper was busy',
            brief: null,
            firstpass_runid: null,
          })
        )
        const wrapper = mountPage()
        await flushPromises()
        const msg = wrapper.find('[data-testid="case-firstpass-failed"]')
        expect(msg.text()).toContain('The AI helper was busy')
        expect(wrapper.find('[data-testid="case-brief"]').exists()).toBe(false)
        expect(mockFetchRun).not.toHaveBeenCalled()
      }
    )

    it('still shows the case when the run cannot be read', async () => {
      mockFetchRun.mockRejectedValue(new Error('500'))
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.find('[data-testid="case-brief"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="case-tokens"]').exists()).toBe(false)
    })
  })

  describe('Referrer view', () => {
    it('has no link to the Support case list, which the referrer cannot see', async () => {
      meState.supportOrAdmin.value = false
      mockFetchCase.mockResolvedValue(referrerCase())
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.find('[data-testid="case-back-to-list"]').exists()).toBe(
        false
      )
    })

    it('shows status, their reason and the thread, and nothing from the first pass', async () => {
      meState.supportOrAdmin.value = false
      mockFetchCase.mockResolvedValue(
        referrerCase({
          // Not in the contract; the page must not render them even if present.
          brief: supportCase().brief,
          firstpass_error: 'secret reason',
          member: { id: 2, displayname: 'Morgan Member' },
        })
      )
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.find('[data-testid="case-referrer"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="case-status"]').text()).toBe('Open')
      expect(wrapper.find('[data-testid="case-reason"]').text()).toContain(
        'Please help'
      )
      expect(wrapper.findAll('[data-testid="case-message"]')).toHaveLength(1)
      expect(wrapper.find('[data-testid="case-reply"]').exists()).toBe(true)

      expect(wrapper.find('[data-testid="case-brief"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="case-draft"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="case-view-chat"]').exists()).toBe(
        false
      )
      expect(wrapper.find('[data-testid="case-handle"]').exists()).toBe(false)
      expect(wrapper.find('.rating').exists()).toBe(false)
      expect(wrapper.text()).not.toContain(
        'An old browser is blocking uploads.'
      )
      expect(wrapper.text()).not.toContain('secret reason')
      expect(wrapper.text()).not.toContain('Morgan Member')
      expect(mockFetchRun).not.toHaveBeenCalled()
    })

    it('sends a reply without a draft marker', async () => {
      meState.supportOrAdmin.value = false
      mockFetchCase.mockResolvedValue(referrerCase())
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-testid="case-reply"]').setValue('More detail')
      await wrapper.find('[data-testid="case-send"]').trigger('click')
      await flushPromises()
      expect(mockSendMessage).toHaveBeenCalledWith(7, 'More detail', undefined)
    })
  })

  describe('access and errors', () => {
    it('says not found on a 404', async () => {
      mockFetchCase.mockRejectedValue({ response: { status: 404 } })
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.find('[data-testid="case-notfound"]').exists()).toBe(true)
    })

    it('says so on another failure', async () => {
      mockFetchCase.mockRejectedValue(new Error('boom'))
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.text()).toContain('Could not load the case')
    })

    it('refuses someone who is not a moderator', async () => {
      meState.supportOrAdmin.value = false
      meState.mod.value = false
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.text()).toContain("You don't have access")
      expect(mockFetchCase).not.toHaveBeenCalled()
    })

    it('shows the list component to Support when there is no id', async () => {
      mockRouteParams.value = {}
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.find('.case-list-stub').exists()).toBe(true)
      expect(mockFetchCase).not.toHaveBeenCalled()
    })

    it('does not show the list to a referring moderator', async () => {
      mockRouteParams.value = {}
      meState.supportOrAdmin.value = false
      const wrapper = mountPage()
      await flushPromises()
      expect(wrapper.find('.case-list-stub').exists()).toBe(false)
      expect(wrapper.text()).toContain("email that Support's reply sends")
      expect(wrapper.text()).toContain('notice you see right after')
    })
  })
})
