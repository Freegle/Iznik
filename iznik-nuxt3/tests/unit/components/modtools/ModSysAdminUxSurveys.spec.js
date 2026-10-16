import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxSurveys from '~/modtools/components/ModSysAdminUxSurveys.vue'
import { uxStubs } from './uxStubs'

const fetchers = {
  fetchProblemGroups: vi.fn(),
  fetchProblems: vi.fn(),
  fetchValues: vi.fn(),
  fetchQuestions: vi.fn(),
}
vi.mock('~/api', () => ({
  default: () => ({ uxsurveys: fetchers }),
}))

// The registry is F's, and it imports every section. A fake one stands in.
vi.mock('~/modtools/composables/useUxSections', () => ({
  hostedFor: () => [],
  uxSections: () => [],
}))

// The shapes are the Go responses in iznik-server-go/ux/surveys.go.
const GROUPS = () => ({
  answers: 120,
  left_over: 4,
  groups: [
    {
      name: 'Photos and posting',
      problems: 3,
      mentions: 41,
      biggest: {
        id: 4,
        title: 'Photos fail to upload',
        mentions: 14,
        answers: 120,
      },
    },
    {
      name: 'Finding things',
      problems: 2,
      mentions: 9,
      biggest: {
        id: 9,
        title: 'Search misses things',
        mentions: 6,
        answers: 120,
      },
    },
    {
      name: 'Other',
      problems: 1,
      mentions: 3,
      biggest: { id: 11, title: 'The site is slow', mentions: 3, answers: 120 },
    },
  ],
})

const problemOf = (id, over = {}) => ({
  id,
  title: `Problem ${id}`,
  description: `Description of ${id}.`,
  flow: 'give',
  group: 'Photos and posting',
  mentions: 10,
  answers: 120,
  asked: 0,
  weight: null,
  most: null,
  times_shown: null,
  quotes: [],
  suggestions: [],
  status: 'open',
  ...over,
})

const survey = (over) => ({
  prompt_id: 1,
  kind: 'survey',
  flow: '',
  question: 'Which part of Freegle works best for you?',
  status: 'open',
  responses: 40,
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

const QUESTIONS = () => ({
  questions: [
    survey({ prompt_id: 3 }),
    survey({
      prompt_id: 2,
      kind: 'awareness',
      question: 'Do you know how to promise an item?',
      yes: 4,
      no: 5,
      not_sure: 1,
      responses: 10,
    }),
    survey({
      prompt_id: 1,
      kind: 'open',
      question: 'Did you have a problem?',
      responses: 100,
    }),
  ],
  decoy_yes_rate: 0,
  decoy_answers: 0,
})

// A view the way useUxView gives it.
const viewOf = (extra = {}, flow = '') => ({
  section: 'members',
  period: '7days',
  from: '',
  to: '',
  device: '',
  signed: '',
  flow,
  step: '',
  open: '',
  uses: [],
  filtersActive: false,
  filterParams: {},
  extra,
})

describe('ModSysAdminUxSurveys', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    fetchers.fetchProblemGroups.mockResolvedValue(GROUPS())
    fetchers.fetchProblems.mockResolvedValue({ total: 0, problems: [] })
    fetchers.fetchValues.mockResolvedValue({
      values: [
        { id: 1, title: 'It is free', mentions: 30, answers: 100, quotes: [] },
      ],
    })
    fetchers.fetchQuestions.mockResolvedValue(QUESTIONS())
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  // hrefFor writes the patch into the address so a test can read what a link would do.
  const make = async (extra = {}, flow = '') => {
    const w = mount(ModSysAdminUxSurveys, {
      props: { view: viewOf(extra, flow) },
      global: {
        stubs: uxStubs,
        provide: { uxHrefFor: (patch) => `#${JSON.stringify(patch)}` },
      },
    })
    await flushPromises()
    return w
  }

  const links = (w) => w.findAll('.view-link')

  describe('the line at the top', () => {
    it('counts the problems, the groups, the answers and the questions', async () => {
      const w = await make()
      expect(w.find('.summary').text()).toBe(
        '6 problems in 3 groups, from 120 written answers. 3 questions asked.'
      )
    })

    it('says "1 problem in 1 group, from 1 written answer. 1 question asked."', async () => {
      fetchers.fetchProblemGroups.mockResolvedValue({
        answers: 1,
        left_over: 0,
        groups: [
          {
            name: 'Other',
            problems: 1,
            mentions: 1,
            biggest: { id: 1, title: 'A', mentions: 1, answers: 1 },
          },
        ],
      })
      fetchers.fetchQuestions.mockResolvedValue({ questions: [survey({})] })
      const w = await make()
      expect(w.find('.summary').text()).toBe(
        '1 problem in 1 group, from 1 written answer. 1 question asked.'
      )
    })

    it('leaves the questions out until they arrive, and draws nothing before the groups do', async () => {
      let resolveQuestions
      fetchers.fetchQuestions.mockReturnValue(
        new Promise((r) => (resolveQuestions = r))
      )
      const w = await make()
      expect(w.find('.summary').text()).toBe(
        '6 problems in 3 groups, from 120 written answers.'
      )
      resolveQuestions(QUESTIONS())
      await flushPromises()
      expect(w.find('.summary').text()).toContain('3 questions asked.')

      fetchers.fetchProblemGroups.mockReturnValue(new Promise(() => {}))
      setActivePinia(createPinia())
      expect((await make()).find('.summary').exists()).toBe(false)
    })
  })

  describe('the caution', () => {
    it('says who answered, what a count is out of, and that a percentage needs 100 answers', async () => {
      const w = await make()
      expect(w.find('.caution').text()).toContain(
        'These are the members who chose to answer. Each count is shown with the number it is out of, and there are no percentages below 100 answers.'
      )
    })

    it('keeps the longer explanation closed until How to read these numbers is pressed', async () => {
      const w = await make()
      const button = w.find('.how-toggle')
      expect(button.text()).toBe('How to read these numbers')
      expect(button.attributes('aria-expanded')).toBe('false')
      expect(w.find('.how').exists()).toBe(false)

      await button.trigger('click')
      expect(button.attributes('aria-expanded')).toBe('true')
      const t = w.find('.how').text()
      expect(t).toContain('They chose to answer')
      expect(t).toContain('prefer what they know')
      expect(t).toContain('No error range is shown')
      expect(t).toContain('is in none of the figures')
      expect(t).toContain('A mention is one answer that names a problem')
      // The base of a problem is the written answers that were analysed; of a question, who answered it.
      expect(t).toContain('Every count is shown with the number it is out of.')
      expect(t).toContain(
        'For a problem or a value, that is the written answers that were analysed for the question it came from.'
      )
      expect(t).toContain('For a question, it is the members who answered it.')
      expect(t).toContain('A percentage appears only from 100 answers.')
      expect(w.find('.how').attributes('id')).toBe(
        button.attributes('aria-controls')
      )

      await button.trigger('click')
      expect(w.find('.how').exists()).toBe(false)
    })
  })

  describe('the three views', () => {
    it('are Problems, What works and Questions, with Problems open to begin with', async () => {
      const w = await make()
      expect(links(w).map((l) => l.text())).toEqual([
        'Problems',
        'What works',
        'Questions',
      ])
      expect(links(w).map((l) => l.attributes('aria-current'))).toEqual([
        'page',
        undefined,
        undefined,
      ])
      expect(w.find('.ux-problem-groups').exists()).toBe(true)
      expect(w.find('.ux-values').exists()).toBe(false)
      expect(w.find('.ux-questions').exists()).toBe(false)
    })

    it('are links with the address of each, which close whatever was open in the one before', async () => {
      const w = await make({ group: 'Other', problem: '4' })
      // The patch is written as JSON for this test, which leaves out what is undefined: the keys that
      // close the group, the problem and the question are not in the text, and the view is not either
      // for Problems, which is the default and is left out of the address.
      const patches = links(w).map((l) =>
        JSON.parse(l.attributes('href').slice(1))
      )
      expect(patches[0]).toEqual({})
      expect(patches[1]).toEqual({ view: 'values' })
      expect(patches[2]).toEqual({ view: 'questions' })
    })

    it('open the Values view from the address', async () => {
      const w = await make({ view: 'values' })
      expect(links(w).map((l) => l.attributes('aria-current'))).toEqual([
        undefined,
        'page',
        undefined,
      ])
      expect(w.find('.ux-values').exists()).toBe(true)
      expect(w.find('.ux-problem-groups').exists()).toBe(false)
      expect(w.find('.ux-values').text()).toContain(
        'It is free: 30 of 100 (30%)'
      )
    })

    it('open the Questions view from the address, with the question named there', async () => {
      const w = await make({ view: 'questions', question: '2' })
      expect(w.find('.ux-questions').exists()).toBe(true)
      expect(w.findAll('.question-row')).toHaveLength(3)
      expect(w.find('.question-body').text()).toContain(
        'Right answer (Yes): 4 of 10.'
      )
    })

    it('fall back to Problems for a view it does not know', async () => {
      const w = await make({ view: 'nonsense' })
      expect(links(w)[0].attributes('aria-current')).toBe('page')
      expect(w.find('.ux-problem-groups').exists()).toBe(true)
    })

    it('ask for the view pressed, and for nothing else open', async () => {
      const w = await make({ group: 'Other', problem: '4' })
      await links(w)[1].trigger('click')
      expect(w.emitted('navigate')).toStrictEqual([
        [
          {
            view: 'values',
            group: undefined,
            problem: undefined,
            question: undefined,
          },
        ],
      ])
      await links(w)[0].trigger('click')
      expect(w.emitted('navigate')[1]).toStrictEqual([
        {
          view: undefined,
          group: undefined,
          problem: undefined,
          question: undefined,
        },
      ])
    })

    it('leave a click with a modifier to the browser, which opens the link apart', async () => {
      const w = await make()
      const click = new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        button: 0,
        ctrlKey: true,
      })
      links(w)[1].element.dispatchEvent(click)
      expect(click.defaultPrevented).toBe(false)
      expect(w.emitted('navigate')).toBeUndefined()
    })
  })

  describe('what is asked for', () => {
    it('asks for the groups and the questions when it opens, and not for the values', async () => {
      await make()
      expect(fetchers.fetchProblemGroups).toHaveBeenCalledTimes(1)
      expect(fetchers.fetchProblemGroups).toHaveBeenCalledWith({})
      expect(fetchers.fetchQuestions).toHaveBeenCalledTimes(1)
      expect(fetchers.fetchValues).not.toHaveBeenCalled()
    })

    it('asks for the values when the Values view is open', async () => {
      await make({ view: 'values' })
      expect(fetchers.fetchValues).toHaveBeenCalledTimes(1)
    })

    it('asks for the values once the person moves to the Values view', async () => {
      const w = await make()
      expect(fetchers.fetchValues).not.toHaveBeenCalled()
      await w.setProps({ view: viewOf({ view: 'values' }) })
      await flushPromises()
      expect(fetchers.fetchValues).toHaveBeenCalledTimes(1)
      await w.setProps({ view: viewOf({}) })
      await w.setProps({ view: viewOf({ view: 'values' }) })
      await flushPromises()
      expect(fetchers.fetchValues).toHaveBeenCalledTimes(1)
    })

    // The tab draws the section anew each time a person comes back to it, while the store lives on. A
    // new visit asks for the values again, like the groups and the questions, and does not draw the
    // values of the visit before beside problems that are new.
    it('asks for the values again when the section is opened again, and draws the new ones', async () => {
      const values = (title) => ({
        values: [{ id: 1, title, mentions: 3, answers: 10, quotes: [] }],
      })
      fetchers.fetchValues.mockResolvedValue(values('First visit value'))
      const first = await make({ view: 'values' })
      expect(first.find('.ux-values').text()).toContain('First visit value')
      first.unmount()

      fetchers.fetchValues.mockResolvedValue(values('Second visit value'))
      const second = await make({ view: 'values' })
      expect(fetchers.fetchProblemGroups).toHaveBeenCalledTimes(2)
      expect(fetchers.fetchQuestions).toHaveBeenCalledTimes(2)
      expect(fetchers.fetchValues).toHaveBeenCalledTimes(2)
      expect(second.find('.ux-values').text()).toContain('Second visit value')
      expect(second.find('.ux-values').text()).not.toContain(
        'First visit value'
      )
    })

    it('does not ask for the values on a new visit that opens on Problems, until What works is chosen', async () => {
      const first = await make({ view: 'values' })
      first.unmount()
      fetchers.fetchValues.mockClear()
      const second = await make()
      expect(fetchers.fetchValues).not.toHaveBeenCalled()
      await second.setProps({ view: viewOf({ view: 'values' }) })
      await flushPromises()
      expect(fetchers.fetchValues).toHaveBeenCalledTimes(1)
    })

    it('asks for the groups again when the funnel changes, and when the search does', async () => {
      const w = await make()
      await w.setProps({ view: viewOf({}, 'give') })
      expect(fetchers.fetchProblemGroups).toHaveBeenLastCalledWith({
        flow: 'give',
      })
      await w.setProps({ view: viewOf({ q: 'photo' }, 'give') })
      expect(fetchers.fetchProblemGroups).toHaveBeenLastCalledWith({
        flow: 'give',
        q: 'photo',
      })
      expect(fetchers.fetchProblemGroups).toHaveBeenCalledTimes(3)
    })

    // The funnel and the search narrow the problems. What works and Questions draw neither, so the line
    // at the top must not be narrowed by them: a count that is filtered with no sign of it on screen
    // reads as a count of everything.
    describe('with a funnel and a search in the address', () => {
      const FILTERED = () => ({
        answers: 30,
        left_over: 0,
        groups: [GROUPS().groups[0]],
      })
      const WHOLE =
        '6 problems in 3 groups, from 120 written answers. 3 questions asked.'

      beforeEach(() => {
        fetchers.fetchProblemGroups.mockImplementation(async (p) =>
          p.flow || p.q ? FILTERED() : GROUPS()
        )
      })

      it('asks for the groups with them on Problems, and the line counts what they leave', async () => {
        const w = await make({ q: 'photo' }, 'give')
        expect(fetchers.fetchProblemGroups).toHaveBeenLastCalledWith({
          flow: 'give',
          q: 'photo',
        })
        expect(w.find('.summary').text()).toBe(
          '3 problems in 1 group, from 30 written answers. 3 questions asked.'
        )
        expect(w.find('.funnel-notice').exists()).toBe(true)
      })

      it('asks for the groups without them on What works, so the line counts everything', async () => {
        const w = await make({ view: 'values', q: 'photo' }, 'give')
        expect(fetchers.fetchProblemGroups).toHaveBeenCalledTimes(1)
        expect(fetchers.fetchProblemGroups).toHaveBeenLastCalledWith({})
        expect(w.find('.summary').text()).toBe(WHOLE)
        expect(w.find('.filters').exists()).toBe(false)
        expect(w.find('.funnel-notice').exists()).toBe(false)
      })

      it('asks for the groups without them on Questions, too', async () => {
        const w = await make({ view: 'questions' }, 'give')
        expect(fetchers.fetchProblemGroups).toHaveBeenLastCalledWith({})
        expect(w.find('.summary').text()).toBe(WHOLE)
        expect(w.find('.funnel-notice').exists()).toBe(false)
      })

      it('asks again as the person moves off Problems and back, and not between the two views that ignore them', async () => {
        const w = await make({ q: 'photo' }, 'give')
        const asked = () =>
          fetchers.fetchProblemGroups.mock.calls.map((c) => c[0])
        expect(asked()).toEqual([{ flow: 'give', q: 'photo' }])

        await w.setProps({
          view: viewOf({ view: 'values', q: 'photo' }, 'give'),
        })
        await flushPromises()
        expect(asked()).toEqual([{ flow: 'give', q: 'photo' }, {}])
        expect(w.find('.summary').text()).toBe(WHOLE)

        await w.setProps({
          view: viewOf({ view: 'questions', q: 'photo' }, 'give'),
        })
        await flushPromises()
        expect(asked()).toHaveLength(2)

        await w.setProps({ view: viewOf({ q: 'photo' }, 'give') })
        await flushPromises()
        expect(asked()).toEqual([
          { flow: 'give', q: 'photo' },
          {},
          { flow: 'give', q: 'photo' },
        ])
        expect(w.find('.funnel-notice').exists()).toBe(true)
        expect(w.find('.summary').text()).toContain('from 30 written answers')
      })

      it('keeps them in the address of the other views, for the way back to Problems', async () => {
        const w = await make({ view: 'values', q: 'photo' }, 'give')
        await links(w)[0].trigger('click')
        // The patch closes what was open and leaves the funnel and the search alone.
        expect(w.emitted('navigate')[0][0]).not.toHaveProperty('flow')
        expect(w.emitted('navigate')[0][0]).not.toHaveProperty('q')
      })
    })

    it('does not ask again for the groups when something else in the address changes', async () => {
      const w = await make()
      await w.setProps({ view: viewOf({ group: 'Other' }) })
      await w.setProps({ view: viewOf({ group: 'Other', problem: '4' }) })
      await flushPromises()
      expect(fetchers.fetchProblemGroups).toHaveBeenCalledTimes(1)
    })
  })

  describe('the funnel', () => {
    const select = (w) => w.find('select.flow-select')

    it('is a select of all funnels, the seven by name, and the problems tied to none', async () => {
      const w = await make()
      expect(
        select(w)
          .findAll('option')
          .map((o) => o.text())
      ).toEqual([
        'All funnels',
        'Offering something',
        'Asking for something',
        'Replying to a post',
        'Promising an item',
        'Marking taken or received',
        'Signing in or joining',
        'Settings',
        'Not tied to a funnel',
      ])
      expect(
        select(w)
          .findAll('option')
          .map((o) => o.element.value)
      ).toEqual([
        '',
        'give',
        'ask',
        'reply',
        'chat_promise',
        'chat_outcome',
        'login',
        'settings',
        'none',
      ])
      expect(w.find('label.filter-label').text()).toBe('Funnel:')
    })

    it('shows the funnel in the address, and all funnels when there is none or it is unknown', async () => {
      expect(select(await make({}, 'ask')).element.value).toBe('ask')
      expect(select(await make({}, '')).element.value).toBe('')
      expect(select(await make({}, 'something-else')).element.value).toBe('')
    })

    it('asks for the funnel chosen, closing what was open', async () => {
      const w = await make({ group: 'Other' })
      await select(w).setValue('reply')
      expect(w.emitted('navigate')).toStrictEqual([
        [{ flow: 'reply', group: undefined, problem: undefined }],
      ])
    })

    it('takes the funnel off when All funnels is chosen', async () => {
      const w = await make({}, 'reply')
      await select(w).setValue('')
      expect(w.emitted('navigate')[0]).toStrictEqual([
        { flow: undefined, group: undefined, problem: undefined },
      ])
    })

    it('writes Not tied to a funnel as none, and asks the routes for none', async () => {
      const w = await make({})
      await select(w).setValue('none')
      expect(w.emitted('navigate')[0]).toStrictEqual([
        { flow: 'none', group: undefined, problem: undefined },
      ])
      fetchers.fetchProblemGroups.mockClear()
      await make({}, 'none')
      expect(fetchers.fetchProblemGroups).toHaveBeenCalledWith({ flow: 'none' })
    })

    it('says Showing Offering only, with a way to show all', async () => {
      const w = await make({}, 'give')
      expect(w.find('.funnel-notice').text()).toBe(
        'Showing Offering only. Show all'
      )
      const link = w.find('.funnel-notice a')
      expect(link.text()).toBe('Show all')
      expect(JSON.parse(link.attributes('href').slice(1))).toEqual({})
    })

    it('says it for problems tied to no funnel, too', async () => {
      const w = await make({}, 'none')
      expect(w.find('.funnel-notice').text()).toBe(
        'Showing problems not tied to a funnel only. Show all'
      )
    })

    it('says nothing when there is no funnel, or one it does not know', async () => {
      expect((await make({}, '')).find('.funnel-notice').exists()).toBe(false)
      expect(
        (await make({}, 'something-else')).find('.funnel-notice').exists()
      ).toBe(false)
    })

    it('shows everything again when Show all is pressed', async () => {
      const w = await make({ group: 'Other' }, 'give')
      await w.find('.funnel-notice a').trigger('click')
      expect(w.emitted('navigate')).toStrictEqual([
        [{ flow: undefined, group: undefined, problem: undefined }],
      ])
    })

    it('draws only on the Problems view', async () => {
      expect(
        (await make({ view: 'values' }, 'give')).find('.filters').exists()
      ).toBe(false)
      expect(
        (await make({ view: 'questions' }, 'give'))
          .find('.funnel-notice')
          .exists()
      ).toBe(false)
      expect((await make({}, 'give')).find('.filters').exists()).toBe(true)
    })
  })

  describe('the search', () => {
    const box = (w) => w.find('input.search-box')

    it('is a box labelled Search, showing the text in the address', async () => {
      const w = await make({ q: 'photo' })
      expect(w.find('label[for]').exists()).toBe(true)
      expect(w.findAll('label.filter-label').map((l) => l.text())).toEqual([
        'Funnel:',
        'Search:',
      ])
      expect(box(w).element.value).toBe('photo')
      expect(box(w).attributes('maxlength')).toBe('60')
    })

    it('sends the text as typed, with its apostrophe, when Enter is pressed', async () => {
      const w = await make({ group: 'Other' })
      await box(w).setValue("can't")
      await box(w).trigger('keydown.enter')
      expect(w.emitted('navigate')).toStrictEqual([
        [{ q: "can't", group: undefined, problem: undefined }],
      ])
    })

    it('asks the routes for the text unchanged when the address holds it', async () => {
      await make({ q: "can't" })
      expect(fetchers.fetchProblemGroups).toHaveBeenCalledWith({ q: "can't" })
    })

    it('waits for typing to pause before it asks, and asks once', async () => {
      const w = await make()
      vi.useFakeTimers()
      await box(w).setValue('p')
      await box(w).setValue('ph')
      await box(w).setValue('pho')
      expect(w.emitted('navigate')).toBeUndefined()
      await vi.advanceTimersByTimeAsync(399)
      expect(w.emitted('navigate')).toBeUndefined()
      await vi.advanceTimersByTimeAsync(2)
      expect(w.emitted('navigate')).toStrictEqual([
        [{ q: 'pho', group: undefined, problem: undefined }],
      ])
    })

    it('asks when the box loses focus, trimmed, and not twice for the same text', async () => {
      const w = await make({ q: 'photo' })
      await box(w).setValue('  email  ')
      await box(w).trigger('blur')
      expect(w.emitted('navigate')).toStrictEqual([
        [{ q: 'email', group: undefined, problem: undefined }],
      ])
      await box(w).setValue('photo')
      await box(w).trigger('keydown.enter')
      expect(w.emitted('navigate')).toHaveLength(1)
    })

    it('takes the search off when the box is emptied', async () => {
      const w = await make({ q: 'photo' })
      await box(w).setValue('')
      await box(w).trigger('keydown.enter')
      expect(w.emitted('navigate')).toStrictEqual([
        [{ q: undefined, group: undefined, problem: undefined }],
      ])
    })

    it('keeps a space the person has typed when the address catches up with the text before it', async () => {
      const w = await make()
      await box(w).setValue('photo ')
      await box(w).trigger('keydown.enter')
      // The address holds the text trimmed, and the section is given it back.
      await w.setProps({ view: viewOf({ q: 'photo' }) })
      expect(box(w).element.value).toBe('photo ')
    })

    it('follows the address when it changes from outside', async () => {
      const w = await make({ q: 'photo' })
      await w.setProps({ view: viewOf({ q: 'email' }) })
      expect(box(w).element.value).toBe('email')
      await w.setProps({ view: viewOf({}) })
      expect(box(w).element.value).toBe('')
    })

    it('does not ask after the section is gone', async () => {
      const w = await make()
      vi.useFakeTimers()
      await box(w).setValue('pho')
      w.unmount()
      await vi.advanceTimersByTimeAsync(1000)
      expect(w.emitted('navigate')).toBeUndefined()
    })
  })

  describe('the open group and problem', () => {
    it('are handed to the list, which asks for the group', async () => {
      await make({ group: 'Photos and posting', problem: '4' }, 'give')
      expect(fetchers.fetchProblems).toHaveBeenCalledWith({
        group: 'Photos and posting',
        flow: 'give',
        limit: 30,
      })
    })

    // The search text is part of what the list asks for. Without it a search narrows the groups, and an
    // opened group lists every problem in it, so its heading and its list disagree.
    it('hand the search text and the funnel to the list, which asks for the group with them', async () => {
      await make({ group: 'Other', q: "can't" }, 'give')
      expect(fetchers.fetchProblems).toHaveBeenCalledWith({
        group: 'Other',
        flow: 'give',
        q: "can't",
        limit: 30,
      })
    })

    it('hand the open problem to the list, which draws its details', async () => {
      fetchers.fetchProblems.mockResolvedValue({
        total: 2,
        problems: [problemOf(4), problemOf(5)],
      })
      const w = await make({ group: 'Photos and posting', problem: '4' })
      expect(
        w.findAll('.problem-row').map((r) => r.attributes('aria-expanded'))
      ).toEqual(['true', 'false'])
      expect(w.find('.ux-problem-detail').text()).toContain('Description of 4.')
    })

    it('are passed up when the list asks to open or close one', async () => {
      const w = await make()
      await w.findAll('.group-row')[0].trigger('click')
      expect(w.emitted('navigate')).toStrictEqual([
        [{ group: 'Photos and posting', problem: undefined }],
      ])
    })

    it('are not carried into the other views', async () => {
      const w = await make({ view: 'values', group: 'Other' })
      expect(w.find('.group-body').exists()).toBe(false)
    })
  })

  describe('when a route is missing or fails', () => {
    it('says this part is not switched on, draws no fault, and hides the filters', async () => {
      fetchers.fetchProblemGroups.mockRejectedValue({
        response: { status: 404 },
      })
      const w = await make()
      expect(w.text()).toContain('This part is not switched on yet.')
      expect(w.find('.notice-message').exists()).toBe(false)
      expect(w.find('.filters').exists()).toBe(false)
      expect(w.find('.summary').exists()).toBe(false)
      // The views are still there, and the explanation is too.
      expect(links(w)).toHaveLength(3)
      expect(w.find('.caution').exists()).toBe(true)
    })

    it('says so on the other views when their route is missing', async () => {
      fetchers.fetchValues.mockRejectedValue({ response: { status: 501 } })
      fetchers.fetchQuestions.mockRejectedValue({ response: { status: 404 } })
      expect((await make({ view: 'values' })).text()).toContain(
        'This part is not switched on yet.'
      )
      setActivePinia(createPinia())
      expect((await make({ view: 'questions' })).text()).toContain(
        'This part is not switched on yet.'
      )
    })

    it('shows a fault as a notice in the view that failed, and keeps the rest', async () => {
      fetchers.fetchProblemGroups.mockRejectedValue(new Error('boom'))
      const w = await make()
      expect(w.find('.notice-message').text()).toBe('boom')
      expect(links(w)).toHaveLength(3)
    })
  })

  describe('the words', () => {
    it('say funnel, never flow, and draw no raw key', async () => {
      for (const extra of [
        {},
        { view: 'values' },
        { view: 'questions', question: '2' },
      ]) {
        setActivePinia(createPinia())
        const w = await make(extra, 'chat_promise')
        await w.find('.how-toggle').trigger('click')
        const t = w.text()
        expect(t).not.toMatch(/\bflow\b/i)
        expect(t).not.toContain('chat_promise')
        expect(t).not.toContain('problem:')
      }
    })

    it('never print a margin of error', async () => {
      const w = await make({ view: 'questions', question: '2' })
      await w.find('.how-toggle').trigger('click')
      // The explanation says none is shown; no figure carries one.
      expect(w.text()).not.toMatch(/±|plus or minus|confidence interval/i)
    })
  })

  // The tab's registry is F's, and the rest of this file fakes it. These read the real one, to check
  // the entry this section adds and that the tab's address helper carries the section's keys.
  describe('its entry in the registry', () => {
    const real = () => vi.importActual('~/modtools/composables/useUxSections')

    it('is one section, between Funnels and Pages, with no filters and no reads of the tab', async () => {
      const { SECTIONS, uxSections } = await real()
      const entry = SECTIONS.find((s) => s.key === 'members')
      expect(entry).toMatchObject({
        label: 'What members say',
        order: 40,
        filters: [],
        slots: [],
        params: ['view', 'group', 'problem', 'q', 'question'],
        funnelLink: { label: 'What members say' },
      })
      expect(entry.component).toBe(ModSysAdminUxSurveys)
      const keys = uxSections().map((s) => s.key)
      expect(keys.indexOf('members')).toBeGreaterThan(keys.indexOf('funnels'))
      expect(keys.indexOf('members')).toBeLessThan(keys.indexOf('pages'))
    })

    it('has its keys read from the address, and the funnel left as the shared key', async () => {
      const { SECTIONS } = await real()
      const { parseView } = await import('~/modtools/composables/useUxView')
      const v = parseView(
        {
          ux_section: 'members',
          ux_view: 'values',
          ux_group: 'Collection and no-shows',
          ux_problem: '4',
          ux_q: "can't",
          ux_question: '2',
          ux_flow: 'none',
        },
        SECTIONS
      )
      expect(v.section).toBe('members')
      expect(v.flow).toBe('none')
      expect(v.uses).toEqual([])
      expect(v.filterParams).toEqual({})
      expect(v.extra).toEqual({
        view: 'values',
        group: 'Collection and no-shows',
        problem: '4',
        q: "can't",
        question: '2',
      })
    })

    it('writes the search with its apostrophe, and clears its keys when the person leaves', async () => {
      const { SECTIONS } = await real()
      const { useUxView } = await import('~/modtools/composables/useUxView')
      const replace = vi.fn()
      const route = {
        query: {
          tab: 'ux',
          ux_section: 'members',
          ux_group: 'Other',
          ux_flow: 'none',
        },
      }
      const { set } = useUxView({
        route,
        router: { replace, resolve: () => ({ href: '#' }) },
        sections: SECTIONS,
      })
      set({ q: "can't", group: undefined })
      expect(replace).toHaveBeenLastCalledWith({
        query: {
          tab: 'ux',
          ux_section: 'members',
          ux_flow: 'none',
          ux_q: "can't",
        },
      })
      // Leaving for another section clears the keys this one owns and the funnel the nav carries.
      set({ section: 'pages' })
      expect(replace).toHaveBeenLastCalledWith({
        query: { tab: 'ux', ux_section: 'pages' },
      })
    })
  })
})
