import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxIdeas from '~/modtools/components/ModSysAdminUxIdeas.vue'
import ModSysAdminUxIdeaRow from '~/modtools/components/ModSysAdminUxIdeaRow.vue'
import { useUxIdeasStore } from '~/modtools/stores/uxIdeas'
import { uxStubs } from './uxStubs'

// The registry is F's. The rows ask it which sections there are, to decide which links to draw.
vi.mock('~/modtools/composables/useUxSections', () => ({
  uxSections: () => [{ key: 'overview' }, { key: 'funnels' }],
}))

const FUNNELS = [
  'give',
  'ask',
  'reply',
  'chat_promise',
  'chat_outcome',
  'login',
  'settings',
]

const idea = (id, flow, title, score, over = {}) => ({
  id,
  flow,
  title,
  rationale: `Why ${id}.`,
  expected: `Expect ${id}.`,
  evidence: { category: 'copy', expected_points: 3 },
  score,
  status: 'open',
  type: 'experiment',
  source: 'ai',
  problem_id: null,
  problem_title: null,
  problem: null,
  attempts: 0,
  experiment: null,
  created_at: '2026-10-14 09:00:00',
  ...over,
})

const COUNTS = { open: 12, scheduled: 1, running: 1, done: 4, rejected: 2 }

const FLOWS = FUNNELS.map((flow) => ({ flow, open: 0, best: null })).map((f) =>
  f.flow === 'give'
    ? {
        flow: 'give',
        open: 5,
        best: { id: 3, title: 'Ask to sign in first', score: 8.2 },
      }
    : f.flow === 'login'
      ? {
          flow: 'login',
          open: 2,
          best: { id: 9, title: 'Remember the email', score: 3 },
        }
      : f
)

// matching is what the route says matched the filters before it cut the list: here as many as it sent.
const answer = (ideas = [], over = {}) => ({
  counts: COUNTS,
  flows: FLOWS,
  matching: ideas.length,
  ideas,
  ...over,
})

const GIVE = [
  idea(3, 'give', 'Ask to sign in first', 8.2),
  idea(4, 'give', 'Show a progress bar', 6, { type: 'fix' }),
  idea(5, 'give', 'Shorter first screen', 2.5),
]

// What useUxView.parseView gives for this section.
const viewOf = ({ flow = '', idea: open, istatus, itype } = {}) => ({
  section: 'ideas',
  flow,
  extra: {
    ...(open ? { idea: open } : {}),
    ...(istatus ? { istatus } : {}),
    ...(itype ? { itype } : {}),
  },
})

// The key the section gives a request, which the store keeps as what an answer was asked for.
const keyOf = (params) => JSON.stringify(params)
const WAITING = { status: 'open', limit: 1 }

describe('ModSysAdminUxIdeas', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxIdeasStore()
    store.load = vi.fn().mockResolvedValue(undefined)
    store.ideas.data = answer()
    store.asked = keyOf(WAITING)
  })

  const make = (view = viewOf(), provide = {}) =>
    mount(ModSysAdminUxIdeas, {
      props: { view },
      global: {
        stubs: uxStubs,
        provide: {
          uxHrefFor: (patch) => {
            const query = new URLSearchParams(
              Object.fromEntries(
                Object.entries(patch)
                  .filter(([, v]) => v !== undefined)
                  .map(([k, v]) => [`ux_${k}`, v])
              )
            ).toString()
            return `/sysadmin${query ? '?' + query : ''}`
          },
          ...provide,
        },
      },
    })

  describe('asking for the ideas', () => {
    it('asks for the totals alone when waiting ideas are chosen with no funnel', () => {
      make()
      expect(store.load).toHaveBeenCalledTimes(1)
      expect(store.load).toHaveBeenCalledWith(WAITING)
    })

    it('asks for one funnel’s ideas, cut by the status and the type from the address', () => {
      make(viewOf({ flow: 'give', istatus: 'done', itype: 'fix' }))
      expect(store.load).toHaveBeenCalledWith({
        status: 'done',
        flow: 'give',
        type: 'fix',
      })
    })

    it('asks for every funnel’s ideas of another status', () => {
      make(viewOf({ istatus: 'rejected' }))
      expect(store.load).toHaveBeenCalledWith({ status: 'rejected' })
    })

    it('asks again when the address changes the choice, and not when it changes nothing', async () => {
      const w = make()
      await w.setProps({ view: viewOf({ flow: 'give' }) })
      expect(store.load).toHaveBeenLastCalledWith({
        status: 'open',
        flow: 'give',
      })
      await w.setProps({ view: viewOf({ flow: 'give', idea: '3' }) })
      expect(store.load).toHaveBeenCalledTimes(2)
      await w.setProps({ view: viewOf({ flow: 'give', itype: 'fix' }) })
      expect(store.load).toHaveBeenLastCalledWith({
        status: 'open',
        flow: 'give',
        type: 'fix',
      })
    })

    it('ignores a type when it is only drawing the funnel lines', () => {
      make(viewOf({ itype: 'fix' }))
      expect(store.load).toHaveBeenCalledWith(WAITING)
    })

    it('ignores an ux_flow that is no funnel, as another section may leave one', () => {
      make(viewOf({ flow: 'none' }))
      expect(store.load).toHaveBeenCalledWith(WAITING)
    })

    it('ignores a status or a type it does not know', () => {
      make({
        section: 'ideas',
        flow: 'give',
        extra: { istatus: 'paused', itype: 'rewrite' },
      })
      expect(store.load).toHaveBeenCalledWith({ status: 'open', flow: 'give' })
    })
  })

  describe('while it waits, and when it cannot', () => {
    it('says it is loading before the first answer', () => {
      store.ideas.data = null
      const w = make()
      expect(w.find('.loading').text()).toContain('Loading')
      expect(w.find('.chips').exists()).toBe(false)
    })

    it('says that this part is not switched on when the route is missing', () => {
      store.ideas.data = null
      store.ideas.missing = true
      const w = make()
      expect(w.find('.not-switched-on').text()).toBe(
        'This part is not switched on yet.'
      )
      expect(w.find('.chips').exists()).toBe(false)
    })

    it('shows an error as a notice', () => {
      store.ideas.data = null
      store.ideas.error = 'boom'
      const w = make()
      expect(w.find('.notice-message').text()).toBe('boom')
      expect(w.find('.chips').exists()).toBe(false)
    })

    it('says there are no ideas yet when there are none at all', () => {
      store.ideas.data = answer([], {
        counts: { open: 0, scheduled: 0, running: 0, done: 0, rejected: 0 },
      })
      const w = make()
      expect(w.find('.none').text()).toBe(
        'No ideas yet. The idea generator adds them once a day.'
      )
      expect(w.find('.chips').exists()).toBe(false)
      expect(w.find('.funnels').exists()).toBe(false)
    })

    it('holds the strip but not the list while an answer to a changed choice is on its way', () => {
      // The slot still holds the answer to waiting ideas; the address now asks for a funnel.
      const w = make(viewOf({ flow: 'give' }))
      expect(w.find('.chips').exists()).toBe(true)
      expect(w.find('.loading').exists()).toBe(true)
      expect(w.find('.ux-idea').exists()).toBe(false)
      expect(w.find('.funnels').exists()).toBe(false)
    })
  })

  describe('the strip of counts', () => {
    it('has a chip for each status with its count, waiting chosen by default', () => {
      const chips = make().findAll('.chip')
      // The same form as the Experiments section's chips: the word, then the number in brackets.
      expect(chips.map((c) => c.text())).toEqual([
        'Waiting (12)',
        'Queued (1)',
        'Being tried (1)',
        'Tried (4)',
        'Set aside (2)',
      ])
      expect(chips.map((c) => c.attributes('aria-pressed'))).toEqual([
        'true',
        'false',
        'false',
        'false',
        'false',
      ])
      expect(chips[0].classes()).toContain('active')
    })

    it('marks the chip the address names', () => {
      store.asked = keyOf({ status: 'done' })
      const chips = make(viewOf({ istatus: 'done' })).findAll('.chip')
      expect(chips.map((c) => c.attributes('aria-pressed'))).toEqual([
        'false',
        'false',
        'false',
        'true',
        'false',
      ])
    })

    it('goes to the status that is pressed, and closes the open idea', async () => {
      const w = make()
      await w.find('.chip-done').trigger('click')
      await w.find('.chip-scheduled').trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ istatus: 'done', idea: undefined }],
        [{ istatus: 'scheduled', idea: undefined }],
      ])
    })

    it('takes the status out of the address for the default, waiting', async () => {
      store.asked = keyOf({ status: 'done' })
      const w = make(viewOf({ istatus: 'done' }))
      await w.find('.chip-open').trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ istatus: undefined, idea: undefined }],
      ])
    })

    it('counts what the route sent, whatever the filters', () => {
      store.ideas.data = answer(GIVE, {
        counts: { open: 3, scheduled: 0, running: 0, done: 0, rejected: 0 },
      })
      store.asked = keyOf({ status: 'open', flow: 'give' })
      const w = make(viewOf({ flow: 'give' }))
      expect(w.findAll('.chip').map((c) => c.text())[0]).toBe('Waiting (3)')
      expect(w.findAll('.chip').map((c) => c.text())[3]).toBe('Tried (0)')
    })

    it('says once what the score is, under the strip, whichever view is on', () => {
      const note =
        'Score is the expected gain, times how sure, times how small the change is. The highest score is listed first.'
      // The line for each funnel, a funnel’s list and another status’s list.
      expect(make().find('.score-note').text()).toBe(note)
      store.ideas.data = answer(GIVE)
      store.asked = keyOf({ status: 'open', flow: 'give' })
      expect(
        make(viewOf({ flow: 'give' }))
          .find('.score-note')
          .text()
      ).toBe(note)
      store.asked = keyOf({ status: 'done' })
      expect(
        make(viewOf({ istatus: 'done' }))
          .find('.score-note')
          .text()
      ).toBe(note)
    })

    it('does not say it before there is a strip to say it under', () => {
      store.ideas.data = null
      expect(make().find('.score-note').exists()).toBe(false)
      store.ideas.missing = true
      expect(make().find('.score-note').exists()).toBe(false)
    })
  })

  describe('with waiting ideas and no funnel chosen', () => {
    it('has a line for each funnel, in the order members meet them', () => {
      const lines = make().findAll('.funnel-link')
      expect(lines).toHaveLength(7)
      expect(lines.map((l) => l.text())).toEqual([
        'Offering something. 5 waiting. Best: Ask to sign in first (score 8.2)',
        'Asking for something. No ideas waiting.',
        'Replying to a post. No ideas waiting.',
        'Promising an item. No ideas waiting.',
        'Marking taken or received. No ideas waiting.',
        'Signing in or joining. 2 waiting. Best: Remember the email (score 3)',
        'Settings. No ideas waiting.',
      ])
    })

    it('draws no list of ideas, and no selects: the lines are the way to a funnel', () => {
      const w = make()
      expect(w.find('.ux-idea').exists()).toBe(false)
      expect(w.find('.type-select').exists()).toBe(false)
      expect(w.find('.flow-select').exists()).toBe(false)
    })

    it('has a funnel with no row in the answer as one with nothing waiting', () => {
      store.ideas.data = answer([], { flows: [] })
      const lines = make().findAll('.funnel-link')
      expect(lines).toHaveLength(7)
      expect(lines.every((l) => l.text().endsWith('No ideas waiting.'))).toBe(
        true
      )
    })

    it('links each line to the funnel, so the address can be copied', () => {
      const lines = make().findAll('.funnel-link')
      expect(lines[0].attributes('href')).toBe('/sysadmin?ux_flow=give')
      expect(lines[5].attributes('href')).toBe('/sysadmin?ux_flow=login')
    })

    it('goes to a funnel on a plain click, and leaves a click with a modifier to the browser', async () => {
      const w = make()
      await w.findAll('.funnel-link')[0].trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ flow: 'give', idea: undefined }],
      ])
      await w.findAll('.funnel-link')[1].trigger('click', { ctrlKey: true })
      await w.findAll('.funnel-link')[1].trigger('click', { button: 1 })
      expect(w.emitted('navigate')).toHaveLength(1)
    })
  })

  describe('with a funnel chosen', () => {
    const ask = () => {
      store.ideas.data = answer(GIVE)
      store.asked = keyOf({ status: 'open', flow: 'give' })
      return make(viewOf({ flow: 'give' }))
    }

    it('lists its ideas in the order the route sent them, best score first', () => {
      const w = ask()
      expect(w.findAll('.ux-idea .title').map((t) => t.text())).toEqual([
        'Ask to sign in first',
        'Show a progress bar',
        'Shorter first screen',
      ])
      expect(w.findAll('.ux-idea .score').map((s) => s.text())).toEqual([
        'Score 8.2',
        'Score 6',
        'Score 2.5',
      ])
    })

    it('says which funnel it shows and offers all of them, in the words the other sections use', async () => {
      const w = ask()
      expect(w.find('.funnel-notice').text()).toBe(
        'Showing Offering only. Show all'
      )
      const link = w.find('.show-all')
      expect(link.text()).toBe('Show all')
      expect(link.attributes('href')).toBe('/sysadmin')
      await link.trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ flow: undefined, idea: undefined }],
      ])
    })

    it('leaves the funnel on a click with a modifier', async () => {
      const w = ask()
      await w.find('.show-all').trigger('click', { ctrlKey: true })
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('has a funnel select with all the funnels, showing the one chosen', () => {
      const select = ask().find('.flow-select')
      expect(select.findAll('option').map((o) => o.text())).toEqual([
        'All funnels',
        'Offering something',
        'Asking for something',
        'Replying to a post',
        'Promising an item',
        'Marking taken or received',
        'Signing in or joining',
        'Settings',
      ])
      expect(select.element.value).toBe('give')
      expect(ask().find('label[for]').text()).toBe('Funnel:')
    })

    it('goes to the funnel that is chosen in the select, or to all of them', async () => {
      const w = ask()
      const select = w.find('.flow-select')
      await select.setValue('login')
      await select.setValue('')
      expect(w.emitted('navigate')).toEqual([
        [{ flow: 'login', idea: undefined }],
        [{ flow: undefined, idea: undefined }],
      ])
    })

    it('says nothing about a cut when the list holds everything that matched', () => {
      expect(ask().find('.cut-notice').exists()).toBe(false)
    })

    it('does not name the funnel on each row, which are all about one', () => {
      expect(ask().find('.ux-idea .funnel').exists()).toBe(false)
    })

    it('has a type select that goes to fix, experiment or all', async () => {
      const w = ask()
      const select = w.find('.type-select')
      expect(select.findAll('option').map((o) => o.text())).toEqual([
        'All',
        'Fix',
        'Experiment',
      ])
      expect(select.element.value).toBe('')
      await select.setValue('fix')
      await select.setValue('')
      expect(w.emitted('navigate')).toEqual([
        [{ itype: 'fix', idea: undefined }],
        [{ itype: undefined, idea: undefined }],
      ])
    })

    it('shows the type from the address in the select', () => {
      store.ideas.data = answer([GIVE[1]])
      store.asked = keyOf({ status: 'open', flow: 'give', type: 'fix' })
      const w = make(viewOf({ flow: 'give', itype: 'fix' }))
      expect(w.find('.type-select').element.value).toBe('fix')
      expect(w.findAll('.ux-idea')).toHaveLength(1)
    })

    it('says there are none here when the funnel has none of that kind', () => {
      store.ideas.data = answer([])
      store.asked = keyOf({ status: 'open', flow: 'ask' })
      const w = make(viewOf({ flow: 'ask' }))
      expect(w.find('.none').text()).toBe('No ideas here.')
      expect(w.find('.ux-idea').exists()).toBe(false)
    })
  })

  describe('with another status and no funnel', () => {
    it('lists the ideas of every funnel straight away, naming the funnel on each', () => {
      const tried = [
        idea(7, 'login', 'Remember the email', 3, { status: 'done' }),
        idea(8, 'give', 'Bigger photos', 2, { status: 'done' }),
      ]
      store.ideas.data = answer(tried)
      store.asked = keyOf({ status: 'done' })
      const w = make(viewOf({ istatus: 'done' }))
      expect(w.find('.funnels').exists()).toBe(false)
      expect(w.findAll('.ux-idea .funnel').map((f) => f.text())).toEqual([
        'Signing in',
        'Offering',
      ])
      expect(w.find('.funnel-notice').exists()).toBe(false)
      expect(w.find('.type-select').exists()).toBe(true)
    })

    it('has a funnel select, so one funnel’s tried ideas are one choice away', async () => {
      store.ideas.data = answer([
        idea(7, 'login', 'Remember the email', 3, { status: 'done' }),
      ])
      store.asked = keyOf({ status: 'done' })
      const w = make(viewOf({ istatus: 'done' }))
      const select = w.find('.flow-select')
      expect(select.exists()).toBe(true)
      expect(select.element.value).toBe('')
      await select.setValue('give')
      expect(w.emitted('navigate')).toEqual([
        [{ flow: 'give', idea: undefined }],
      ])
    })

    it('keeps the status when a funnel is chosen from the select', () => {
      store.ideas.data = answer([GIVE[0]])
      store.asked = keyOf({ status: 'done', flow: 'give' })
      const w = make(viewOf({ istatus: 'done', flow: 'give' }))
      expect(w.find('.flow-select').element.value).toBe('give')
      expect(store.load).toHaveBeenCalledWith({ status: 'done', flow: 'give' })
    })

    describe('when the route cut the list', () => {
      const cutAnswer = (n, matching) =>
        answer(
          Array.from({ length: n }, (_, i) =>
            idea(100 + i, 'give', `Tried ${i}`, 5 - i / 100, { status: 'done' })
          ),
          { matching }
        )

      it('says how many it shows of how many there are, and how to see fewer', () => {
        store.ideas.data = cutAnswer(3, 340)
        store.asked = keyOf({ status: 'done' })
        const w = make(viewOf({ istatus: 'done' }))
        expect(w.find('.cut-notice').text()).toBe(
          'Showing the first 3 of 340. Choose a funnel or a type to see fewer.'
        )
        expect(w.findAll('.ux-idea')).toHaveLength(3)
      })

      it('offers only the choice that is left once the funnel and the type are both chosen', () => {
        store.ideas.data = cutAnswer(3, 340)
        store.asked = keyOf({ status: 'done', flow: 'give', type: 'fix' })
        const w = make(viewOf({ istatus: 'done', flow: 'give', itype: 'fix' }))
        expect(w.find('.cut-notice').text()).toBe('Showing the first 3 of 340.')
      })

      it('says nothing when the list is not shorter than what matched', () => {
        for (const matching of [3, 2, undefined, null, 'x']) {
          store.ideas.data = cutAnswer(3, matching)
          store.asked = keyOf({ status: 'done' })
          const w = make(viewOf({ istatus: 'done' }))
          expect(w.find('.cut-notice').exists(), String(matching)).toBe(false)
        }
      })

      it('does not say it for the lines of the funnels, which list nothing', () => {
        store.ideas.data = answer([], { matching: 12 })
        const w = make()
        expect(w.find('.cut-notice').exists()).toBe(false)
        expect(w.find('.funnels').exists()).toBe(true)
      })
    })
  })

  describe('one idea open at a time', () => {
    const list = (open) => {
      store.ideas.data = answer(GIVE)
      store.asked = keyOf({ status: 'open', flow: 'give' })
      return make(viewOf({ flow: 'give', idea: open }))
    }

    it('opens the idea the address names, and no other', () => {
      const w = list('4')
      expect(w.findAll('.ux-idea-detail')).toHaveLength(1)
      expect(
        w
          .findAll('button.ux-idea-row')
          .map((b) => b.attributes('aria-expanded'))
      ).toEqual(['false', 'true', 'false'])
      expect(w.find('.ux-idea-detail').text()).toContain('Why 4.')
      expect(w.find('.ux-idea-detail').attributes('id')).toBe('ux-idea-4')
    })

    it('opens a row when it is pressed, and closes the open one', async () => {
      const closed = list(undefined)
      await closed.findAll('button.ux-idea-row')[1].trigger('click')
      expect(closed.emitted('navigate')).toEqual([[{ idea: '4' }]])

      const open = list('4')
      await open.findAll('button.ux-idea-row')[1].trigger('click')
      expect(open.emitted('navigate')).toEqual([[{ idea: undefined }]])
    })

    it('says so when the idea in the address is not in the list', () => {
      const w = list('999')
      expect(w.find('.not-in-list').text()).toBe(
        'That idea is not in this list.'
      )
      expect(w.find('.ux-idea-detail').exists()).toBe(false)
    })

    it('says nothing about the address when no idea is open', () => {
      expect(list(undefined).find('.not-in-list').exists()).toBe(false)
    })

    it('ignores an idea in the address that is not a number', () => {
      const w = list('abc')
      expect(w.find('.ux-idea-detail').exists()).toBe(false)
      expect(w.find('.not-in-list').exists()).toBe(false)
    })

    it('passes on a row’s move to another section', () => {
      // The registry has no Experiments section here, so there is no link to press: the row’s own
      // spec covers the link. This checks that what a row emits is passed up.
      const w = list('4')
      w.findAllComponents(ModSysAdminUxIdeaRow)[1].vm.$emit('navigate', {
        section: 'experiments',
        exp: '4',
      })
      expect(w.emitted('navigate')).toEqual([
        [{ section: 'experiments', exp: '4' }],
      ])
    })
  })

  it('never offers a way to change an idea: that is the ux:idea command', () => {
    store.ideas.data = answer(GIVE)
    store.asked = keyOf({ status: 'open', flow: 'give' })
    const w = make(viewOf({ flow: 'give', idea: '3' }))
    expect(w.find('.ux-idea-detail').exists()).toBe(true)
    // No control but the strip’s chips, the type select, the link back and a button for each row.
    expect(w.findAll('button')).toHaveLength(5 + 3)
    const text = w.text().toLowerCase()
    for (const word of ['schedule', 'reject', 'reopen', 'delete']) {
      expect(text, word).not.toContain(word)
    }
  })
})

// The mock above stands in for the registry in every test of the section. These two use the real one:
// they check the section is entered in it, and that the address keys it reads are the ones the tab hands it.
describe('the Ideas section in the tab’s registry', () => {
  it('is registered between Funnels and Pages, with no filters and no reads of its own', async () => {
    const real = await vi.importActual('~/modtools/composables/useUxSections')
    const entry = real.uxSections().find((s) => s.key === 'ideas')
    expect(entry).toMatchObject({
      label: 'Ideas',
      order: 50,
      filters: [],
      slots: [],
      params: ['idea', 'istatus', 'itype'],
      funnelLink: { label: 'Ideas' },
    })
    expect(entry.component.__name).toBe('ModSysAdminUxIdeas')
    const keys = real.uxSections().map((s) => s.key)
    expect(keys.indexOf('ideas')).toBeGreaterThan(keys.indexOf('funnels'))
    expect(keys.indexOf('ideas')).toBeLessThan(keys.indexOf('pages'))
  })

  it('is hosted in a member problem’s detail, which draws the ideas for that problem', async () => {
    const real = await vi.importActual('~/modtools/composables/useUxSections')
    expect(real.hostedFor('problem').map((c) => c.__name)).toContain(
      'ModSysAdminUxProblemIdeas'
    )
  })

  it('reads the keys the tab hands a section from the address', async () => {
    const real = await vi.importActual('~/modtools/composables/useUxSections')
    const { parseView } = await vi.importActual(
      '~/modtools/composables/useUxView'
    )
    const view = parseView(
      {
        ux_section: 'ideas',
        ux_flow: 'give',
        ux_idea: '3',
        ux_istatus: 'done',
        ux_itype: 'fix',
        ux_exp: '9',
      },
      real.uxSections()
    )
    expect(view.section).toBe('ideas')
    expect(view.flow).toBe('give')
    // Only the keys the section lists are its own: another section’s are dropped.
    expect(view.extra).toEqual({ idea: '3', istatus: 'done', itype: 'fix' })
    expect(view.uses).toEqual([])
  })
})
