import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxValues from '~/modtools/components/ModSysAdminUxValues.vue'
import { useUxSurveysStore } from '~/modtools/stores/uxSurveys'
import { uxStubs } from './uxStubs'

// The shape is the Go response in iznik-server-go/ux/surveys.go (Value).
const value = (over) => ({
  id: 5,
  title: 'It is free',
  mentions: 30,
  answers: 100,
  quotes: [],
  ...over,
})

describe('ModSysAdminUxValues', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxSurveysStore()
  })

  const make = () => mount(ModSysAdminUxValues, { global: { stubs: uxStubs } })

  const give = (values) => {
    store.values.data = { values }
  }

  it('shows each value against the answers to the question it came from', () => {
    // A question with 120 answers and one with 100: neither borrows the other's count, and the sum
    // (220) never appears.
    give([
      value({ id: 1, title: 'It is free', mentions: 30, answers: 100 }),
      value({ id: 2, title: 'Local people', mentions: 24, answers: 120 }),
    ])
    const w = make()
    const items = w.findAll('.value')
    expect(items).toHaveLength(2)
    expect(items[0].text()).toContain('It is free: 30 of 100 (30%)')
    expect(items[1].text()).toContain('Local people: 24 of 120 (20%)')
    expect(w.text()).not.toContain('220')
  })

  it('gives counts without percentages below 100 answers', () => {
    give([value({ mentions: 14, answers: 45 })])
    const t = make().text()
    expect(t).toContain('It is free: 14 of 45')
    expect(t).not.toContain('%')
  })

  it('has no "of" for a value that did not come from a question', () => {
    give([
      value({ mentions: 14, answers: 0 }),
      value({ id: 6, mentions: 1, answers: 0 }),
    ])
    const t = make().text()
    expect(t).toContain('It is free: 14 members')
    expect(t).toContain(': 1 member')
  })

  it('keeps the order the server sent, the most mentioned first', () => {
    give([
      value({ id: 1, title: 'First', mentions: 9 }),
      value({ id: 2, title: 'Second', mentions: 8 }),
    ])
    expect(
      make()
        .findAll('.value .title')
        .map((t) => t.text())
    ).toEqual(['First', 'Second'])
  })

  it('says what every count is out of', () => {
    give([value()])
    expect(make().find('.lead-in').text()).toBe(
      'Each count is out of the written answers to the question it came from.'
    )
  })

  it('keeps quotes behind a toggle, with the count', async () => {
    give([
      value({
        quotes: [
          { answer_id: 3, span: 'free is the whole point' },
          { answer_id: 8, span: 'nobody asks for money' },
        ],
      }),
    ])
    const w = make()
    expect(w.text()).not.toContain('free is the whole point')
    const toggle = w.find('.said .said-toggle')
    expect(toggle.text()).toContain('What members wrote (2)')
    await toggle.trigger('click')
    expect(w.text()).toContain('free is the whole point')
    expect(w.text()).toContain('nobody asks for money')
  })

  it('escapes a quote', async () => {
    give([
      value({
        quotes: [{ answer_id: 1, span: '<img src=x onerror=alert(1)>' }],
      }),
    ])
    const w = make()
    await w.find('.said .said-toggle').trigger('click')
    expect(w.find('.value img').exists()).toBe(false)
    expect(w.text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('draws no toggle for a value with no quotes, and skips a quote with no words', () => {
    give([
      value({ id: 1, quotes: [] }),
      value({ id: 2, quotes: [{ answer_id: 4, span: '   ' }] }),
    ])
    expect(make().find('.said').exists()).toBe(false)
  })

  it('shows the empty message when nothing is valued yet, and says who must act before it fills', () => {
    give([])
    const w = make()
    expect(w.find('.none').text().replace(/\s+/g, ' ')).toBe(
      'No answers about what works yet. They appear after members answer the question about what has worked well and the answers have been analysed.'
    )
    expect(w.find('.value').exists()).toBe(false)
  })

  // A figure with no base is a count of members and not a bare number (finding: a row with no unit).
  it('reads a value with no base as a count of members, never as a bare number', () => {
    give([value({ mentions: 6, answers: 0 })])
    expect(make().find('.count').text()).toBe(': 6 members')
  })

  it('says this part is not switched on when the route is missing, and shows no fault', () => {
    store.values.missing = true
    const w = make()
    expect(w.text()).toContain('This part is not switched on yet.')
    expect(w.find('.notice-message').exists()).toBe(false)
  })

  it('shows a fault as a notice, and says it is loading before the first answer', () => {
    store.values.error = 'boom'
    expect(make().find('.notice-message').text()).toBe('boom')
    store.values.error = null
    store.values.loading = true
    expect(make().text()).toContain('Loading...')
  })

  it('never prints a margin of error', () => {
    give([value()])
    expect(make().text()).not.toMatch(/margin|confidence|±|plus or minus/i)
  })
})
