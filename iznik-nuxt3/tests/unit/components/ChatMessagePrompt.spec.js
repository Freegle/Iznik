import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ChatMessagePrompt from '~/components/ChatMessagePrompt.vue'

const { mockData, answerPrompt, routerPush } = vi.hoisted(() => {
  return {
    mockData: {
      chatmessage: {
        userid: 2,
        refmsgid: 999,
        message: 'Could you drop it off, if it worked for you?',
        prompt: {
          kind: 'delivery',
          msgids: [999],
          answered: false,
          expired: false,
          answer: null,
          options: [
            { value: 'maybe', label: 'Maybe, if it works for me' },
            { value: 'no', label: 'Collection only', variant: 'secondary' },
          ],
        },
      },
    },
    answerPrompt: vi.fn().mockResolvedValue({}),
    routerPush: vi.fn(),
  }
})

vi.mock('~/composables/useChat', () => ({
  useChatMessageBase: () => ({
    get chatmessage() {
      return ref(mockData.chatmessage)
    },
    get emessage() {
      return ref(mockData.chatmessage.message)
    },
  }),
}))

vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({
    answerPrompt,
  }),
}))

// The component imports useRouter from '#imports' (the repo convention), not
// from vue-router directly.
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRouter: () => ({ push: routerPush }),
  }
})

describe('ChatMessagePrompt', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockData.chatmessage = {
      userid: 2,
      refmsgid: 999,
      message: 'Could you drop it off, if it worked for you?',
      prompt: {
        kind: 'delivery',
        msgids: [999],
        answered: false,
        expired: false,
        answer: null,
        options: [
          { value: 'maybe', label: 'Maybe, if it works for me' },
          { value: 'no', label: 'Collection only', variant: 'secondary' },
        ],
      },
    }
  })

  function createWrapper(props = {}) {
    return mount(ChatMessagePrompt, {
      props: {
        chatid: 123,
        id: 456,
        ...props,
      },
      global: {
        stubs: {
          'b-row': { template: '<div class="row"><slot /></div>' },
          'b-col': { template: '<div class="col"><slot /></div>' },
          'b-card': { template: '<div class="card"><slot /></div>' },
          'b-card-text': { template: '<div class="card-text"><slot /></div>' },
          'b-form-input': {
            template:
              '<input :type="type" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'type', 'min', 'max'],
          },
          ChatPromptPost: { template: '<div class="chat-prompt-post" />' },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'disabled'],
          },
        },
      },
    })
  }

  it('shows the question and its options', () => {
    const wrapper = createWrapper()

    expect(wrapper.text()).toContain('Could you drop it off')
    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(2)
    expect(buttons[0].text()).toBe('Maybe, if it works for me')
  })

  it('sends the chosen answer', async () => {
    const wrapper = createWrapper()

    await wrapper.findAll('button')[0].trigger('click')
    await flushPromises()

    expect(answerPrompt).toHaveBeenCalledWith(123, 456, 'maybe')
  })

  it('shows what was chosen instead of the buttons once answered', () => {
    mockData.chatmessage.prompt.answered = true
    mockData.chatmessage.prompt.answer = 'maybe'

    const wrapper = createWrapper()

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.text()).toContain('You said: Maybe, if it works for me')
  })

  it('still shows an expired prompt but stops offering the buttons', () => {
    // A conversation with holes in it is more confusing than a stale question,
    // so the text stays even though the answer is no longer wanted.
    mockData.chatmessage.prompt.expired = true

    const wrapper = createWrapper()

    expect(wrapper.text()).toContain('Could you drop it off')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('does not let support or moderators answer on the member behalf', () => {
    const wrapper = createWrapper({ pov: 42 })

    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('takes the member to their post when the option asks it to', async () => {
    mockData.chatmessage.prompt = {
      kind: 'photo',
      msgids: [999],
      answered: false,
      expired: false,
      answer: null,
      options: [
        { value: 'add', label: 'Add a photo', action: 'editmessage' },
        { value: 'none', label: "I haven't got a photo" },
      ],
    }

    const wrapper = createWrapper()
    await wrapper.findAll('button')[0].trigger('click')
    await flushPromises()

    expect(answerPrompt).toHaveBeenCalledWith(123, 456, 'add')
    expect(routerPush).toHaveBeenCalledWith('/mypost/999')
  })

  it('does not navigate for an option with no action', async () => {
    const wrapper = createWrapper()

    await wrapper.findAll('button')[0].trigger('click')
    await flushPromises()

    expect(routerPush).not.toHaveBeenCalled()
  })

  it('renders nothing tappable when the payload has no options', () => {
    mockData.chatmessage.prompt = {
      kind: 'views',
      msgids: [999],
      answered: false,
      expired: false,
      answer: null,
      options: [],
    }
    mockData.chatmessage.message = '7 freeglers have opened your post.'

    const wrapper = createWrapper()

    expect(wrapper.text()).toContain('7 freeglers have opened your post.')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('renders the post itself rather than naming it in the prose', async () => {
    // An item name reads fine as "your dining chairs" and badly as whatever
    // someone actually typed, and nothing can tell the two apart.
    const wrapper = createWrapper()

    expect(wrapper.find('.chat-prompt-post').exists()).toBe(true)
  })

  it('does not repeat an automated-message line on every card', async () => {
    // It is said once in the chat header instead - in this conversation every
    // message is automated, so per-message it is just noise.
    const wrapper = createWrapper()

    expect(wrapper.text()).not.toContain('automated message')
  })

  it('offers a date picker for the deadline rather than fixed timescales', async () => {
    mockData.chatmessage.prompt = {
      kind: 'deadline',
      msgids: [999],
      answered: false,
      expired: false,
      answer: null,
      options: [
        { value: 'date', label: 'Pick a date', input: 'date' },
        { value: 'norush', label: "There's no rush", variant: 'secondary' },
      ],
    }

    const wrapper = createWrapper()
    const date = wrapper.find('input[type="date"]')
    expect(date.exists()).toBe(true)

    await date.setValue('2026-09-01')
    const buttons = wrapper.findAll('button')
    await buttons[0].trigger('click')
    await flushPromises()

    expect(answerPrompt).toHaveBeenCalledWith(123, 456, '2026-09-01')
  })

  it('still lets the poster say there is no rush', async () => {
    mockData.chatmessage.prompt = {
      kind: 'deadline',
      msgids: [999],
      answered: false,
      expired: false,
      answer: null,
      options: [
        { value: 'date', label: 'Pick a date', input: 'date' },
        { value: 'norush', label: "There's no rush", variant: 'secondary' },
      ],
    }

    const wrapper = createWrapper()
    const noRush = wrapper
      .findAll('button')
      .find((b) => b.text().includes('no rush'))
    await noRush.trigger('click')
    await flushPromises()

    expect(answerPrompt).toHaveBeenCalledWith(123, 456, 'norush')
  })

  it('lists every post the question covers, not just one', async () => {
    // Freegle groups a member's outstanding posts, so one question usually
    // covers several and the card has to say which.
    mockData.chatmessage.prompt.msgids = [11, 22]

    const wrapper = createWrapper()

    expect(wrapper.findAll('.chat-prompt-post')).toHaveLength(2)
  })

  it('collapses a long list rather than filling the thread with items', async () => {
    // A house clearance can have a lot outstanding. The rows are compact, so
    // five show before it starts hiding any.
    mockData.chatmessage.prompt.msgids = [1, 2, 3, 4, 5, 6, 7, 8]

    const wrapper = createWrapper()

    expect(wrapper.findAll('.chat-prompt-post')).toHaveLength(5)
    expect(wrapper.text()).toContain('Show 3 more')
  })
})
