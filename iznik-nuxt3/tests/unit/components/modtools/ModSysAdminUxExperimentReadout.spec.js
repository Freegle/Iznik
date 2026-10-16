import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxExperimentReadout from '~/modtools/components/ModSysAdminUxExperimentReadout.vue'

const result = (over = {}, primary = {}) => ({
  read_at: '2026-10-14T16:00:00+00:00',
  arms: {
    control: { starts: 200, completes: 110 },
    b: { starts: 210, completes: 128 },
  },
  primary: {
    b: {
      control_rate: 0.55,
      variant_rate: 0.61,
      diff_points: 6,
      p_better: 0.91,
      enough: true,
      observed_share: 51,
      expected_share: 50,
      share_off: false,
      ...primary,
    },
  },
  guardrails: [],
  ...over,
})

const guardrail = (over = {}) => ({
  arm: 'b',
  name: 'submit_failed',
  label: 'the share of submissions that failed',
  control_rate: 0.05,
  variant_rate: 0.09,
  p_worse: 0.7,
  ...over,
})

const experiment = (over = {}) => ({
  id: 3,
  flow: 'give',
  status: 'running',
  title: 'T',
  result: result(),
  ...over,
})

const make = (exp) =>
  mount(ModSysAdminUxExperimentReadout, { props: { experiment: exp } })

describe('ModSysAdminUxExperimentReadout', () => {
  describe('a running experiment with a read', () => {
    it('says where the comparison stands, how many started, how many finished and the chance', () => {
      const w = make(experiment())
      expect(w.find('.verdict').text()).toBe('Change is ahead')
      const t = w.text()
      expect(t).toContain('Started: 210 with the change, 200 without.')
      expect(t).toContain(
        'Finished: 61% with the change, 55% without. That is 6 points more.'
      )
      expect(t).toContain('Chance the change is better: 91%.')
    })

    it('draws the ahead word on a dark green that white text can be read on, and not on the theme success green', () => {
      const ahead = make(experiment()).find('.verdict')
      expect(ahead.classes()).toContain('ux-green')
      expect(ahead.classes()).not.toContain('bg-success')
      expect(
        make(experiment({ result: result({}, { p_better: 0.1 }) }))
          .find('.verdict')
          .classes()
      ).toContain('bg-danger')
      expect(
        make(experiment({ result: result({}, { enough: false }) }))
          .find('.verdict')
          .classes()
      ).toContain('bg-secondary')
    })

    it('says too early to tell until enough people have started', () => {
      const w = make(experiment({ result: result({}, { enough: false }) }))
      expect(w.find('.verdict').text()).toBe('Too early to tell')
    })

    it('says the change is behind, and that there is no clear difference', () => {
      expect(
        make(experiment({ result: result({}, { p_better: 0.1 }) }))
          .find('.verdict')
          .text()
      ).toBe('Change is behind')
      expect(
        make(experiment({ result: result({}, { p_better: 0.5 }) }))
          .find('.verdict')
          .text()
      ).toBe('No clear difference yet')
    })

    it('gives a line for each other thing watched, under their label', () => {
      const w = make(
        experiment({
          result: result({
            guardrails: [
              guardrail(),
              guardrail({
                name: 'flow:login',
                label: 'finishing "Signing in or joining"',
                control_rate: 0.73,
                variant_rate: 0.72,
                p_worse: 0.2,
              }),
            ],
          }),
        })
      )
      // The lines are checks on this funnel and on others, so the heading does not call them funnels.
      // "Other funnels we watched" in the details is the list set up at the start.
      expect(w.find('.others-label').text()).toBe('Also checked')
      const lines = w.findAll('.others li').map((l) => l.text())
      expect(lines).toEqual([
        'The share of submissions that failed: 9% with the change, 5% without. It may be worse.',
        'Finishing "Signing in or joining": 72% with the change, 73% without. Fine.',
      ])
    })

    it('marks a line that may be worse, and not one that is fine', () => {
      const w = make(
        experiment({
          result: result({
            guardrails: [guardrail(), guardrail({ p_worse: 0.1, label: 'y' })],
          }),
        })
      )
      const items = w.findAll('.others li')
      expect(items[0].classes()).toContain('worried')
      expect(items[1].classes()).not.toContain('worried')
    })

    it('draws no label when nothing else is watched', () => {
      expect(make(experiment()).find('.others-label').exists()).toBe(false)
    })

    it('warns that the rates cannot be compared when fewer started than expected', () => {
      const w = make(
        experiment({
          result: result(
            {},
            { share_off: true, observed_share: 40, expected_share: 50 }
          ),
        })
      )
      expect(w.find('.share').text()).toContain(
        'Fewer people started with the change than expected. The rates cannot be compared yet.'
      )
    })

    it('warns the other way when more started than expected', () => {
      const w = make(
        experiment({
          result: result(
            {},
            { share_off: true, observed_share: 61, expected_share: 50 }
          ),
        })
      )
      expect(w.find('.share').text()).toContain(
        'More people started with the change than expected. The rates cannot be compared yet.'
      )
    })

    it('draws no warning when the share is as expected', () => {
      expect(make(experiment()).find('.share').exists()).toBe(false)
    })

    it('leaves out the finished line when a side has no rate yet', () => {
      const w = make(
        experiment({
          result: result({}, { variant_rate: null, diff_points: null }),
        })
      )
      expect(w.text()).toContain('Started: 210 with the change')
      expect(w.text()).not.toContain('Finished:')
    })
  })

  describe('a running experiment with no read yet', () => {
    it('says no count yet and draws no verdict and no figures, as just after a restart', () => {
      // start() resets result to the start facts, although the old reads are still in the table.
      const w = make(
        experiment({ result: { mde_points: 4.2 }, latest_read: null })
      )
      expect(w.text()).toContain(
        'No count yet. The first one comes within the hour.'
      )
      expect(w.find('.verdict').exists()).toBe(false)
      expect(w.text()).not.toContain('Started:')
      expect(w.text()).not.toContain('Finished:')
      expect(w.text()).not.toContain('Chance')
    })

    it('says the same for an empty result and for none', () => {
      for (const r of [[], {}, null]) {
        const w = make(experiment({ result: r }))
        expect(w.text()).toContain('No count yet')
      }
    })

    it('shows the figures once the first new read arrives', async () => {
      const w = make(experiment({ result: { mde_points: 4.2 } }))
      expect(w.text()).not.toContain('Started:')
      await w.setProps({ experiment: experiment() })
      expect(w.text()).toContain('Started: 210 with the change, 200 without.')
      expect(w.find('.verdict').exists()).toBe(true)
    })
  })

  describe('a finished experiment', () => {
    it('shows its final read and no verdict', () => {
      const w = make(experiment({ status: 'adopted' }))
      expect(w.find('.verdict').exists()).toBe(false)
      expect(w.text()).toContain('Started: 210 with the change, 200 without.')
      expect(w.text()).toContain('Chance the change is better: 91%.')
    })

    it('shows the decision when it kept no figures', () => {
      const w = make(
        experiment({
          status: 'reverted',
          result: [],
          decision: 'Reverted early: the change was clearly worse.',
        })
      )
      expect(w.text()).toContain(
        'Reverted early: the change was clearly worse.'
      )
      expect(w.text()).not.toContain('Started:')
    })

    it('says no figures were kept when there is neither', () => {
      const w = make(experiment({ status: 'stopped', result: null }))
      expect(w.text()).toContain('No figures were kept.')
    })

    it('says no figures were kept after the decision, once, when there is a decision and no figures', () => {
      const w = make(
        experiment({
          status: 'reverted',
          result: [],
          decision: 'Reverted early: the change was clearly worse.',
        })
      )
      expect(w.text().match(/clearly worse/g)).toHaveLength(1)
      expect(w.find('.none').text()).toBe('No figures were kept.')
    })
  })

  describe('why a finished experiment was decided as it was', () => {
    // E1 can take a change off because a guardrail got worse, whatever the main result. The figures
    // then say the change was better, and only the batch's own sentence says why it was taken off.
    const reason =
      'Reverted at the end: the share of submissions that failed was probably worse with the change (probability 99%), whatever the main result.'
    const decision = `${reason} 398 started with the change and 400 without; 53% finished against 46% (+7.1 points); probability the change is better: 97%.`
    const guardrailRevert = (over = {}) =>
      experiment({
        status: 'reverted',
        decision,
        result: result(
          { guardrails: [guardrail({ p_worse: 0.99 })] },
          {
            p_better: 0.97,
            variant_rate: 0.53,
            control_rate: 0.46,
            diff_points: 7.1,
          }
        ),
        ...over,
      })

    it('writes the stored decision above the figures, as the batch wrote it', () => {
      const w = make(guardrailRevert())
      const text = w.find('.decision')
      expect(text.text()).toBe(decision)
      // Above the figures, so the figures are read with the reason in mind.
      const html = w.html()
      expect(html.indexOf('class="decision')).toBeLessThan(
        html.indexOf('class="started')
      )
      expect(w.find('.started').text()).toBe(
        'Started: 210 with the change, 200 without.'
      )
      expect(w.find('.chance').text()).toBe('Chance the change is better: 97%.')
    })

    it('does so for every kind of ending that has a decision', () => {
      for (const status of [
        'adopted',
        'closed',
        'reverted',
        'inconclusive',
        'stopped',
      ]) {
        const w = make(guardrailRevert({ status }))
        expect(w.find('.decision').exists(), status).toBe(true)
      }
    })

    it('shows it as text, never as markup', () => {
      const w = make(
        guardrailRevert({ decision: 'Stopped: <b>bold</b> and <i>more</i>.' })
      )
      expect(w.find('.decision').text()).toBe(
        'Stopped: <b>bold</b> and <i>more</i>.'
      )
      expect(w.find('.decision b').exists()).toBe(false)
    })

    it('draws nothing when the experiment has no decision', () => {
      expect(
        make(guardrailRevert({ decision: null }))
          .find('.decision')
          .exists()
      ).toBe(false)
      expect(
        make(guardrailRevert({ decision: '   ' }))
          .find('.decision')
          .exists()
      ).toBe(false)
    })

    it('draws nothing for a running experiment, which has not been decided', () => {
      expect(
        make(experiment({ decision: decision }))
          .find('.decision')
          .exists()
      ).toBe(false)
    })
  })
  describe('an experiment decided on the server', () => {
    const words = {
      who: 'members',
      started: 'were counted',
      finished: 'clicked a link within a day',
      tab_started: 'Counted',
      tab_finished: 'Clicked within a day',
      note: 'Each member is counted once, for the first email they were sent, and only when that was at least a day ago.',
    }

    const decided = (over = {}) =>
      experiment({
        design: { channel: 'email', source: 'email_click', window_hours: 24 },
        result: result(
          {
            arms: {
              control: { starts: 12389, completes: 1239 },
              b: { starts: 12402, completes: 2232 },
            },
            words,
          },
          { control_rate: 0.1, variant_rate: 0.18, diff_points: 8 }
        ),
        ...over,
      })

    it('says how many were counted and what happened in the nouns of the source', () => {
      const t = make(decided()).text()
      expect(t).toContain('Counted: 12,402 with the change, 12,389 without.')
      expect(t).toContain(
        'Clicked within a day: 18.0% with the change, 10.0% without. That is 8 points more.'
      )
      expect(t).not.toContain('Sent:')
      expect(t).not.toContain('Started:')
      expect(t).not.toContain('Finished:')
    })

    it('shows how it counts, as a note', () => {
      const w = make(decided())
      expect(w.find('.note').exists()).toBe(true)
      expect(w.find('.note').text()).toBe(words.note)
    })

    it('shows no note for a change to the site', () => {
      expect(make(experiment()).find('.note').exists()).toBe(false)
    })

    it('says members, not starts, when the share counted is not the share served', () => {
      const t = make(
        decided({
          result: result(
            { words },
            { share_off: true, observed_share: 30, expected_share: 50 }
          ),
        })
      ).text()
      expect(t).toContain(
        'Fewer members were counted with the change than expected.'
      )
      expect(t).toContain(
        '30% of those counted were on the change, and about 50% were expected.'
      )
      expect(t).not.toContain('of starts')
    })
  })
})
