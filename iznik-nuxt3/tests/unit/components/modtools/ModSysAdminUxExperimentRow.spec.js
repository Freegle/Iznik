import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxExperimentRow from '~/modtools/components/ModSysAdminUxExperimentRow.vue'

const result = (over = {}, primary = {}) => ({
  arms: {
    control: { starts: 200, completes: 92 },
    b: { starts: 210, completes: 107 },
  },
  primary: {
    b: {
      control_rate: 0.46,
      variant_rate: 0.51,
      diff_points: 5.4,
      p_better: 0.91,
      enough: true,
      share_off: false,
      ...primary,
    },
  },
  guardrails: [],
  ...over,
})

const experiment = (over = {}) => ({
  id: 3,
  flow: 'give',
  name: 'give-photos-skip',
  title: 'Make skipping photos obvious',
  status: 'running',
  result: result(),
  ...over,
})

const make = (props = {}) =>
  mount(ModSysAdminUxExperimentRow, {
    props: { experiment: experiment(), ...props },
  })

describe('ModSysAdminUxExperimentRow', () => {
  it('is one button, with the status, the title, the funnel and the result line', () => {
    const w = make()
    expect(w.element.tagName).toBe('BUTTON')
    expect(w.attributes('type')).toBe('button')
    expect(w.findAll('button')).toHaveLength(1)
    expect(w.find('.status').text()).toBe('Running')
    expect(w.find('.title').text()).toBe('Make skipping photos obvious')
    expect(w.find('.funnel').text()).toBe('Offering')
    expect(w.find('.line').text()).toBe(
      'Looking better: 51% finished against 46% (+5.4 points).'
    )
  })

  it('says it is open or closed to a screen reader, and which block it opens', () => {
    const closed = make({ controls: 'ux-exp-3' })
    expect(closed.attributes('aria-expanded')).toBe('false')
    expect(closed.attributes('aria-controls')).toBe('ux-exp-3')
    const open = make({ open: true })
    expect(open.attributes('aria-expanded')).toBe('true')
    expect(open.classes()).toContain('open')
  })

  it('asks to be opened or closed when it is pressed', async () => {
    const w = make()
    await w.trigger('click')
    expect(w.emitted('toggle')).toHaveLength(1)
  })

  it('names each status in a word', () => {
    const cases = [
      [{ status: 'draft', result: null }, 'Not started'],
      [{ status: 'running' }, 'Running'],
      [{ status: 'adopted' }, 'Kept'],
      [
        { status: 'adopted', holdout_until: '2026-10-25 09:00:00' },
        'Kept for now',
      ],
      [{ status: 'closed' }, 'Kept, cleaned up'],
      [{ status: 'reverted' }, 'Taken off'],
      [{ status: 'inconclusive' }, 'Could not tell'],
      [{ status: 'stopped' }, 'Stopped'],
    ]
    for (const [over, word] of cases) {
      expect(
        make({ experiment: experiment(over) })
          .find('.status')
          .text()
      ).toBe(word)
    }
  })

  describe('the colour of the status word', () => {
    const chip = (over) =>
      make({ experiment: experiment(over) }).find('.status')

    it('keeps kept and cleaned up on a dark green that white text can be read on', () => {
      // The ModTools theme makes Bootstrap's success green light enough that white text on it is 2.8
      // to 1, below the 4.5 to 1 that small text needs. Its class forces the colour, so it is not used.
      for (const status of ['adopted', 'closed']) {
        const classes = chip({ status }).classes()
        expect(classes, status).toContain('ux-green')
        expect(classes, status).not.toContain('bg-success')
      }
    })

    it('keeps running on blue, taken off on red, and the others on grey or plain', () => {
      expect(chip({ status: 'running' }).classes()).toContain('ux-blue')
      expect(chip({ status: 'reverted' }).classes()).toContain('bg-danger')
      expect(chip({ status: 'stopped' }).classes()).toContain('bg-secondary')
      expect(chip({ status: 'inconclusive' }).classes()).toContain(
        'bg-secondary'
      )
      expect(chip({ status: 'draft', result: null }).classes()).toContain(
        'bg-light'
      )
    })
  })

  describe('the line of an experiment that was taken off', () => {
    const reason =
      'Reverted at the end: the share of submissions that failed was probably worse with the change (probability 99%), whatever the main result.'
    const decision = `${reason} 398 started with the change and 400 without; 53% finished against 46% (+7.1 points); probability the change is better: 97%.`

    it('ends with why, so figures that look better do not read as a contradiction', () => {
      const w = make({
        experiment: experiment({
          status: 'reverted',
          decision,
          result: result(
            {},
            {
              p_better: 0.97,
              variant_rate: 0.53,
              control_rate: 0.46,
              diff_points: 7.1,
            }
          ),
        }),
      })
      expect(w.find('.line-text').text()).toBe(
        `Taken off: 53% finished against 46% (+7.1 points). ${reason}`
      )
    })

    it('gives the reason a change could not be told apart, instead of one fixed sentence', () => {
      const why =
        'Inconclusive at the end, so the change was taken off: the numbers do not show whether the change is better or worse.'
      const w = make({
        experiment: experiment({
          status: 'inconclusive',
          decision: `${why} 400 started with the change and 400 without.`,
        }),
      })
      expect(w.find('.line-text').text()).toBe(why)
    })
  })

  it('uses the short name of each funnel, and the key for a funnel it does not know', () => {
    expect(
      make({ experiment: experiment({ flow: 'chat_outcome' }) })
        .find('.funnel')
        .text()
    ).toBe('Marking taken')
    expect(
      make({ experiment: experiment({ flow: 'reply' }) })
        .find('.funnel')
        .text()
    ).toBe('Replying')
    expect(
      make({ experiment: experiment({ flow: 'newthing' }) })
        .find('.funnel')
        .text()
    ).toBe('newthing')
  })

  it('says a draft has not started', () => {
    const w = make({
      experiment: experiment({ status: 'draft', result: null }),
    })
    expect(w.find('.line').text()).toBe('Not started.')
  })

  describe('the extra chips', () => {
    const worrying = result({
      guardrails: [
        {
          arm: 'b',
          name: 'submit_failed',
          label: 'the share of submissions that failed',
          control_rate: 0.05,
          variant_rate: 0.09,
          p_worse: 0.7,
        },
      ],
    })

    it('flags a guardrail of a running experiment that may be worse, once', () => {
      const w = make({ experiment: experiment({ result: worrying }) })
      const chip = w.find('.watch')
      expect(chip.text()).toBe('Watch: the share of submissions that failed')
      // The chip says it, so the line does not say it again.
      expect(w.find('.line-text').text()).not.toContain('Watch')
      expect(w.text().match(/Watch/g)).toHaveLength(1)
    })

    it('draws no watch chip for a guardrail that is fine, or for a finished experiment', () => {
      expect(make().find('.watch').exists()).toBe(false)
      expect(
        make({
          experiment: experiment({ status: 'adopted', result: worrying }),
        })
          .find('.watch')
          .exists()
      ).toBe(false)
    })

    it('flags a cleanup that is overdue', () => {
      const w = make({
        experiment: experiment({ status: 'adopted', cleanup_overdue: true }),
      })
      expect(w.find('.overdue').text()).toBe('Clean up the code')
    })

    it('flags a request the batch has not answered', () => {
      expect(make().find('.waiting').exists()).toBe(false)
      expect(make({ waiting: true }).find('.waiting').text()).toBe(
        'Waiting for the batch'
      )
    })
  })

  it('shows no old figures for an experiment that was started again', () => {
    // start() resets result to the start facts; the reads of the run before are still in the table.
    const w = make({
      experiment: experiment({
        result: { mde_points: 4.2 },
        latest_read: null,
      }),
    })
    expect(w.find('.line').text()).toBe(
      'No count yet. The first one comes within the hour.'
    )
  })
  describe('an experiment decided on the server', () => {
    const words = {
      who: 'members',
      started: 'were counted',
      finished: 'clicked a link within a day',
      tab_started: 'Counted',
      tab_finished: 'Clicked within a day',
      note: 'Each member is counted once.',
    }

    const decided = (channel, over = {}) =>
      experiment({
        flow: 'reply',
        name: `${channel}-test`,
        design: {
          channel,
          type: 'UnifiedDigestDaily',
          source: 'email_click',
          window_hours: 24,
        },
        result: result(
          { words },
          { control_rate: 0.1, variant_rate: 0.18, diff_points: 8 }
        ),
        ...over,
      })

    it('shows the channel and then the funnel it feeds in the funnel column', () => {
      // The Funnel filter above the list chooses by funnel, so a row says which funnel it feeds as well.
      expect(
        make({ experiment: decided('email') })
          .find('.funnel')
          .text()
      ).toBe('Email, Replying')
      expect(
        make({ experiment: decided('push') })
          .find('.funnel')
          .text()
      ).toBe('Push, Replying')
      expect(
        make({ experiment: decided('server') })
          .find('.funnel')
          .text()
      ).toBe('Server, Replying')
    })

    it('does not call server code the site: a change to the site is the one that is on it', () => {
      const text = make({ experiment: decided('server') })
        .find('.funnel')
        .text()
      expect(text).not.toMatch(/\bSite\b/)
    })

    it('shows the channel on a draft too, before it has a read', () => {
      const w = make({
        experiment: decided('email', { status: 'draft', result: null }),
      })
      expect(w.find('.funnel').text()).toBe('Email, Replying')
      expect(w.find('.status').text()).toBe('Not started')
    })

    it('keeps the funnel name for a change to the site', () => {
      expect(make().find('.funnel').text()).toBe('Offering')
      const web = make({
        experiment: experiment({
          design: { touches: ['give'], channel: 'web' },
        }),
      })
      expect(web.find('.funnel').text()).toBe('Offering')
    })

    it('writes its line with the words of the source', () => {
      expect(
        make({ experiment: decided('email') })
          .find('.line-text')
          .text()
      ).toBe(
        'Looking better: 18.0% clicked within a day against 10.0% (+8.0 points).'
      )
    })
  })
})
