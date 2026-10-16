import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxQuestionResult from '~/modtools/components/ModSysAdminUxQuestionResult.vue'
import { uxStubs } from './uxStubs'

// The shapes are the Go responses in iznik-server-go/ux/surveys.go (Survey and SurveyTally): every
// count is a number, and a question has the fields of every kind with zero in the ones it does not use.
const base = {
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
}

const tally = (key, label, over = {}) => ({
  key,
  label,
  most: 0,
  least: 0,
  times_shown: 0,
  count: 0,
  ...over,
})

const area = {
  ...base,
  prompt_id: 9,
  question: 'Which part of Freegle works best for you?',
  responses: 40,
  tallies: [
    tally('give', 'Offering something', { most: 20, least: 4 }),
    tally('login', 'Signing in or joining', { most: 2, least: 18 }),
  ],
  none: 3,
  dont_understand: 1,
  skipped: 5,
  comments: ['Signing in on my phone is a pain', 'Love it'],
}

const awareness = {
  ...base,
  prompt_id: 10,
  kind: 'awareness',
  flow: 'chat_promise',
  question: 'Do you know how to promise an item in a chat?',
  status: 'closed',
  responses: 10,
  yes: 4,
  no: 5,
  not_sure: 1,
}

const decoyQuestion = {
  ...base,
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
}

const open = {
  ...base,
  prompt_id: 11,
  kind: 'open',
  question: 'Did you have a problem the last time you used Freegle?',
  status: 'closed',
  responses: 100,
  nothing: 30,
  dont_understand: 10,
  skipped: 4,
  analysed: true,
}

const followup = {
  ...base,
  prompt_id: 12,
  question: 'Which of these has been your biggest problem?',
  responses: 20,
  tallies: [
    tally('problem:1', 'Photos fail to upload', {
      times_shown: 12,
      most: 9,
      least: 0,
    }),
    tally('problem:2', 'Too many emails', {
      times_shown: 10,
      most: 3,
      least: 0,
    }),
  ],
  none: 2,
}

const big = {
  ...base,
  prompt_id: 14,
  question: 'Which of these has been your biggest problem?',
  status: 'closed',
  responses: 240,
  tallies: [
    tally('problem:1', 'Photos fail to upload', {
      times_shown: 120,
      most: 60,
      least: 0,
    }),
  ],
}

const NO_DECOYS = { questions: 0, answers: 0, yes: 0 }

// The second sentence of the base line below 100 answers.
const BELOW_100 =
  'Below 100 answers these are counts, not percentages, because a few more answers can change them a lot.'

const make = (question, decoy = NO_DECOYS) =>
  mount(ModSysAdminUxQuestionResult, {
    props: { question, decoy },
    global: { stubs: uxStubs },
  })

// The columns the table was given.
const columns = (w) =>
  w
    .findComponent(uxStubs['b-table'])
    .props('fields')
    .map((f) => f.label)

describe('ModSysAdminUxQuestionResult', () => {
  describe('the area survey', () => {
    it('tallies as counts below 100 answers, most needs improving first', () => {
      const w = make(area)
      const t = w.text()
      expect(t).toContain('Of the 40 who answered')
      expect(t).toContain('Signing in or joining')
      expect(t).toContain('18 of 40')
      expect(t).toContain('20 of 40')
      expect(t).not.toContain('%')
      expect(t.indexOf('Signing in or joining')).toBeLessThan(
        t.indexOf('Offering something')
      )
    })

    it('says counts, not percentages, below 100 answers, and no more from 100', () => {
      expect(make(area).find('[data-testid="base"]').text()).toBe(
        `Of the 40 who answered. ${BELOW_100}`
      )
      expect(make(big).find('[data-testid="base"]').text()).toBe(
        'Of the 240 who answered.'
      )
    })

    // The rule of the whole section: a percentage only from 100 answers. The sentence and the table
    // must change at the same number, so 99 and 100 are tried, with a figure that has a percentage.
    it('has the caution and no percentage at 99 answers', () => {
      const w = make({ ...area, responses: 99 })
      expect(w.find('[data-testid="base"]').text()).toBe(
        `Of the 99 who answered. ${BELOW_100}`
      )
      expect(w.text()).not.toContain('%')
      expect(w.text()).toContain('20 of 99')
    })

    it('has no caution, and the percentage in the table, at exactly 100 answers', () => {
      const w = make({ ...area, responses: 100 })
      expect(w.find('[data-testid="base"]').text()).toBe(
        'Of the 100 who answered.'
      )
      expect(w.text()).toContain('20 of 100 (20%)')
      expect(w.text()).not.toContain('Below 100')
    })

    it('has two picks, in the words of the card', () => {
      expect(columns(make(area))).toEqual([
        'Area',
        'Works best',
        'Most needs improving',
      ])
    })

    it('gives the ways out and the skips', () => {
      const t = make(area).text()
      expect(t).toContain(
        '3 said none of these; 1 did not understand the question.'
      )
      expect(t).toContain('5 skipped it.')
    })

    it('adds the times shown when the server has them, and judges each option against them', () => {
      const shown = {
        ...area,
        tallies: [
          tally('give', 'Offering something', {
            most: 6,
            least: 2,
            times_shown: 24,
          }),
        ],
      }
      const w = make(shown)
      expect(columns(w)).toEqual([
        'Area',
        'Times shown',
        'Works best',
        'Most needs improving',
      ])
      expect(w.text()).toContain('6 of 24')
      expect(w.text()).toContain('2 of 24')
    })

    // The server counts none and the older name, dont_care, as two figures, and a survey can hold both.
    // Both are answers of none of these, so the line adds them. Each figure is tried alone and
    // together, with the whole line compared, so a sum that became a difference or a maximum fails.
    it('reads the older name for none of these', () => {
      const old = { ...area, none: 0, dont_care: 4, dont_understand: 0 }
      expect(make(old).find('.ways-out').text()).toBe('4 said none of these.')
    })

    it('reads the newer name for none of these alone', () => {
      const now = { ...area, none: 3, dont_care: 0, dont_understand: 0 }
      expect(make(now).find('.ways-out').text()).toBe('3 said none of these.')
    })

    it('adds the two names when a survey has both, and keeps the ones who did not understand apart', () => {
      const both = { ...area, none: 3, dont_care: 4, dont_understand: 1 }
      expect(make(both).find('.ways-out').text()).toBe(
        '7 said none of these; 1 did not understand the question.'
      )
    })

    it('says nobody has answered yet, and gives no table rows', () => {
      const w = make({ ...area, responses: 0, comments: [] })
      expect(w.text()).toContain('Nobody has answered yet.')
      expect(w.text()).toContain('0')
    })
  })

  describe('the follow-up that ranks the biggest problems', () => {
    it('is worded in its own terms, with one pick and no column for the smallest problem', () => {
      const w = make(followup)
      expect(columns(w)).toEqual([
        'Problem',
        'Times shown',
        'Picked as the biggest problem',
      ])
      expect(w.text()).not.toMatch(/smallest/i)
    })

    it('lists the problem picked most first', () => {
      const t = make(followup).text()
      expect(t.indexOf('Photos fail to upload')).toBeLessThan(
        t.indexOf('Too many emails')
      )
    })

    it('shows each problem against the times it was shown', () => {
      const t = make(followup).text()
      expect(t).toContain('9 of 12')
      expect(t).toContain('3 of 10')
      expect(t).toContain('2 said none of these')
    })

    it('adds the percentage from 100 answers', () => {
      const t = make(big).text()
      expect(t).toContain('60 of 120 (50%)')
    })

    it('never shows the figure for the smallest problem', () => {
      // An old server may still send it. It is not drawn.
      const withLeast = {
        ...followup,
        tallies: followup.tallies.map((t) => ({ ...t, least: 7 })),
      }
      expect(make(withLeast).text()).not.toContain('7 of')
    })
  })

  describe('an awareness question', () => {
    it('is yes, no and not sure, as counts only', () => {
      const w = make(awareness)
      const t = w.text()
      expect(columns(w)).toEqual(['Answer', 'Members'])
      expect(t).toContain('4 of 10')
      expect(t).toContain('5 of 10')
      expect(t).toContain('1 of 10')
      expect(t).not.toContain('%')
      // None of these means nothing for awareness.
      expect(t).not.toContain('none of these')
    })

    it('says what the right answer is, and how many gave it, per kind', () => {
      expect(make(awareness).find('.right-answer').text()).toBe(
        'Right answer (Yes): 4 of 10.'
      )
      expect(make(decoyQuestion).find('.right-answer').text()).toBe(
        'Right answer (No): 10 of 20.'
      )
    })

    it('says a decoy is a decoy, and a real question is not', () => {
      expect(make(decoyQuestion).find('.decoy-line').text()).toBe(
        'Decoy: Freegle does not do this.'
      )
      expect(make(awareness).find('.decoy-line').exists()).toBe(false)
      expect(make(awareness).text()).not.toContain('Decoy')
    })

    it('shows the yes-saying rate across the decoys beside a real question, and not on a decoy', () => {
      const check = { questions: 1, answers: 20, yes: 8 }
      expect(
        make(awareness, check).find('[data-testid="decoy-check"]').text()
      ).toBe(
        'Yes-saying check: across 1 decoy question about things Freegle does not do, 8 of 20 answers were Yes. So some Yes answers on this question may be guesses.'
      )
      expect(
        make(decoyQuestion, check).find('[data-testid="decoy-check"]').exists()
      ).toBe(false)
    })

    it('says "questions" for more than one decoy', () => {
      const check = { questions: 2, answers: 150, yes: 30 }
      expect(
        make(awareness, check).find('[data-testid="decoy-check"]').text()
      ).toContain('across 2 decoy questions about things')
      expect(
        make(awareness, check).find('[data-testid="decoy-check"]').text()
      ).toContain('30 of 150 (20%) answers were Yes')
    })

    it('shows no yes-saying line when there are no decoy questions', () => {
      expect(
        make(awareness, NO_DECOYS).find('[data-testid="decoy-check"]').exists()
      ).toBe(false)
    })

    it('says how many did not understand it, in the ways-out line', () => {
      const t = make({ ...awareness, dont_understand: 2 }).text()
      expect(t).toContain('2 did not understand the question.')
    })

    it('still reads an older question made of options of its own', () => {
      const old = {
        ...awareness,
        yes: undefined,
        no: undefined,
        not_sure: undefined,
        responses: 10,
        tallies: [
          tally('use', 'Yes, and I use it', { count: 4 }),
          tally('no', "No, I didn't know", { count: 2 }),
          tally('dont_understand', "I don't understand", { count: 1 }),
        ],
        dont_understand: 1,
      }
      const w = make(old)
      const t = w.text()
      expect(t).toContain("No, I didn't know")
      expect(t).toContain('2 of 10')
      expect(t).toContain('1 of 10')
      expect(t).toContain('1 did not understand the question.')
      // It has no yes or no to be right or wrong about.
      expect(w.find('.right-answer').exists()).toBe(false)
    })

    it('adds the percentage from 100 answers', () => {
      const t = make({
        ...awareness,
        responses: 200,
        yes: 50,
        no: 100,
        not_sure: 50,
      }).text()
      expect(t).toContain('50 of 200 (25%)')
      expect(t).toContain('100 of 200 (50%)')
    })
  })

  describe('an open question', () => {
    it('is counts only, never the text, with its analysis state', () => {
      const w = make(open)
      expect(w.find('.open-counts').text()).toBe(
        'Of the 100 who answered, 60 wrote something, 30 had nothing to tell us, 10 did not understand the question. The answers have been analysed. The results are in Problems and What works.'
      )
      expect(w.text()).toContain('4 skipped it.')
      expect(w.text()).not.toContain('none of these')
      expect(w.find('[data-testid="base"]').exists()).toBe(false)
      expect(w.find('table').exists()).toBe(false)
    })

    it('says how many written answers the analysis has not read', () => {
      const t = make({ ...open, analysed_answers: 25 })
        .find('.open-counts')
        .text()
      expect(t).toContain(
        'The results were drawn from 25 of the 60 written answers. 35 have arrived since and have not been analysed.'
      )
      expect(t).not.toContain('The answers have been analysed')
    })

    it('does not claim a gap when the analysis read every written answer', () => {
      expect(
        make({ ...open, analysed_answers: 60 })
          .find('.open-counts')
          .text()
      ).toContain('The answers have been analysed.')
    })

    it('says when it has not been analysed', () => {
      expect(
        make({ ...open, analysed: false })
          .find('.open-counts')
          .text()
      ).toContain('The answers have not been analysed yet.')
    })

    it('does not count a ways-out line for an open question', () => {
      expect(
        make({ ...open, none: 3 })
          .find('.ways-out')
          .exists()
      ).toBe(false)
    })
  })

  describe('what members wrote', () => {
    it('keeps comments behind a toggle, with the count', async () => {
      const w = make(area)
      expect(w.text()).not.toContain('Signing in on my phone is a pain')
      const toggle = w.find('.said .said-toggle')
      expect(toggle.text()).toContain('What members wrote (2)')
      await toggle.trigger('click')
      expect(w.text()).toContain('Signing in on my phone is a pain')
    })

    it('escapes what members wrote', async () => {
      const w = make({ ...area, comments: ['<img src=x onerror=alert(1)>'] })
      await w.find('.said .said-toggle').trigger('click')
      expect(w.find('img').exists()).toBe(false)
      expect(w.text()).toContain('<img src=x onerror=alert(1)>')
    })

    it('says how many are shown when there are more than are listed', async () => {
      const w = make({
        ...area,
        comments: Array.from({ length: 20 }, (_, i) => `comment ${i}`),
      })
      await w.find('.said .said-toggle').trigger('click')
      expect(w.text()).toContain('Showing 8 of 20.')
      expect(w.text()).toContain('The newest 20 are all that is kept here.')
      expect(w.text()).not.toContain('comment 8')
    })

    it('draws nothing when there are no comments', () => {
      expect(
        make({ ...area, comments: [] })
          .find('.said')
          .exists()
      ).toBe(false)
    })
  })

  describe('the words', () => {
    it('never prints a margin of error', () => {
      for (const q of [area, awareness, open, followup, big]) {
        expect(make(q).text()).not.toMatch(
          /margin of error|confidence interval|±/i
        )
      }
    })

    it('says funnel, never flow, and shows no raw key', () => {
      for (const q of [area, awareness, open, followup, decoyQuestion]) {
        const t = make(q, { questions: 1, answers: 20, yes: 8 }).text()
        expect(t).not.toMatch(/\bflow\b/i)
        expect(t).not.toContain('problem:1')
        expect(t).not.toContain('chat_promise')
      }
    })

    // Staff read these in a second language as often as not, so no phrase may need a native ear, and
    // one step is called by one name: analysed, never read.
    it('uses no idiom, and calls the coding of the answers analysis everywhere', () => {
      const partial = { ...open, analysed_answers: 25 }
      for (const q of [area, awareness, open, partial, followup, big]) {
        const t = make(q, { questions: 1, answers: 20, yes: 8 }).text()
        expect(t).not.toMatch(/the picture|allow for|been read|not been read/i)
      }
    })
  })
})
