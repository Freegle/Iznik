import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxProblem from '~/modtools/components/ModSysAdminUxProblem.vue'
import { uxStubs } from './uxStubs'

// The registry is F's. A fake one stands in for the pieces other parts of the tab add.
const hosted = vi.hoisted(() => ({ pieces: [] }))
vi.mock('~/modtools/composables/useUxSections', () => ({
  hostedFor: (slot) => (slot === 'problem' ? hosted.pieces : []),
}))

// The shape is the Go response in iznik-server-go/ux/surveys.go (Problem).
const problem = (over = {}) => ({
  id: 4,
  title: 'Photos fail to upload',
  description:
    'On a slow phone the photo step fails and the post starts again.',
  flow: 'give',
  group: 'Photos and posting',
  mentions: 14,
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

const make = (p = problem(), provide = {}) =>
  mount(ModSysAdminUxProblem, {
    props: { problem: p },
    global: {
      stubs: uxStubs,
      provide: {
        uxHrefFor: (patch) => `/sysadmin?x=${JSON.stringify(patch)}`,
        ...provide,
      },
    },
  })

describe('ModSysAdminUxProblem', () => {
  beforeEach(() => {
    hosted.pieces = []
  })

  describe('the sentences', () => {
    it('gives the description', () => {
      expect(make().find('.description').text()).toBe(
        'On a slow phone the photo step fails and the post starts again.'
      )
    })

    it('leaves the description out when there is none', () => {
      expect(
        make(problem({ description: '' }))
          .find('.description')
          .exists()
      ).toBe(false)
    })

    it('says how many of the written answers named it, with the percentage from 100 answers', () => {
      expect(make().find('.mentioned').text()).toBe(
        'Mentioned in 14 of the 120 written answers (12%).'
      )
    })

    it('gives a count and no percentage below 100 answers', () => {
      const w = make(problem({ mentions: 14, answers: 45 }))
      expect(w.find('.mentioned').text()).toBe(
        'Mentioned in 14 of the 45 written answers.'
      )
      expect(w.text()).not.toContain('%')
    })

    it('has no "of" when the problem did not come from a question', () => {
      expect(
        make(problem({ answers: 0 }))
          .find('.mentioned')
          .text()
      ).toBe('Mentioned by 14 members.')
    })

    it('says how other members ranked it once they were asked', () => {
      const w = make(
        problem({ times_shown: 40, most: 12, asked: 80, weight: 0.3 })
      )
      expect(w.find('.ranked').text()).toBe(
        'Other members were shown it 40 times and 12 picked it as the biggest problem.'
      )
      expect(w.find('.not-ranked').exists()).toBe(false)
    })

    it('adds the percentage of the times it was shown from 100 members asked', () => {
      const w = make(
        problem({ times_shown: 40, most: 12, asked: 150, weight: 0.3 })
      )
      expect(w.find('.ranked').text()).toContain(
        'picked it as the biggest problem (30%).'
      )
    })

    it('says other members have not been asked yet, rather than leaving a gap', () => {
      const w = make()
      expect(w.find('.ranked').exists()).toBe(false)
      expect(w.find('.not-ranked').text()).toBe(
        'Other members have not been asked to rank it yet.'
      )
    })

    it('never prints a margin of error', () => {
      const w = make(problem({ times_shown: 40, most: 12, asked: 150 }))
      expect(w.text()).not.toMatch(/margin|confidence|±|plus or minus/i)
    })
  })

  describe('suggestions', () => {
    const withSuggestions = problem({
      suggestions: [
        { id: 21, title: 'Let me add photos later', mentions: 5 },
        { id: 22, title: 'Resize the photo for me', mentions: 2 },
      ],
    })

    it('lists the fixes members suggested under their own heading, each with its count', () => {
      const w = make(withSuggestions)
      expect(w.find('.suggestions p').text()).toBe(
        "Members' suggestions for it:"
      )
      expect(w.findAll('.suggestion').map((s) => s.text())).toEqual([
        'Let me add photos later (5)',
        'Resize the photo for me (2)',
      ])
    })

    it('draws no heading when there are none', () => {
      const w = make()
      expect(w.find('.suggestions').exists()).toBe(false)
      expect(w.text()).not.toContain("Members' suggestions")
    })

    it('escapes a suggestion', () => {
      const w = make(
        problem({ suggestions: [{ id: 1, title: '<b>bold</b>', mentions: 1 }] })
      )
      expect(w.find('.suggestion b').exists()).toBe(false)
      expect(w.find('.suggestion').text()).toBe('<b>bold</b> (1)')
    })
  })

  describe('quotes', () => {
    const quoted = problem({
      quotes: [
        { answer_id: 4, span: 'photos take ages to upload' },
        { answer_id: 9, span: 'the upload hangs on my phone' },
      ],
    })

    it('keeps quotes behind a toggle, with the count', async () => {
      const w = make(quoted)
      expect(w.text()).not.toContain('photos take ages to upload')
      const toggle = w.find('.said .said-toggle')
      expect(toggle.text()).toContain('What members wrote (2)')
      await toggle.trigger('click')
      expect(w.text()).toContain('photos take ages to upload')
      expect(w.text()).toContain('the upload hangs on my phone')
    })

    it('escapes a quote', async () => {
      const w = make(
        problem({
          quotes: [{ answer_id: 1, span: '<img src=x onerror=alert(1)>' }],
        })
      )
      await w.find('.said .said-toggle').trigger('click')
      expect(w.find('.ux-problem-detail img').exists()).toBe(false)
      expect(w.text()).toContain('<img src=x onerror=alert(1)>')
    })

    it('draws no toggle when there are no quotes, and skips one with no words', () => {
      expect(make().find('.said').exists()).toBe(false)
      const blank = problem({ quotes: [{ answer_id: 1, span: '  ' }] })
      expect(make(blank).find('.said').exists()).toBe(false)
    })

    it('accepts a quote that is a bare string', async () => {
      const w = make(problem({ quotes: ['it froze'] }))
      await w.find('.said .said-toggle').trigger('click')
      expect(w.text()).toContain('it froze')
    })
  })

  describe('the funnel it is about', () => {
    it('names the funnel as a link to it, with the address a click would give', () => {
      const a = make().find('.about-link')
      expect(a.text()).toBe('Offering something')
      expect(a.attributes('href')).toBe(
        `/sysadmin?x=${JSON.stringify({ section: 'funnels', flow: 'give' })}`
      )
      expect(make().find('.about').text()).toBe('About: Offering something')
    })

    it('opens the funnel on a plain click, without leaving the page', async () => {
      const w = make()
      const click = new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        button: 0,
      })
      w.find('.about-link').element.dispatchEvent(click)
      expect(click.defaultPrevented).toBe(true)
      expect(w.emitted('navigate')).toEqual([
        [{ section: 'funnels', flow: 'give' }],
      ])
    })

    it('leaves a click with a modifier to the browser', () => {
      const w = make()
      const click = new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        button: 0,
        ctrlKey: true,
      })
      w.find('.about-link').element.dispatchEvent(click)
      expect(click.defaultPrevented).toBe(false)
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('says so when it is not tied to one funnel, and has no link', () => {
      const w = make(problem({ flow: null }))
      expect(w.find('.about').text()).toBe('About: Not tied to one funnel')
      expect(w.find('.about-link').exists()).toBe(false)
    })

    it('says the funnel in words, never the key, and never "flow"', () => {
      const t = make().text()
      expect(t).not.toMatch(/\bflow\b/i)
      expect(t).not.toContain('give')
    })
  })

  describe('pieces other parts of the tab add', () => {
    it('draws each one, given the problem', () => {
      hosted.pieces = [
        {
          name: 'PieceOne',
          props: ['problem'],
          template: '<div class="piece one">{{ problem.title }}</div>',
        },
        {
          name: 'PieceTwo',
          props: ['problem'],
          template: '<div class="piece two">{{ problem.id }}</div>',
        },
      ]
      const w = make()
      expect(w.find('.piece.one').text()).toBe('Photos fail to upload')
      expect(w.find('.piece.two').text()).toBe('4')
    })

    it('draws nothing extra when no part has added one', () => {
      expect(make().find('.piece').exists()).toBe(false)
    })

    it('asks only for the pieces for a problem', () => {
      hosted.pieces = [
        { name: 'P', props: ['problem'], template: '<div class="piece" />' },
      ]
      expect(make().findAll('.piece')).toHaveLength(1)
    })
  })
})
