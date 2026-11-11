import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxQuestions from '~/modtools/components/ModSysAdminUxQuestions.vue'
import { useUxSurveysStore } from '~/modtools/stores/uxSurveys'
import { uxStubs } from './uxStubs'

// The shapes are the Go responses in iznik-server-go/ux/surveys.go.
const q = (over) => ({
  prompt_id: 1,
  kind: 'survey',
  flow: '',
  question: 'A question?',
  status: 'open',
  responses: 0,
  tallies: [],
  dont_care: 0,
  dont_understand: 0,
  nothing: 0,
  none: 0,
  skipped: 0,
  analysed: false,
  analysed_answers: 0,
  comments: [],
  yes: 0,
  no: 0,
  not_sure: 0,
  decoy: false,
  right_share: 0,
  ...over,
})

const QUESTIONS = [
  q({
    prompt_id: 14,
    question: 'Which of these has been your biggest problem?',
    status: 'open',
    responses: 64,
    tallies: [
      {
        key: 'problem:1',
        label: 'Photos fail to upload',
        most: 9,
        least: 0,
        times_shown: 12,
        count: 0,
      },
    ],
  }),
  q({
    prompt_id: 13,
    kind: 'awareness',
    flow: 'give',
    question: 'Do you know how to pay to be shown first?',
    status: 'closed',
    responses: 20,
    yes: 8,
    no: 10,
    not_sure: 2,
    decoy: true,
  }),
  q({
    prompt_id: 12,
    kind: 'awareness',
    flow: 'chat_promise',
    question: 'Do you know how to promise an item in a chat?',
    status: 'closed',
    responses: 10,
    yes: 4,
    no: 5,
    not_sure: 1,
  }),
  q({
    prompt_id: 11,
    kind: 'open',
    question: 'Did you have a problem the last time you used Freegle?',
    status: 'closed',
    responses: 100,
    nothing: 30,
    dont_understand: 10,
    analysed: true,
  }),
  q({
    prompt_id: 9,
    question: 'Which part of Freegle works best for you?',
    status: 'open',
    responses: 40,
    tallies: [
      {
        key: 'give',
        label: 'Offering something',
        most: 20,
        least: 4,
        times_shown: 0,
        count: 0,
      },
    ],
  }),
]

const view = (open = '') => ({ props: { open } })

describe('ModSysAdminUxQuestions', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxSurveysStore()
    store.questions.data = {
      questions: JSON.parse(JSON.stringify(QUESTIONS)),
      decoy_yes_rate: 0.4,
      decoy_answers: 20,
    }
  })

  const make = (open = '') =>
    mount(ModSysAdminUxQuestions, { ...view(open), global: { stubs: uxStubs } })

  const rows = (w) => w.findAll('.question-row')

  it('lists each question on one line, in the order the server sent them', () => {
    const w = make()
    expect(rows(w)).toHaveLength(5)
    expect(rows(w).map((r) => r.find('.text').text())).toEqual([
      'Which of these has been your biggest problem?',
      'Do you know how to pay to be shown first?',
      'Do you know how to promise an item in a chat?',
      'Did you have a problem the last time you used Freegle?',
      'Which part of Freegle works best for you?',
    ])
  })

  it('names the kind of each: problem ranking, awareness, written answers and area ranking', () => {
    const w = make()
    expect(rows(w).map((r) => r.find('.kind').text())).toEqual([
      'Problem ranking',
      'Awareness',
      'Awareness',
      'Written answers',
      'Area ranking',
    ])
  })

  // The state is Open or Closed. A kind that was also called Open put "Open question" beside "Closed".
  it('never puts the word open in the name of a kind, so Open and Closed mean only the state', () => {
    const w = make()
    for (const r of rows(w)) {
      expect(r.find('.kind').text()).not.toMatch(/\bopen\b|\bclosed\b/i)
    }
    expect(rows(w).map((r) => r.find('.status').text())).toContain('Open')
    expect(rows(w).map((r) => r.find('.status').text())).toContain('Closed')
  })

  it('says how many answered and whether it is open or closed', () => {
    const w = make()
    expect(rows(w).map((r) => r.find('.answered').text())).toEqual([
      '64 answered',
      '20 answered',
      '10 answered',
      '100 answered',
      '40 answered',
    ])
    expect(rows(w).map((r) => r.find('.status').text())).toEqual([
      'Open',
      'Closed',
      'Closed',
      'Closed',
      'Open',
    ])
  })

  it('opens nothing until one is chosen', () => {
    const w = make()
    expect(w.find('.question-body').exists()).toBe(false)
    expect(rows(w).map((r) => r.attributes('aria-expanded'))).toEqual(
      Array(5).fill('false')
    )
  })

  it('asks to open the question pressed, and to close the one that is open', async () => {
    const w = make()
    await rows(w)[2].trigger('click')
    expect(w.emitted('navigate')).toStrictEqual([[{ question: '12' }]])

    const open = make('12')
    await rows(open)[2].trigger('click')
    expect(open.emitted('navigate')).toStrictEqual([[{ question: undefined }]])
  })

  it('opens the question the address names, and only that one', () => {
    const w = make('12')
    expect(w.findAll('.question-body')).toHaveLength(1)
    expect(rows(w).map((r) => r.attributes('aria-expanded'))).toEqual([
      'false',
      'false',
      'true',
      'false',
      'false',
    ])
    expect(w.find('.question-body').text()).toContain(
      'Right answer (Yes): 4 of 10.'
    )
    expect(rows(w)[2].attributes('aria-controls')).toBe(
      w.find('.question-body').attributes('id')
    )
  })

  it('gives each real question the yes-saying check across the decoys, and not the decoy itself', () => {
    const real = make('12')
    expect(real.find('[data-testid="decoy-check"]').text()).toContain(
      'across 1 decoy question about things Freegle does not do, 8 of 20 answers were Yes.'
    )
    const decoy = make('13')
    expect(decoy.find('[data-testid="decoy-check"]').exists()).toBe(false)
    expect(decoy.find('.decoy-line').text()).toBe(
      'Decoy: Freegle does not do this.'
    )
  })

  it('leaves a decoy with no answers out of the yes-saying check', () => {
    store.questions.data.questions[1].responses = 0
    store.questions.data.questions[1].yes = 0
    store.questions.data.questions[1].no = 0
    store.questions.data.questions[1].not_sure = 0
    const w = make('12')
    expect(w.find('[data-testid="decoy-check"]').exists()).toBe(false)
  })

  it('says when the question in the address is not in the list, and still lists the rest', () => {
    const w = make('999')
    expect(w.find('.not-in-list').text()).toBe(
      'That question is not in the list.'
    )
    expect(rows(w)).toHaveLength(5)
    expect(w.find('.question-body').exists()).toBe(false)
  })

  it('says nothing about a missing question when none is named', () => {
    expect(make().find('.not-in-list').exists()).toBe(false)
  })

  it('shows the empty message when nothing has been asked', () => {
    store.questions.data = { questions: [] }
    const w = make()
    // The list also holds the questions that members answer in their own words, so it names no kind.
    expect(w.find('.none').text()).toBe('No questions have been asked yet.')
    expect(rows(w)).toHaveLength(0)
  })

  it('says this part is not switched on when the route is missing, and shows no fault', () => {
    store.questions.data = null
    store.questions.missing = true
    const w = make()
    expect(w.text()).toContain('This part is not switched on yet.')
    expect(w.find('.notice-message').exists()).toBe(false)
  })

  it('shows a fault as a notice', () => {
    store.questions.data = null
    store.questions.error = 'boom'
    const w = make()
    expect(w.find('.notice-message').text()).toBe('boom')
    expect(rows(w)).toHaveLength(0)
  })

  it('says it is loading before the first answer', () => {
    store.questions.data = null
    store.questions.loading = true
    const w = make()
    expect(w.text()).toContain('Loading...')
  })

  it('says funnel, never flow, and draws no raw key', () => {
    const t = make('13').text()
    expect(t).not.toMatch(/\bflow\b/i)
    expect(t).not.toContain('chat_promise')
    expect(t).not.toContain('problem:1')
  })
})
