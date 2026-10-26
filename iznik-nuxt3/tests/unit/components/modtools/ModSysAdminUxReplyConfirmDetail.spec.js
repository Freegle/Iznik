import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxReplyConfirmDetail from '~/modtools/components/ModSysAdminUxReplyConfirmDetail.vue'
import { uxStubs } from './uxStubs'

const cell = (o) => ({
  replies: 0,
  asked: 0,
  yes_button: 0,
  no: 0,
  never_answered: 0,
  post_gone: 0,
  waiting: 0,
  reached: 0,
  counted: 0,
  handed_over: 0,
  ...o,
})

// The strata add up to all. For ask the asked are the yes, the no, the never answered and the waiting; reached equals
// the yes.
const reasons = {
  all: {
    control: cell({ replies: 100, reached: 100, counted: 60, handed_over: 15 }),
    ask: cell({
      replies: 100,
      asked: 100,
      yes_button: 50,
      no: 30,
      never_answered: 15,
      waiting: 5,
      reached: 50,
      counted: 60,
      handed_over: 14,
    }),
  },
  damaged: {
    control: cell({ replies: 50, reached: 50, counted: 30, handed_over: 9 }),
    ask: cell({
      replies: 50,
      asked: 50,
      yes_button: 25,
      no: 15,
      never_answered: 8,
      waiting: 2,
      reached: 25,
      counted: 30,
      handed_over: 8,
    }),
  },
  car: {
    control: cell({ replies: 30, reached: 30, counted: 18, handed_over: 4 }),
    ask: cell({
      replies: 30,
      asked: 30,
      yes_button: 15,
      no: 9,
      never_answered: 4,
      waiting: 2,
      reached: 15,
      counted: 18,
      handed_over: 4,
    }),
  },
  both: {
    control: cell({ replies: 20, reached: 20, counted: 12, handed_over: 2 }),
    ask: cell({
      replies: 20,
      asked: 20,
      yes_button: 10,
      no: 6,
      never_answered: 3,
      waiting: 1,
      reached: 10,
      counted: 12,
      handed_over: 2,
    }),
  },
}

const experiment = (over = {}) => ({
  id: 12,
  flow: 'reply',
  name: 'confirm-reply',
  title: 'Ask before passing on a reply to a damaged or bulky offer',
  status: 'running',
  design: {
    channel: 'server',
    source: 'confirm_reply',
    window_hours: 168,
    intake_hours: 168,
    max_hours: 336,
  },
  result: {
    detail: {
      exposed: { control: 100, ask: 100 },
      counted: { control: 60, ask: 60 },
      reasons,
    },
  },
  ...over,
})

// The shared table stub draws cells and no header row, so the labels of the columns never reach the text of the
// component. This one also draws them.
const tableWithHeaders = {
  template:
    '<table class="table"><thead><tr><th v-for="f in fields" :key="f.key">{{ f.label }}</th></tr></thead>' +
    '<tbody><tr v-for="(item, i) in items" :key="i"><td v-for="f in fields" :key="f.key">{{ item[f.key] }}</td></tr></tbody></table>',
  props: ['items', 'fields'],
}

const make = (exp, stubs = uxStubs) =>
  mount(ModSysAdminUxReplyConfirmDetail, {
    props: { experiment: exp },
    global: { stubs },
  })

// The names of the rows of a table, in order.
const rowNames = (w, selector) =>
  w
    .find(selector)
    .findAll('tr')
    .map((r) => r.findAll('td')[0].text())

const STORY = [
  'Repliers',
  'Asked',
  'Said yes',
  'Said no',
  'Did not answer',
  'The offer went first',
  'Still waiting',
  'Reached the poster',
]

const EVERY_ROW = [
  'Repliers',
  'Asked',
  'Said yes',
  'Said no',
  'Did not answer',
  'The offer went first',
  'Still waiting',
  'Reached the poster',
  'Counted',
  'Promised or taken',
]

describe('ModSysAdminUxReplyConfirmDetail', () => {
  it('draws nothing for any experiment but confirm-reply', () => {
    expect(
      make(experiment({ name: 'digest-subject' }))
        .find('[data-testid="ux-reply-confirm"]')
        .exists()
    ).toBe(false)
    expect(make(experiment({ name: 'reply-web' })).html()).not.toContain(
      'Still want it?'
    )
    expect(
      make(experiment()).find('[data-testid="ux-reply-confirm"]').exists()
    ).toBe(true)
  })

  it('draws the heading and the sentence before the first read, with no replies counted yet', () => {
    const w = make(experiment({ result: null }))

    expect(w.find('.ux-exp-h').text()).toBe('Still want it?')
    expect(w.find('.what').text()).toContain(
      'Freegle asks the replier whether they still want an offer that looks damaged'
    )
    expect(w.find('.what').text()).toContain('Only some repliers are asked')
    expect(w.find('.none').text()).toBe('No replies counted yet.')
    expect(w.find('table').exists()).toBe(false)
    expect(w.find('.rows-toggle').exists()).toBe(false)
    expect(w.find('.by-reason').exists()).toBe(false)

    // A read with no reasons in it, or the wrong shape, is the same.
    for (const result of [
      { detail: {} },
      { detail: { reasons: [] } },
      { detail: { reasons: 'x' } },
    ]) {
      expect(make(experiment({ result })).find('.none').exists()).toBe(true)
    }
  })

  it('has no note about writing again, because only the buttons answer', async () => {
    const w = make(experiment())

    await w.find('.rows-toggle').trigger('click')

    expect(w.find('.rows-note').exists()).toBe(false)
    expect(w.text()).not.toContain('Writing again')
    expect(w.text()).not.toContain('Sent twice')
    for (const sentence of w.find('.what').text().split(/\.\s+/)) {
      expect(sentence.split(/\s+/).length, sentence).toBeLessThanOrEqual(30)
    }
  })

  it('names the total, and calls the people counted repliers', () => {
    const w = make(experiment())

    expect(w.find('.all-name').text()).toBe('All reasons')
    // The reasons carry their own class, so counting them does not count the total.
    expect(w.findAll('.reason-name')).toHaveLength(3)
    expect(rowNames(w, '.all')[0]).toBe('Repliers')
    expect(w.find('.all').text()).not.toContain('Replies')
  })

  it('shows the rows that tell the story, with the count behind each, and the rest after Show every row', async () => {
    const w = make(experiment())

    expect(rowNames(w, '.all')).toEqual(STORY)
    const story = w.find('.all').text()
    for (const want of [
      '50.0% (50/100)',
      '30.0% (30/100)',
      '15.0% (15/100)',
      '50.0% (50/100)',
      '100.0% (100/100)',
    ]) {
      expect(story, want).toContain(want)
    }
    // The rest is not drawn until it is asked for.
    expect(story).not.toContain('Promised or taken')
    expect(story).not.toContain('Counted')
    expect(w.find('.rows-toggle').text()).toBe('Show every row')
    expect(w.find('.rows-toggle').attributes('aria-expanded')).toBe('false')

    await w.find('.rows-toggle').trigger('click')

    expect(rowNames(w, '.all')).toEqual(EVERY_ROW)
    const all = w.find('.all').text()
    for (const want of ['25.0% (15/60)', '23.3% (14/60)']) {
      expect(all, want).toContain(want)
    }
    expect(w.find('.rows-toggle').text()).toBe('Show fewer rows')
    expect(w.find('.rows-toggle').attributes('aria-expanded')).toBe('true')

    await w.find('.rows-toggle').trigger('click')
    expect(rowNames(w, '.all')).toEqual(STORY)
  })

  it('accounts for everyone asked: the answers in the story add up to the asked', () => {
    // Staff read yes, no and did not answer as the whole of the asked. They are not: a question also closes when the
    // offer goes first, and some are still open. Leaving those two out showed 85% that should have been 100%.
    const w = make(experiment())
    const rows = w
      .find('.all')
      .findAll('tr')
      .map((r) => r.findAll('td').map((c) => c.text()))
    const answers = [
      'Said yes',
      'Said no',
      'Did not answer',
      'The offer went first',
      'Still waiting',
    ]
    const counted = answers.reduce((sum, name) => {
      const cell = rows.find((r) => r[0] === name)[1]
      return sum + Number(cell.match(/\((\d+)\/\d+\)/)[1])
    }, 0)
    expect(counted).toBe(100)
  })

  it('shows a dash for the answer rows of the version that was not asked', async () => {
    const w = make(experiment())
    await w.find('.rows-toggle').trigger('click')
    const rows = w
      .find('.all')
      .findAll('tr')
      .map((r) => r.findAll('td').map((c) => c.text()))
    const row = (name) => rows.find((r) => r[0] === name)

    // The version with the change comes first and the one without it last. Without it no one is asked, so a share of the
    // asked has nothing to divide by.
    for (const name of [
      'Said yes',
      'Said no',
      'Did not answer',
      'The offer went first',
      'Still waiting',
    ]) {
      expect(row(name)[2], name).toBe('–')
    }
    expect(row('Still waiting')[1]).toBe('5.0% (5/100)')
    expect(row('Still waiting')[2]).toBe('–')
    expect(row('Repliers')).toEqual(['Repliers', '100', '100'])
  })

  it('names the versions as the block above does, the change first', () => {
    // The read lists the asked arm first in one order and the other arm first in another. The columns do not follow it.
    for (const order of [
      { ask: reasons.all.ask, control: reasons.all.control },
      { control: reasons.all.control, ask: reasons.all.ask },
    ]) {
      const w = make(
        experiment({
          result: { detail: { reasons: { ...reasons, all: order } } },
        }),
        { ...uxStubs, 'b-table': tableWithHeaders }
      )

      const heads = w
        .find('.all')
        .findAll('th')
        .map((h) => h.text())
      expect(heads).toEqual(['', 'With the change', 'Without the change'])
    }
  })

  it('shows each reason as a closed row, in the order damaged, car, both, and opens one at a time', async () => {
    const w = make(experiment())

    expect(w.find('.by-reason').text()).toBe('By reason')
    expect(w.findAll('.reason-name').map((n) => n.text())).toEqual([
      'Looks damaged',
      'Needs a car',
      'Both',
    ])
    expect(w.findAll('.reason-table')).toHaveLength(0)
    for (const b of w.findAll('.reason-name')) {
      expect(b.attributes('aria-expanded')).toBe('false')
    }

    // The first reason's own figures: 25 said yes of the 50 asked.
    await w.findAll('.reason-name')[0].trigger('click')
    expect(w.findAll('.reason-table')).toHaveLength(1)
    expect(w.find('.reason-table').text()).toContain('50.0% (25/50)')
    expect(w.findAll('.reason-name')[0].attributes('aria-expanded')).toBe(
      'true'
    )

    // Opening another closes the first: one at a time.
    await w.findAll('.reason-name')[1].trigger('click')
    expect(w.findAll('.reason-table')).toHaveLength(1)
    expect(w.find('.reason-table').text()).toContain('50.0% (15/30)')
    expect(w.findAll('.reason-name')[0].attributes('aria-expanded')).toBe(
      'false'
    )
    expect(w.findAll('.reason-name')[1].attributes('aria-expanded')).toBe(
      'true'
    )

    // Opening the open one closes it.
    await w.findAll('.reason-name')[1].trigger('click')
    expect(w.findAll('.reason-table')).toHaveLength(0)
  })

  it('draws the rows that are asked for in every table, and leaves out a reason the read does not have', async () => {
    const w = make(experiment())
    await w.findAll('.reason-name')[0].trigger('click')

    expect(rowNames(w, '.reason-table')).toEqual(STORY)
    await w.find('.rows-toggle').trigger('click')
    expect(rowNames(w, '.reason-table')).toEqual(EVERY_ROW)

    const onlyDamaged = { all: reasons.all, damaged: reasons.damaged }
    const partial = make(
      experiment({ result: { detail: { reasons: onlyDamaged } } })
    )
    expect(partial.findAll('.reason-name').map((n) => n.text())).toEqual([
      'Looks damaged',
    ])

    // With only the total there is nothing to open.
    const total = make(
      experiment({ result: { detail: { reasons: { all: reasons.all } } } })
    )
    expect(total.find('.all').exists()).toBe(true)
    expect(total.find('.by-reason').exists()).toBe(false)
    expect(total.findAll('.reason-name')).toHaveLength(0)
  })

  it('does not say when members are reached, which the block above it says', () => {
    const w = make(experiment())

    expect(w.text()).not.toMatch(/served until/i)
    expect(w.text()).not.toMatch(/decision on/i)
  })

  it('needs no store: it mounts and reads with no pinia installed', () => {
    // No plugins at all: a store would fail to resolve.
    const w = mount(ModSysAdminUxReplyConfirmDetail, {
      props: { experiment: experiment() },
      global: { stubs: uxStubs, plugins: [] },
    })

    expect(w.find('.all').exists()).toBe(true)
    expect(w.text()).toContain('50.0% (50/100)')
  })
})
