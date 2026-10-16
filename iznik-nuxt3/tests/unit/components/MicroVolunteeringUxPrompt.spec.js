import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { mount, flushPromises } from '@vue/test-utils'
import MicroVolunteeringUxPrompt from '~/components/MicroVolunteeringUxPrompt.vue'

const store = {
  respond: vi.fn().mockResolvedValue(undefined),
}

vi.mock('~/stores/microvolunteering', () => ({
  useMicroVolunteeringStore: () => store,
}))

// The card's own source, for the few things a unit test cannot see: its style rules and its imports.
const source = readFileSync(
  join(process.cwd(), 'components/MicroVolunteeringUxPrompt.vue'),
  'utf8'
)

const YES_NO = [
  { key: 'yes', label: 'Yes' },
  { key: 'no', label: 'No' },
  { key: 'not_sure', label: 'Not sure' },
]

// The area survey: seven areas, four shown, and a second question.
const areaPrompt = {
  id: 9,
  kind: 'survey',
  question: 'Which part of Freegle works best for you?',
  body: 'Think about the last few times you used it.',
  options: [
    { key: 'give', label: 'Offering something' },
    { key: 'reply', label: 'Replying to a post' },
    { key: 'login', label: 'Signing in or joining' },
    { key: 'search', label: 'Asking for something' },
    { key: 'chat', label: 'Arranging a collection in chat' },
  ],
  display: ['reply', 'login', 'chat', 'give'],
  images: [],
  settings: {
    worst_label: 'Which other part most needs improving? (optional)',
  },
}

// The follow-up: three problems, one pick, no second question.
const followUpPrompt = {
  id: 15,
  kind: 'survey',
  question:
    'Which of these has been your biggest problem in the last 3 months?',
  options: [
    { key: 'problem:1', label: 'Photos took too long to upload' },
    { key: 'problem:2', label: 'Nobody replied to my post' },
    { key: 'problem:3', label: 'I could not sign in on my phone' },
  ],
  display: ['problem:2', 'problem:1', 'problem:3'],
  images: [],
  settings: {},
}

const awareness = {
  id: 21,
  kind: 'awareness',
  question: 'Do you know how to promise an item in a chat?',
  options: YES_NO,
  images: [],
  settings: {
    reveal: 'Yes. Tap the three dots in the chat and choose Promise.',
    link: '/chats',
    decoy: false,
  },
}

// An awareness question with nothing to reveal: the answer moves straight on.
const plainAwareness = { ...awareness, id: 22, settings: { decoy: false } }

const openPrompt = {
  id: 12,
  kind: 'open',
  question: 'Did you have a problem the last time you used Freegle?',
  options: [
    { key: 'nothing', label: 'I had no problems' },
    { key: 'dont_understand', label: "I don't understand" },
  ],
  images: [],
  settings: { preset: 'problems', placeholder: 'If yes, what happened?' },
}

// A kind this card does not know: it is drawn as a plain list of choices.
const unknownKind = {
  id: 7,
  kind: 'experiment',
  question: 'Would this change be better or worse for you?',
  body: 'A line of explanation that this card does not draw.',
  options: [
    { key: 'better', label: 'Better' },
    { key: 'worse', label: 'Worse' },
    { key: 'dont_care', label: 'Makes no difference to me' },
    { key: 'dont_understand', label: "I don't understand" },
  ],
  images: [{ url: 'https://img/now.png', caption: 'How it is now' }],
  settings: {},
}

function mountWith(prompt, options = {}) {
  return mount(MicroVolunteeringUxPrompt, {
    props: { prompt },
    ...options,
    global: {
      stubs: {
        SpinButton: {
          template:
            '<button class="spin-button" :disabled="disabled" @click="$emit(\'handle\', () => {})">{{ label }}</button>',
          props: ['iconName', 'variant', 'label', 'disabled'],
          emits: ['handle'],
        },
        'b-button': {
          template:
            '<button class="b-button" :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'disabled', 'size'],
          emits: ['click'],
        },
        'nuxt-link': {
          template: '<a :href="to"><slot /></a>',
          props: ['to'],
        },
        'b-form-textarea': {
          template:
            '<textarea :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'rows', 'maxlength', 'placeholder'],
          inheritAttrs: true,
          emits: ['update:modelValue'],
        },
      },
    },
  })
}

// The keys of the buttons that start with a prefix, in screen order.
const keys = (w, prefix) =>
  w
    .findAll(`[data-testid^="${prefix}-"]`)
    .map((b) => b.attributes('data-testid').replace(`${prefix}-`, ''))

const find = (w, testid) => w.find(`[data-testid="${testid}"]`)
const click = (w, testid) => find(w, testid).trigger('click')

async function send(w) {
  await click(w, 'submit-prompt')
  await flushPromises()
}

describe('MicroVolunteeringUxPrompt', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('any question', () => {
    it('cannot send until a choice is made', async () => {
      const w = mountWith(plainAwareness)
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeDefined()
      await click(w, 'choice-no')
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeUndefined()
    })

    it('sends the choice and the trimmed comment, then moves on', async () => {
      const w = mountWith(plainAwareness)
      await click(w, 'choice-no')
      await click(w, 'comment-toggle')
      expect(find(w, 'comment-text').attributes('placeholder')).toBe(
        'One sentence is enough.'
      )
      await find(w, 'comment-text').setValue('  What is a chat?  ')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 22,
        ux_answer: { choice: 'no', shown: ['yes', 'no', 'not_sure'] },
        comments: 'What is a chat?',
      })
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('leaves the comment out when empty', async () => {
      const w = mountWith(plainAwareness)
      await click(w, 'choice-yes')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 22,
        ux_answer: { choice: 'yes', shown: ['yes', 'no', 'not_sure'] },
        comments: undefined,
      })
    })

    it('records a skip before moving on, so the same question is not offered back', async () => {
      const w = mountWith(plainAwareness)
      await click(w, 'skip-prompt')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledTimes(1)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 22,
        ux_answer: { skipped: true },
      })
      expect(w.emitted('skipped')).toHaveLength(1)
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('moves on from a skip even if recording it fails, and only once', async () => {
      store.respond.mockRejectedValueOnce(new Error('down'))
      const w = mountWith(plainAwareness)
      await click(w, 'skip-prompt')
      await click(w, 'skip-prompt')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledTimes(1)
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('marks the chosen option as pressed', async () => {
      const w = mountWith(plainAwareness)
      const yes = find(w, 'choice-yes')
      expect(yes.attributes('aria-pressed')).toBe('false')
      await yes.trigger('click')
      expect(yes.attributes('aria-pressed')).toBe('true')
      expect(find(w, 'choice-no').attributes('aria-pressed')).toBe('false')
    })

    it('still moves on if the send fails', async () => {
      store.respond.mockRejectedValueOnce(new Error('down'))
      const w = mountWith(plainAwareness)
      await click(w, 'choice-no')
      await send(w)
      expect(store.respond).toHaveBeenCalled()
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('draws a kind it does not know as a plain list of choices', async () => {
      const w = mountWith(unknownKind)
      expect(keys(w, 'choice')).toEqual([
        'better',
        'worse',
        'dont_care',
        'dont_understand',
      ])
      // Not a blank, and none of the extras an older card drew.
      expect(w.text()).toContain(
        'Would this change be better or worse for you?'
      )
      expect(w.find('img').exists()).toBe(false)
      expect(w.text()).not.toContain('does not draw')
      await click(w, 'choice-worse')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 7,
        ux_answer: {
          choice: 'worse',
          shown: ['better', 'worse', 'dont_care', 'dont_understand'],
        },
        comments: undefined,
      })
    })
  })

  describe('the comment box', () => {
    it('is behind Add a comment, and the link goes once the box is open', async () => {
      const w = mountWith(plainAwareness)
      expect(find(w, 'comment-text').exists()).toBe(false)
      expect(find(w, 'comment-toggle').text()).toBe('Add a comment')
      await click(w, 'comment-toggle')
      expect(find(w, 'comment-text').exists()).toBe(true)
      expect(find(w, 'comment-toggle').exists()).toBe(false)
      expect(w.find('.ux-privacy').text()).toBe(
        'No names, addresses or phone numbers.'
      )
    })

    it('is sent as comments from the second step of the area survey', async () => {
      const w = mountWith(areaPrompt)
      await click(w, 'best-give')
      await click(w, 'step-next')
      await click(w, 'comment-toggle')
      await find(w, 'comment-text').setValue('Chat is fine')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { best: 'give', shown: ['reply', 'login', 'chat', 'give'] },
        comments: 'Chat is fine',
      })
    })

    it('does not exist on an open question, which is all comment already', () => {
      const w = mountWith(openPrompt)
      expect(find(w, 'comment-toggle').exists()).toBe(false)
      expect(find(w, 'comment-text').exists()).toBe(false)
    })
  })

  describe('the area survey', () => {
    it('shows exactly the options the server chose, in its order', () => {
      const w = mountWith(areaPrompt)
      expect(keys(w, 'best')).toEqual(['reply', 'login', 'chat', 'give'])
      expect(find(w, 'best-search').exists()).toBe(false)
    })

    it('falls back to every option when the server sent no display list', () => {
      const w = mountWith({ ...areaPrompt, display: undefined })
      expect(keys(w, 'best')).toEqual([
        'give',
        'reply',
        'login',
        'search',
        'chat',
      ])
    })

    it('skips a display key that is not an option rather than showing a blank', () => {
      const w = mountWith({
        ...areaPrompt,
        display: ['reply', 'nope', 'give'],
      })
      expect(keys(w, 'best')).toEqual(['reply', 'give'])
    })

    it('needs a first pick before Next, which shows the second question without that pick', async () => {
      const w = mountWith(areaPrompt)
      expect(w.attributes('data-step')).toBe('1')
      expect(find(w, 'step-next').attributes('disabled')).toBeDefined()
      expect(find(w, 'submit-prompt').exists()).toBe(false)
      expect(find(w, 'group-worst').exists()).toBe(false)
      // The first question has both ways out.
      expect(keys(w, 'escape')).toEqual(['none', 'dont_understand'])

      await click(w, 'best-give')
      expect(find(w, 'step-next').attributes('disabled')).toBeUndefined()
      await click(w, 'step-next')

      expect(w.attributes('data-step')).toBe('2')
      expect(w.find('.ux-question').text()).toBe(
        'Which other part most needs improving? (optional)'
      )
      expect(keys(w, 'worst')).toEqual(['reply', 'login', 'chat'])
      expect(find(w, 'worst-give').exists()).toBe(false)
      // The first screen's options are gone, and nothing has been sent yet.
      expect(find(w, 'best-reply').exists()).toBe(false)
      expect(store.respond).not.toHaveBeenCalled()

      // The second question has no way out: None of these would send an answer with no first pick.
      expect(w.findAll('[data-testid^="escape-"]')).toHaveLength(0)

      // Back returns to the first question, with both ways out and the first pick kept.
      await click(w, 'step-back')
      expect(w.attributes('data-step')).toBe('1')
      expect(keys(w, 'escape')).toEqual(['none', 'dont_understand'])
      expect(find(w, 'best-give').attributes('aria-pressed')).toBe('true')
    })

    it('sends the first pick alone from the second step, with what was shown', async () => {
      const w = mountWith(areaPrompt)
      await click(w, 'best-give')
      await click(w, 'step-next')
      await send(w)
      expect(store.respond).toHaveBeenCalledTimes(1)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { best: 'give', shown: ['reply', 'login', 'chat', 'give'] },
        comments: undefined,
      })
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('sends the second pick too when one is made, and lets it be taken back', async () => {
      const w = mountWith(areaPrompt)
      await click(w, 'best-give')
      await click(w, 'step-next')
      await click(w, 'worst-login')
      expect(find(w, 'worst-login').attributes('aria-pressed')).toBe('true')
      await click(w, 'worst-login')
      expect(find(w, 'worst-login').attributes('aria-pressed')).toBe('false')
      await click(w, 'worst-chat')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: {
          best: 'give',
          worst: 'chat',
          shown: ['reply', 'login', 'chat', 'give'],
        },
        comments: undefined,
      })
    })

    it('keeps the first pick when the member goes Back', async () => {
      const w = mountWith(areaPrompt)
      await click(w, 'best-give')
      await click(w, 'step-next')
      expect(find(w, 'skip-prompt').exists()).toBe(false)
      await click(w, 'step-back')
      expect(w.attributes('data-step')).toBe('1')
      expect(find(w, 'best-give').attributes('aria-pressed')).toBe('true')
      expect(find(w, 'step-next').attributes('disabled')).toBeUndefined()
    })

    it('clears a second pick that a new first pick makes the same', async () => {
      const w = mountWith(areaPrompt)
      await click(w, 'best-give')
      await click(w, 'step-next')
      await click(w, 'worst-chat')
      await click(w, 'step-back')
      await click(w, 'best-chat')
      await click(w, 'step-next')
      // The first pick is not offered again, and nothing is left pressed.
      expect(find(w, 'worst-chat').exists()).toBe(false)
      for (const k of keys(w, 'worst')) {
        expect(find(w, `worst-${k}`).attributes('aria-pressed')).toBe('false')
      }
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { best: 'chat', shown: ['reply', 'login', 'chat', 'give'] },
        comments: undefined,
      })
    })

    it('offers none of these, which changes Next to Send and sends none with what was shown', async () => {
      const w = mountWith(areaPrompt)
      expect(find(w, 'escape-none').text()).toBe('None of these')
      await click(w, 'best-give')
      expect(find(w, 'step-next').exists()).toBe(true)
      expect(find(w, 'submit-prompt').exists()).toBe(false)
      await click(w, 'escape-none')
      // The pick is taken back, there is no second question, and the button now sends.
      expect(find(w, 'best-give').attributes('aria-pressed')).toBe('false')
      expect(find(w, 'step-next').exists()).toBe(false)
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeUndefined()
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: {
          choice: 'none',
          shown: ['reply', 'login', 'chat', 'give'],
        },
        comments: undefined,
      })
    })

    it('sends dont understand alone, with no shown list', async () => {
      const w = mountWith(areaPrompt)
      await click(w, 'escape-dont_understand')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { choice: 'dont_understand' },
        comments: undefined,
      })
    })

    it('pressing the way out again un-picks it', async () => {
      const w = mountWith(areaPrompt)
      await click(w, 'escape-dont_understand')
      await click(w, 'escape-dont_understand')
      expect(find(w, 'step-next').attributes('disabled')).toBeDefined()
      expect(find(w, 'submit-prompt').exists()).toBe(false)
    })

    it('does not send the way out after the member changed their mind', async () => {
      const w = mountWith(areaPrompt)
      await click(w, 'escape-none')
      await click(w, 'best-give')
      expect(find(w, 'escape-none').attributes('aria-pressed')).toBe('false')
      await click(w, 'step-next')
      await send(w)
      const answer = store.respond.mock.calls[0][0].ux_answer
      expect(answer.best).toBe('give')
      expect(answer.choice).toBeUndefined()
    })

    it('has one step when the prompt has no second question', async () => {
      const w = mountWith({ ...areaPrompt, settings: {} })
      await click(w, 'best-login')
      expect(find(w, 'step-next').exists()).toBe(false)
      expect(find(w, 'group-worst').exists()).toBe(false)
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { best: 'login', shown: ['reply', 'login', 'chat', 'give'] },
        comments: undefined,
      })
    })

    it('words the none way out from the prompt settings', () => {
      const w = mountWith({
        ...areaPrompt,
        settings: { ...areaPrompt.settings, none_label: 'None of them' },
      })
      expect(find(w, 'escape-none').text()).toBe('None of them')
    })

    it('shuffles an old-style prompt once per member when randomise is set', () => {
      const many = Array.from({ length: 7 }, (_, i) => ({
        key: `problem:${i}`,
        label: `Problem ${i}`,
      }))
      const orders = new Set()
      for (let n = 0; n < 8; n++) {
        const w = mountWith({
          ...areaPrompt,
          options: many,
          display: undefined,
          settings: { ...areaPrompt.settings, randomise: true },
        })
        const best = w
          .findAll('[data-testid^="best-"]')
          .map((b) => b.attributes('data-testid'))
        expect([...best].sort()).toEqual(
          many.map((o) => `best-${o.key}`).sort()
        )
        orders.add(best.join('|'))
      }
      expect(orders.size).toBeGreaterThan(1)
    })

    it('labels the group by the question and marks the picks', async () => {
      const w = mountWith(areaPrompt)
      const group = find(w, 'group-best')
      expect(group.attributes('role')).toBe('group')
      const labelId = group.attributes('aria-labelledby')
      expect(w.find(`#${labelId}`).text()).toBe(areaPrompt.question)
      const give = find(w, 'best-give')
      expect(give.attributes('aria-pressed')).toBe('false')
      await give.trigger('click')
      expect(give.attributes('aria-pressed')).toBe('true')
    })

    it('moves focus to the question on the second step, and back', async () => {
      const w = mountWith(areaPrompt, { attachTo: document.body })
      try {
        await click(w, 'best-give')
        await click(w, 'step-next')
        await flushPromises()
        expect(document.activeElement).toBe(w.find('h2').element)
        document.activeElement.blur()
        await click(w, 'step-back')
        await flushPromises()
        expect(document.activeElement).toBe(w.find('h2').element)
      } finally {
        w.unmount()
      }
    })
  })

  describe('the follow-up', () => {
    it('shows the three problems in the order the server chose, with Send at once', () => {
      const w = mountWith(followUpPrompt)
      expect(keys(w, 'best')).toEqual(['problem:2', 'problem:1', 'problem:3'])
      expect(find(w, 'step-next').exists()).toBe(false)
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeDefined()
      expect(find(w, 'escape-none').text()).toBe('None of these')
    })

    it('sends its one pick with what was shown', async () => {
      const w = mountWith(followUpPrompt)
      await click(w, 'best-problem:1')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 15,
        ux_answer: {
          best: 'problem:1',
          shown: ['problem:2', 'problem:1', 'problem:3'],
        },
        comments: undefined,
      })
    })

    it('un-picks a way out and leaves Send disabled', async () => {
      const w = mountWith(followUpPrompt)
      await click(w, 'escape-none')
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeUndefined()
      await click(w, 'escape-none')
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeDefined()
    })
  })

  describe('an awareness question', () => {
    it('shows Yes, No and Not sure in one row, with no ways out, and sends the choice with what was shown', async () => {
      const w = mountWith(awareness)
      expect(keys(w, 'choice')).toEqual(['yes', 'no', 'not_sure'])
      expect(w.find('[data-testid^="escape-"]').exists()).toBe(false)
      const row = find(w, 'choice-yes').element.parentElement
      expect(row.classList.contains('ux-row')).toBe(true)
      expect(row.contains(find(w, 'choice-no').element)).toBe(true)
      expect(row.contains(find(w, 'choice-not_sure').element)).toBe(true)
      await click(w, 'choice-no')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 21,
        ux_answer: { choice: 'no', shown: ['yes', 'no', 'not_sure'] },
        comments: undefined,
      })
    })

    it('tells them the answer with a link and waits for Next', async () => {
      const w = mountWith(awareness)
      await click(w, 'choice-no')
      await send(w)
      expect(w.emitted('next')).toBeUndefined()
      expect(w.attributes('data-step')).toBe('reveal')
      expect(find(w, 'reveal-text').text()).toBe(
        'Yes. Tap the three dots in the chat and choose Promise.'
      )
      expect(find(w, 'reveal-link').attributes('href')).toBe('/chats')
      expect(find(w, 'reveal-link').text()).toBe('Show me')
      // The question stays; the options and the Send button are gone.
      expect(w.find('.ux-question').text()).toBe(awareness.question)
      expect(find(w, 'choice-yes').exists()).toBe(false)
      expect(find(w, 'submit-prompt').exists()).toBe(false)
      await click(w, 'reveal-next')
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('moves focus to the question when the answer is shown', async () => {
      const w = mountWith(awareness, { attachTo: document.body })
      try {
        await click(w, 'choice-yes')
        await send(w)
        expect(find(w, 'reveal').exists()).toBe(true)
        expect(document.activeElement).toBe(w.find('h2').element)
      } finally {
        w.unmount()
      }
    })

    it('shows no link when none is set', async () => {
      const w = mountWith({
        ...awareness,
        settings: { reveal: 'No, Freegle does not do that.' },
      })
      await click(w, 'choice-no')
      await send(w)
      expect(find(w, 'reveal-text').exists()).toBe(true)
      expect(find(w, 'reveal-link').exists()).toBe(false)
    })

    it('does not follow a link that leaves the site', async () => {
      for (const link of [
        'https://example.com/x',
        '//example.com/x',
        'chats',
      ]) {
        const w = mountWith({
          ...awareness,
          settings: { reveal: 'Here.', link },
        })
        await click(w, 'choice-yes')
        await send(w)
        expect(find(w, 'reveal-link').exists()).toBe(false)
      }
    })

    it('does not follow a path that a browser reads as another site', async () => {
      // A browser reads a backslash in a link as a slash, and drops a tab or a new line inside it, so
      // each of these goes to another site although it starts with a single slash.
      for (const link of [
        '/\\evil.example',
        '/\\/evil.example',
        '/\t/evil.example',
        '/\n/evil.example',
        '/ok' + String.fromCharCode(1),
      ]) {
        const w = mountWith({
          ...awareness,
          settings: { reveal: 'Here.', link },
        })
        await click(w, 'choice-yes')
        await send(w)
        expect(find(w, 'reveal').exists(), JSON.stringify(link)).toBe(true)
        expect(find(w, 'reveal-link').exists(), JSON.stringify(link)).toBe(
          false
        )
      }
    })

    it('follows a path on the site, with a query', async () => {
      for (const link of ['/chats', '/settings', '/ok?a=1&b=2']) {
        const w = mountWith({
          ...awareness,
          settings: { reveal: 'Here.', link },
        })
        await click(w, 'choice-yes')
        await send(w)
        expect(find(w, 'reveal-link').attributes('href')).toBe(link)
      }
    })

    it('shows the reveal as text, never as markup', async () => {
      const w = mountWith({
        ...awareness,
        settings: { reveal: '<img src=x onerror=alert(1)>' },
      })
      await click(w, 'choice-yes')
      await send(w)
      expect(w.find('img').exists()).toBe(false)
      expect(find(w, 'reveal-text').text()).toContain('<img src=x')
    })

    it('still reveals when the answer could not be sent', async () => {
      store.respond.mockRejectedValueOnce(new Error('down'))
      const w = mountWith(awareness)
      await click(w, 'choice-yes')
      await send(w)
      expect(find(w, 'reveal-text').exists()).toBe(true)
    })

    it('moves straight on when there is nothing to reveal', async () => {
      const w = mountWith({ ...awareness, settings: {} })
      await click(w, 'choice-yes')
      await send(w)
      expect(w.emitted('next')).toHaveLength(1)
      expect(find(w, 'reveal').exists()).toBe(false)
    })

    it('moves on after the answer for a plain awareness question, even with a link', async () => {
      // The usual awareness question has no reveal: it is a question, not a tip, so there is
      // nothing to teach and nothing to link to.
      const w = mountWith({
        ...awareness,
        settings: { link: '/chats', decoy: false },
      })
      expect(w.text()).toContain(
        'Do you know how to promise an item in a chat?'
      )
      await click(w, 'choice-not_sure')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 21,
        ux_answer: { choice: 'not_sure', shown: ['yes', 'no', 'not_sure'] },
        comments: undefined,
      })
      expect(w.emitted('next')).toHaveLength(1)
      expect(find(w, 'reveal').exists()).toBe(false)
      expect(find(w, 'reveal-link').exists()).toBe(false)
    })
  })

  describe('an open question', () => {
    it('shows the question, the box, the privacy line and both ways out, with no counter or comment link', () => {
      const w = mountWith(openPrompt)
      expect(w.find('.ux-question').text()).toBe(openPrompt.question)
      expect(find(w, 'open-text').exists()).toBe(true)
      expect(w.find('.ux-privacy').text()).toContain(
        'No names, addresses or phone numbers.'
      )
      expect(find(w, 'escape-nothing').text()).toBe('I had no problems')
      expect(find(w, 'escape-dont_understand').text()).toBe(
        "I don't understand"
      )
      expect(find(w, 'open-count').exists()).toBe(false)
      expect(w.text()).not.toContain('characters')
    })

    it('cannot send with nothing, or with fewer than three characters', async () => {
      const w = mountWith(openPrompt)
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeDefined()
      expect(find(w, 'open-more').exists()).toBe(false)
      await find(w, 'open-text').setValue('ab')
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeDefined()
      expect(find(w, 'open-more').text()).toBe('Please write a little more.')
      await find(w, 'open-text').setValue('  a  ')
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeDefined()
      expect(find(w, 'open-more').exists()).toBe(true)
      await find(w, 'open-text').setValue('abc')
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeUndefined()
      expect(find(w, 'open-more').exists()).toBe(false)
    })

    it('sends the trimmed text', async () => {
      const w = mountWith(openPrompt)
      await find(w, 'open-text').setValue('  The photo upload took ages  ')
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeUndefined()
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { text: 'The photo upload took ages' },
        comments: undefined,
      })
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('sends a way out instead of text', async () => {
      const w = mountWith(openPrompt)
      await click(w, 'escape-nothing')
      expect(find(w, 'submit-prompt').attributes('disabled')).toBeUndefined()
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { choice: 'nothing' },
        comments: undefined,
      })
    })

    it('typing after a way out takes the way out back', async () => {
      const w = mountWith(openPrompt)
      await click(w, 'escape-nothing')
      await find(w, 'open-text').setValue('Actually the login was slow')
      expect(find(w, 'escape-nothing').attributes('aria-pressed')).toBe('false')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { text: 'Actually the login was slow' },
        comments: undefined,
      })
    })

    it('a way out after typing clears the text and sends the way out', async () => {
      const w = mountWith(openPrompt)
      await find(w, 'open-text').setValue('something typed')
      await click(w, 'escape-dont_understand')
      expect(find(w, 'open-text').element.value).toBe('')
      await send(w)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { choice: 'dont_understand' },
        comments: undefined,
      })
    })

    it('falls back to the standard ways out when the prompt lists none', () => {
      const w = mountWith({ ...openPrompt, options: [] })
      expect(find(w, 'escape-nothing').text()).toBe("I can't think of one")
      expect(find(w, 'escape-dont_understand').text()).toBe(
        "I don't understand"
      )
    })

    it("takes the words of the ways out from the prompt's options", () => {
      const w = mountWith({
        ...openPrompt,
        options: [
          { key: 'nothing', label: 'Nothing to report' },
          { key: 'dont_understand', label: 'This makes no sense' },
        ],
      })
      expect(find(w, 'escape-nothing').text()).toBe('Nothing to report')
      expect(find(w, 'escape-dont_understand').text()).toBe(
        'This makes no sense'
      )
    })

    it('takes the hint in the box from the settings', () => {
      const w = mountWith(openPrompt)
      expect(find(w, 'open-text').attributes('placeholder')).toBe(
        'If yes, what happened?'
      )
      const bare = mountWith({ ...openPrompt, settings: {} })
      expect(find(bare, 'open-text').attributes('placeholder')).toBeUndefined()
    })

    it('puts the box first, then the privacy line, then Send, then the ways out', () => {
      const w = mountWith(openPrompt)
      const html = w.html()
      const at = (s) => html.indexOf(s)
      const box = at('<textarea')
      const privacy = at('No names, addresses or phone numbers.')
      const skip = at('data-testid="skip-prompt"')
      const submit = at('data-testid="submit-prompt"')
      const nothing = at('data-testid="escape-nothing"')
      expect(box).toBeGreaterThan(-1)
      expect(privacy).toBeGreaterThan(box)
      // Send sits right under the box, so the on-screen keyboard cannot hide it.
      expect(skip).toBeGreaterThan(privacy)
      expect(submit).toBeGreaterThan(skip)
      expect(nothing).toBeGreaterThan(submit)
      expect(find(w, 'skip-prompt').text()).toBe('Skip')
    })

    it('can be skipped, recording a skip', async () => {
      const w = mountWith(openPrompt)
      await click(w, 'skip-prompt')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { skipped: true },
      })
    })

    it('shows the typed text as text, never as markup', async () => {
      const w = mountWith(openPrompt)
      await find(w, 'open-text').setValue('<img src=x onerror=alert(1)>')
      expect(w.find('img').exists()).toBe(false)
    })
  })

  describe('what the card leaves out and how it is built', () => {
    const everyCard = () => [
      mountWith(areaPrompt),
      mountWith(followUpPrompt),
      mountWith(awareness),
      mountWith(openPrompt),
      mountWith(unknownKind),
    ]

    it('draws no introduction, no body line, no counter and no picture for any kind', () => {
      for (const prompt of [
        areaPrompt,
        followUpPrompt,
        awareness,
        openPrompt,
        unknownKind,
      ]) {
        const w = mountWith({
          ...prompt,
          body: 'A line that is never drawn.',
          settings: {
            ...prompt.settings,
            intro: 'An introduction that is never drawn.',
          },
        })
        expect(w.text()).not.toContain('never drawn')
        for (const selector of [
          '.intro-text',
          '.body',
          '.question-label',
          '[data-testid="open-count"]',
          'img',
          'figure',
        ]) {
          expect(w.find(selector).exists(), `${prompt.kind} ${selector}`).toBe(
            false
          )
        }
      }
    })

    it('does not bring back any of the words that were dropped', async () => {
      const dropped = [
        'We want to know',
        'There is no right answer',
        'Think about the last few times',
        'Works best for you',
        'Most needs improving',
        'Or, if none of these fits',
        "Anything you'd like to add",
        'characters',
        'Answer from what you know',
        'Smallest problem for me',
        'None of these has been a problem for me',
        "I don't understand the question",
        'Skip this one',
      ]
      const cards = everyCard()
      const stepTwo = mountWith(areaPrompt)
      await click(stepTwo, 'best-give')
      await click(stepTwo, 'step-next')
      await click(stepTwo, 'comment-toggle')
      cards.push(stepTwo)
      for (const w of cards) {
        for (const words of dropped) {
          expect(w.text()).not.toContain(words)
        }
      }
    })

    it('carries the test id, the prompt id, the kind and the step on its root', async () => {
      const w = mountWith(areaPrompt)
      expect(w.attributes('data-testid')).toBe('ux-card')
      expect(w.attributes('data-prompt-id')).toBe('9')
      expect(w.attributes('data-kind')).toBe('survey')
      expect(w.attributes('data-step')).toBe('1')
      expect(w.classes()).toContain('ux-prompt')
      await click(w, 'best-give')
      await click(w, 'step-next')
      expect(w.attributes('data-step')).toBe('2')
      const aware = mountWith(awareness)
      await click(aware, 'choice-yes')
      await send(aware)
      expect(aware.attributes('data-step')).toBe('reveal')
    })

    it('asks the question in an h2 of the header--size5 size that can take focus', () => {
      for (const w of everyCard()) {
        const h = w.find('h2.ux-question')
        expect(h.exists()).toBe(true)
        expect(h.classes()).toContain('header--size5')
        expect(h.attributes('tabindex')).toBe('-1')
      }
    })

    it('keeps the two ways out in one row of equal width', () => {
      for (const prompt of [areaPrompt, followUpPrompt, openPrompt]) {
        const w = mountWith(prompt)
        const [a, b] = w
          .findAll('[data-testid^="escape-"]')
          .map((e) => e.element)
        expect(a.parentElement).toBe(b.parentElement)
        expect(a.parentElement.classList.contains('ux-row')).toBe(true)
      }
      expect(source).toMatch(
        /\.ux-row\s*\{[\s\S]*?:deep\(\.btn\)\s*\{[^}]*flex:\s*1 1 0/
      )
    })

    it('puts Skip or Back, Add a comment and Send or Next in one sticky row', () => {
      const w = mountWith(areaPrompt)
      const row = w.find('.ux-actions')
      expect(row.exists()).toBe(true)
      expect(row.find('[data-testid="skip-prompt"]').exists()).toBe(true)
      expect(row.find('[data-testid="comment-toggle"]').exists()).toBe(true)
      expect(row.find('[data-testid="step-next"]').exists()).toBe(true)
      expect(source).toMatch(
        /\.ux-actions\s*\{[^}]*position:\s*sticky;[^}]*bottom:\s*0/
      )
    })

    it('gives every button the 44 pixel minimum and lets its words wrap', () => {
      expect(source).toMatch(
        /\.ux-prompt\s+:deep\(\.btn\)\s*\{[^}]*min-height:\s*44px/
      )
      expect(source).toMatch(
        /\.ux-prompt\s+:deep\(\.btn\)\s*\{[^}]*white-space:\s*normal\s*!important/
      )
    })

    it('imports no member store other than the microvolunteering store', () => {
      const imports = [
        ...source.matchAll(/import\s+[^'"]*?from\s+['"]([^'"]+)['"]/g),
      ].map((m) => m[1])
      expect(imports).toContain('~/stores/microvolunteering')
      const members = imports.filter(
        (i) => /stores\//.test(i) || /composables\/useMe/.test(i)
      )
      expect(members).toEqual(['~/stores/microvolunteering'])
    })
  })
})
