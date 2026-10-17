import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxFunnelChart from '~/modtools/components/ModSysAdminUxFunnelChart.vue'
import { uxStubs } from './uxStubs'

const steps = [
  {
    step: 'give',
    label: 'Item and photos',
    entered: 300,
    visits: 310,
    abandoned: 93,
    completed: 200,
    reach: 1,
    lossRate: 0.3,
  },
  {
    step: 'give-whereami',
    label: 'Location',
    entered: 150,
    visits: 160,
    abandoned: 16,
    completed: 120,
    reach: 0.5,
    lossRate: 0.1,
  },
  {
    step: 'give-whoami',
    label: 'Who you are',
    entered: 120,
    visits: 125,
    abandoned: 0,
    completed: 100,
    reach: 0.4,
    lossRate: 0,
  },
]

// Different ways into one stage: signing in or joining.
const stageWithWays = [
  {
    step: 'signin',
    label: 'Signing in or Joining',
    entered: 400,
    visits: 500,
    abandoned: 100,
    completed: 380,
    reach: 1,
    lossRate: 0.2,
    alternatives: [
      {
        step: 'signin',
        label: 'Signing in',
        entered: 300,
        visits: 380,
        abandoned: 60,
        completed: 290,
        reach: 0.75,
        lossRate: 60 / 380,
      },
      {
        step: 'signup',
        label: 'Joining',
        entered: 100,
        visits: 120,
        abandoned: 40,
        completed: 90,
        reach: 0.25,
        lossRate: 40 / 120,
      },
    ],
  },
]

const make = (props = {}) =>
  mount(ModSysAdminUxFunnelChart, {
    props: { title: 'Desktop pages', steps, ...props },
    global: { stubs: uxStubs },
  })

describe('ModSysAdminUxFunnelChart', () => {
  it('draws one row per step, sized by reach, labelled for people', () => {
    const w = make()
    const rows = w.findAll('li.funnel-row')
    expect(rows).toHaveLength(3)
    const widths = w
      .findAll('.bar')
      .map((b) =>
        parseFloat(b.attributes('style').match(/width:\s*([\d.]+)%/)[1])
      )
    expect(widths[0]).toBeGreaterThan(widths[1])
    expect(widths[1]).toBeCloseTo(widths[0] / 2, 0)
    expect(w.text()).toContain('Item and photos')
  })

  it('uses one denominator on a line and says what it is', () => {
    const t = make().text()
    // 93 of the 310 visits, not of the 300 who reached: the reader can check 93/310 = 30%.
    expect(t).toContain('300 reached. 93 of 310 visits ended here (30%)')
    expect(t).toContain('120 reached')
    expect(t).not.toContain('120 reached.')
  })

  it('tags the worst step in words, not only in colour, and draws the loss', () => {
    const w = make({ worst: 'give' })
    const rows = w.findAll('li.funnel-row')
    expect(rows[0].find('.worst-tag').text()).toBe('Biggest loss')
    expect(rows[0].classes()).toContain('worst')
    expect(rows[1].find('.worst-tag').exists()).toBe(false)
    expect(w.findAll('.bar-loss')).toHaveLength(2)
  })

  it('tags nothing unless it is told which step is the worst', () => {
    const w = make()
    expect(w.findAll('.worst-tag')).toHaveLength(0)
    expect(w.findAll('li.funnel-row.worst')).toHaveLength(0)
    expect(w.findAll('button.line')[0].attributes('aria-label')).not.toContain(
      'Biggest loss'
    )
  })

  it('does not work out a worst step itself: change what it is told and the tag moves', async () => {
    // Step "give" has by far the highest share of visits that ended there, and is still not tagged
    // when it is not the step the chart was told about.
    const w = make({ worst: 'give-whoami' })
    const rows = w.findAll('li.funnel-row')
    expect(rows[0].find('.worst-tag').exists()).toBe(false)
    expect(rows[2].find('.worst-tag').exists()).toBe(true)
    await w.setProps({ worst: 'give-whereami' })
    expect(w.findAll('li.funnel-row')[1].find('.worst-tag').exists()).toBe(true)
    expect(w.findAll('li.funnel-row')[2].find('.worst-tag').exists()).toBe(
      false
    )
    expect(w.findAll('.worst-tag')).toHaveLength(1)
  })

  it('draws a worst step it is told about even when it has few visits, since the caller decides', () => {
    const w = make({
      worst: 'b',
      steps: [
        {
          step: 'a',
          label: 'A',
          entered: 100,
          visits: 100,
          abandoned: 10,
          reach: 1,
          lossRate: 0.1,
        },
        {
          step: 'b',
          label: 'B',
          entered: 5,
          visits: 5,
          abandoned: 5,
          reach: 0.05,
          lossRate: 1,
        },
      ],
    })
    expect(w.findAll('li.funnel-row')[1].classes()).toContain('worst')
  })

  describe('the tag column', () => {
    it('gives every row a tag slot as the first cell of its line, and fills only the worst row', () => {
      const w = make({ worst: 'give-whereami' })
      const rows = w.findAll('li.funnel-row')
      expect(rows).toHaveLength(3)
      for (const row of rows) {
        const line = row.find('.line')
        const first = line.element.firstElementChild
        expect(first.classList.contains('label-line')).toBe(true)
        expect(first.firstElementChild.classList.contains('tag-slot')).toBe(
          true
        )
        expect(first.children[1].classList.contains('row-label')).toBe(true)
      }
      expect(rows.map((r) => r.find('.tag-slot .worst-tag').exists())).toEqual([
        false,
        true,
        false,
      ])
    })

    it('keeps the label free of the tag, so a long label never pushes it along', () => {
      const w = make({ worst: 'give' })
      expect(w.find('.row-label').text()).toBe('Item and photos')
      expect(w.find('.row-label .worst-tag').exists()).toBe(false)
    })

    it('never tags a stage heading', () => {
      // The heading of a stage with ways in carries the key of its commonest way in.
      const w = make({ steps: stageWithWays, worst: 'signin' })
      const rows = w.findAll('li.funnel-row')
      expect(rows[0].find('.worst-tag').exists()).toBe(false)
      expect(rows[1].find('.worst-tag').exists()).toBe(true)
      expect(w.findAll('.worst-tag')).toHaveLength(1)
    })

    it('has no tag in an exit row', () => {
      const w = make({
        worst: 'forgot',
        exits: [
          { step: 'forgot', label: 'Went to reset', entered: 4, visits: 4 },
        ],
      })
      expect(
        w.findAll('li.funnel-row').at(-1).find('.worst-tag').exists()
      ).toBe(false)
    })
  })

  it('outlines the previous period when given', () => {
    const w = make({ previousReach: { give: 1, 'give-whereami': 0.7 } })
    const prev = w.findAll('.bar-prev')
    expect(prev).toHaveLength(2)
    expect(prev[1].attributes('style')).toContain('width: 70%')
  })

  it('outlines a stage heading with the stage before, not with one way in', () => {
    const w = make({
      steps: stageWithWays,
      previousReach: { 'stage:0': 1, signin: 0.6, signup: 0.4 },
    })
    const prev = w.findAll('.bar-prev')
    expect(prev).toHaveLength(3)
    // The heading, then signing in, then joining.
    expect(prev[0].attributes('style')).toContain('width: 100%')
    expect(prev[1].attributes('style')).toContain('width: 60%')
    expect(prev[2].attributes('style')).toContain('width: 40%')
  })

  it('explains the dashed outline on the chart itself', () => {
    expect(make().text()).toContain('Grey dashed outline')
    expect(make().text()).toContain("that period's first step")
  })

  describe('as buttons a keyboard and a screen reader can use', () => {
    it('makes each step a real button with a name and a pressed state', () => {
      const w = make({ selected: 'give-whereami', worst: 'give' })
      const buttons = w.findAll('button.line')
      expect(buttons).toHaveLength(3)
      for (const b of buttons) expect(b.attributes('type')).toBe('button')
      expect(buttons[0].attributes('aria-pressed')).toBe('false')
      expect(buttons[1].attributes('aria-pressed')).toBe('true')
      expect(buttons[0].attributes('aria-label')).toContain(
        'Item and photos: 300 reached'
      )
      expect(buttons[0].attributes('aria-label')).toContain('Biggest loss')
    })

    it('has no svg pretending to be one image', () => {
      expect(make().find('svg').exists()).toBe(false)
    })

    it('emits the step on a click and marks the chosen row', async () => {
      const w = make({ selected: 'give-whereami' })
      await w.findAll('button.line')[0].trigger('click')
      expect(w.emitted('select')[0]).toEqual(['give'])
      expect(w.findAll('li.funnel-row')[1].classes()).toContain('active')
    })

    it('labels the list', () => {
      expect(make().find('ul.rows').attributes('aria-label')).toBe(
        'Funnel: Desktop pages'
      )
    })
  })

  describe('as a table', () => {
    it('can show the same figures as a table, and back', async () => {
      const w = make()
      expect(w.find('table').exists()).toBe(false)
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      expect(w.find('ul.rows').exists()).toBe(false)
      const t = w.find('table')
      expect(t.exists()).toBe(true)
      expect(t.text()).toContain('Item and photos')
      expect(t.text()).toContain('310')
      expect(t.text()).toContain('93')
      expect(t.text()).toContain('30%')
      expect(t.find('th[scope="col"]').exists()).toBe(true)
      expect(w.find('[data-testid="chart-view-toggle"]').text()).toBe(
        'Show as bars'
      )
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      expect(w.find('table').exists()).toBe(false)
    })

    it('tags the worst step in the table too, in its first column', async () => {
      const w = make({ worst: 'give-whereami' })
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      const header = w.findAll('thead th')
      expect(header[0].text()).toBe('Flag')
      expect(header[0].find('.visually-hidden').exists()).toBe(true)
      const rows = w.findAll('tbody tr')
      expect(rows[1].findAll('td')[0].text()).toBe('Biggest loss')
      expect(rows[0].findAll('td')[0].text()).toBe('')
      expect(w.findAll('.worst-tag')).toHaveLength(1)
    })

    it('has the columns of the old step cards: the share that finished, and the same for the period before', async () => {
      const w = make()
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      expect(w.findAll('thead th').map((h) => h.text())).toEqual([
        'Flag',
        'Step',
        'Reached',
        'Visits',
        'Finished',
        'Previous period',
        'Ended there',
        'Of visits',
      ])
    })

    it('shows the share of visits that went on, with its counts', async () => {
      const w = make({
        steps: [
          {
            step: 'a',
            label: 'A',
            entered: 100,
            visits: 100,
            abandoned: 10,
            completed: 60,
            reach: 1,
            lossRate: 0.1,
          },
          {
            step: 'b',
            label: 'B',
            entered: 70,
            visits: 80,
            abandoned: 5,
            completed: 20,
            reach: 0.7,
            lossRate: 0.0625,
          },
        ],
      })
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      const cells = w
        .findAll('tbody tr')
        .map((r) => r.findAll('td').map((c) => c.text()))
      expect(cells[0][3]).toBe('60.0% (60/100)')
      expect(cells[1][3]).toBe('25.0% (20/80)')
    })

    it('divides by visits, so a step entered again cannot pass 100 percent', async () => {
      const w = make({
        steps: [
          {
            step: 'a',
            label: 'A',
            entered: 1,
            visits: 2,
            abandoned: 0,
            completed: 2,
            reach: 1,
            lossRate: 0,
          },
        ],
      })
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      expect(w.findAll('tbody tr')[0].findAll('td')[3].text()).toBe(
        '100.0% (2/2)'
      )
    })

    it('shows the same step in the period before, or a dash when it had none', async () => {
      const w = make({
        previousDone: { give: { completed: 150, visits: 200 } },
      })
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      const rows = w.findAll('tbody tr').map((r) => r.findAll('td'))
      expect(rows[0][4].text()).toBe('75.0% (150/200)')
      expect(rows[1][4].text()).toBe('–')
    })

    it("adds up a stage's ways in on its heading, and gives each way in its own", async () => {
      const w = make({
        steps: stageWithWays,
        previousDone: {
          'stage:0': { completed: 300, visits: 400 },
          signin: { completed: 200, visits: 250 },
          signup: { completed: 100, visits: 150 },
        },
      })
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      const rows = w.findAll('tbody tr').map((r) => r.findAll('td'))
      // The heading is the sum of both ways in; each way in shows its own.
      expect(rows[0][3].text()).toBe('76.0% (380/500)')
      expect(rows[0][4].text()).toBe('75.0% (300/400)')
      expect(rows[1][3].text()).toBe('76.3% (290/380)')
      expect(rows[1][4].text()).toBe('80.0% (200/250)')
      expect(rows[2][3].text()).toBe('75.0% (90/120)')
      expect(rows[2][4].text()).toBe('66.7% (100/150)')
    })

    it('leaves both cells blank in an exit row', async () => {
      const w = make({
        exits: [
          { step: 'forgot', label: 'Went to reset', entered: 40, visits: 45 },
        ],
        previousDone: { forgot: { completed: 1, visits: 2 } },
      })
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      const last = w.findAll('tbody tr').at(-1).findAll('td')
      expect(last[3].text()).toBe('')
      expect(last[4].text()).toBe('')
    })

    it('sits inside a box that scrolls, so a phone does not scroll sideways', async () => {
      const w = make()
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      expect(w.find('.table-responsive table').exists()).toBe(true)
    })
  })

  describe('the line that explains the dashed outline', () => {
    it('is drawn once by default', () => {
      expect(make().findAll('.key')).toHaveLength(1)
    })

    it('is left out when the caller says another chart draws it', () => {
      expect(make({ showKey: false }).find('.key').exists()).toBe(false)
      expect(make({ showKey: false }).text()).not.toContain(
        'Grey dashed outline'
      )
    })
  })

  describe('a stage with different ways in', () => {
    it('shows the stage once, and each way in under it as its own row', () => {
      const w = make({ steps: stageWithWays })
      const rows = w.findAll('li.funnel-row')
      expect(rows).toHaveLength(3)
      expect(rows[0].text()).toContain('Signing in or Joining')
      expect(rows[0].find('button').exists()).toBe(false)
      expect(rows[1].classes()).toContain('indent')
      expect(rows[1].text()).toContain('came in through Signing in')
      expect(rows[2].text()).toContain('came in through Joining')
    })

    it('lets a way in be chosen, but not the stage heading', async () => {
      const w = make({ steps: stageWithWays })
      expect(w.findAll('button.line')).toHaveLength(2)
      await w.findAll('button.line')[1].trigger('click')
      expect(w.emitted('select')[0]).toEqual(['signup'])
    })

    it('names the worst way in, not the stage', () => {
      const w = make({ steps: stageWithWays, worst: 'signup' })
      const rows = w.findAll('li.funnel-row')
      expect(rows[0].find('.worst-tag').exists()).toBe(false)
      // 40 of 120 (33%) is worse than 60 of 380 (16%).
      expect(rows[2].find('.worst-tag').exists()).toBe(true)
      expect(rows[1].find('.worst-tag').exists()).toBe(false)
    })

    it('gives each way in a share of everyone who started, so they add up', () => {
      const w = make({ steps: stageWithWays })
      const widths = w
        .findAll('.bar')
        .map((b) =>
          parseFloat(b.attributes('style').match(/width:\s*([\d.]+)%/)[1])
        )
      expect(widths[1] + widths[2]).toBeCloseTo(widths[0], 0)
    })
  })

  describe('a way out that is not a step', () => {
    const exits = [
      {
        step: 'forgot',
        label: 'Went to reset their password',
        entered: 40,
        visits: 45,
      },
    ]

    it('is drawn beside the funnel, labelled, and not counted as lost', () => {
      const w = make({ steps: stageWithWays, exits })
      const row = w.findAll('li.funnel-row').at(-1)
      expect(row.classes()).toContain('exit')
      expect(row.text()).toContain('Went to reset their password')
      expect(row.text()).toContain('40 people. Not counted as lost.')
      expect(row.find('button').exists()).toBe(false)
      expect(row.find('.bar-loss').exists()).toBe(false)
      expect(row.find('.worst-tag').exists()).toBe(false)
    })
  })
})
