import { describe, it, expect } from 'vitest'
import {
  STATUS_WORDS,
  NO_READ_YET,
  statusWord,
  estateOf,
  readOf,
  verdictOf,
  worryOf,
  lineOf,
  readLines,
  guardrailLine,
  shareWarning,
  sharedSlotWords,
  firstSentence,
  holdoutText,
  safeLink,
  signedPoints,
  dateWord,
  stampWord,
  durationWord,
  watchedFunnels,
  orderExperiments,
  CHANNEL_WORDS,
  channelWord,
  slotOf,
  windowWord,
  copyLines,
  servingLine,
  exposureLines,
} from '~/modtools/composables/useUxExperimentRead'

// The structured read the hourly tick stores in `result` (UxExperimentService::statistics). Rates are
// fractions, diff_points is in points, and p_better and p_worse are between 0 and 1.
const result = (over = {}, primary = {}) => ({
  read_at: '2026-10-14T16:00:00+00:00',
  window_minutes: 390,
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
      observed_share: 51,
      expected_share: 50,
      share_off: false,
      ...primary,
    },
  },
  guardrails: [],
  allocation: { control: 50, b: 50 },
  ...over,
})

const guardrail = (over = {}) => ({
  arm: 'b',
  name: 'submit_failed',
  label: 'the share of submissions that failed',
  control_rate: 0.05,
  variant_rate: 0.09,
  p_worse: 0.7,
  k: 4,
  ...over,
})

const running = (over = {}) => ({
  id: 3,
  flow: 'give',
  status: 'running',
  title: 'T',
  result: result(),
  ...over,
})

describe('useUxExperimentRead', () => {
  describe('the words for a status', () => {
    it('has one word for each status the engine uses', () => {
      expect(STATUS_WORDS).toEqual({
        draft: 'Not started',
        running: 'Running',
        adopted: 'Kept',
        closed: 'Kept, cleaned up',
        reverted: 'Taken off',
        inconclusive: 'Could not tell',
        stopped: 'Stopped',
      })
    })

    it('says kept for now while the holdout lasts, and plainly kept after', () => {
      expect(statusWord({ status: 'adopted' })).toBe('Kept')
      expect(
        statusWord({ status: 'adopted', holdout_until: '2026-10-25 09:00:00' })
      ).toBe('Kept for now')
    })

    it('prints a status it does not know as it is', () => {
      expect(statusWord({ status: 'paused' })).toBe('paused')
      expect(statusWord(null)).toBe('')
    })

    it('puts every experiment in one of three groups for the chips', () => {
      expect(estateOf({ status: 'running' })).toBe('running')
      expect(estateOf({ status: 'draft' })).toBe('draft')
      for (const status of [
        'adopted',
        'closed',
        'reverted',
        'inconclusive',
        'stopped',
      ]) {
        expect(estateOf({ status })).toBe('finished')
      }
    })
  })

  describe('readOf', () => {
    it('is null when there is no read yet: no result, an empty one, or one with only the start facts', () => {
      expect(readOf(null)).toBeNull()
      expect(readOf({ status: 'draft' })).toBeNull()
      expect(readOf({ status: 'running', result: null })).toBeNull()
      // PHP writes an empty array as [] when there is no minimum effect to record.
      expect(readOf({ status: 'running', result: [] })).toBeNull()
      expect(readOf({ status: 'running', result: {} })).toBeNull()
      expect(
        readOf({ status: 'running', result: { mde_points: 4.2 } })
      ).toBeNull()
      expect(
        readOf({ status: 'running', result: { arms: {}, primary: {} } })
      ).toBeNull()
    })

    it('is null for a result that has figures for a variant but no counts, and does not throw', () => {
      const noArms = { primary: result().primary, guardrails: [] }
      expect(readOf({ status: 'running', result: noArms })).toBeNull()
      expect(
        readOf({
          status: 'running',
          result: { arms: null, primary: result().primary },
        })
      ).toBeNull()
      expect(
        readOf({
          status: 'running',
          result: { arms: 'x', primary: result().primary },
        })
      ).toBeNull()
    })

    it('reads the variant that is most likely to be better', () => {
      const exp = running({
        result: {
          ...result(),
          arms: {
            control: { starts: 100, completes: 40 },
            b: { starts: 90, completes: 40 },
            c: { starts: 95, completes: 50 },
          },
          primary: {
            b: { ...result().primary.b, p_better: 0.6 },
            c: {
              ...result().primary.b,
              p_better: 0.97,
              variant_rate: 0.53,
              diff_points: 13,
              control_rate: 0.4,
            },
          },
        },
      })
      const read = readOf(exp)
      expect(read.arm).toBe('c')
      expect(read.controlStarts).toBe(100)
      expect(read.changeStarts).toBe(95)
      expect(read.pBetter).toBe(0.97)
      expect(read.changeRate).toBe(0.53)
      expect(read.controlRate).toBe(0.4)
      expect(read.diffPoints).toBe(13)
    })

    it('carries the numbers a person reads', () => {
      const read = readOf(running())
      expect(read).toMatchObject({
        arm: 'b',
        controlStarts: 200,
        changeStarts: 210,
        controlRate: 0.46,
        changeRate: 0.51,
        diffPoints: 5.4,
        pBetter: 0.91,
        enough: true,
        shareOff: false,
        observedShare: 51,
        expectedShare: 50,
        guardrails: [],
      })
    })

    it('reads a finished experiment from the same place as a running one', () => {
      for (const status of ['running', 'adopted', 'reverted', 'stopped']) {
        expect(readOf(running({ status })).pBetter, status).toBe(0.91)
      }
    })

    it('keeps only the guardrails of the variant it reads, with the label the batch stored', () => {
      const exp = running({
        result: result({
          guardrails: [
            guardrail(),
            guardrail({ arm: 'c', label: 'something about another arm' }),
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
      expect(readOf(exp).guardrails).toEqual([
        {
          label: 'the share of submissions that failed',
          change: 0.09,
          control: 0.05,
          pWorse: 0.7,
        },
        {
          label: 'finishing "Signing in or joining"',
          change: 0.72,
          control: 0.73,
          pWorse: 0.2,
        },
      ])
    })

    it('treats a variant with no p_better as unread', () => {
      const exp = running({
        result: result({}, { p_better: null }),
      })
      expect(readOf(exp)).toBeNull()
    })
  })

  describe('verdictOf', () => {
    const read = (over) => readOf(running({ result: result({}, over) }))

    it('says too early to tell until enough people have started on both sides', () => {
      expect(verdictOf(read({ enough: false, p_better: 0.99 }))).toBe(
        'Too early to tell'
      )
    })

    it('says the change is ahead at 80 percent or more', () => {
      expect(verdictOf(read({ p_better: 0.8 }))).toBe('Change is ahead')
      expect(verdictOf(read({ p_better: 0.99 }))).toBe('Change is ahead')
    })

    it('says the change is behind at 20 percent or less', () => {
      expect(verdictOf(read({ p_better: 0.2 }))).toBe('Change is behind')
      expect(verdictOf(read({ p_better: 0.01 }))).toBe('Change is behind')
    })

    it('says there is no clear difference in between', () => {
      expect(verdictOf(read({ p_better: 0.5 }))).toBe('No clear difference yet')
      expect(verdictOf(read({ p_better: 0.79 }))).toBe(
        'No clear difference yet'
      )
      expect(verdictOf(read({ p_better: 0.21 }))).toBe(
        'No clear difference yet'
      )
    })
  })

  describe('lineOf, the one line on a row', () => {
    it('says a draft has not started', () => {
      expect(lineOf({ status: 'draft', result: null })).toBe('Not started.')
    })

    it('says there is no count yet for a running experiment with no read', () => {
      expect(NO_READ_YET).toBe(
        'No count yet. The first one comes within the hour.'
      )
      expect(lineOf(running({ result: { mde_points: 4 } }))).toBe(NO_READ_YET)
      expect(lineOf(running({ result: [] }))).toBe(NO_READ_YET)
    })

    it('says too few people so far when there are not enough', () => {
      expect(lineOf(running({ result: result({}, { enough: false }) }))).toBe(
        'Too few people so far to say.'
      )
    })

    it('says looking better at 80 percent or more', () => {
      expect(lineOf(running())).toBe(
        'Looking better: 51% finished against 46% (+5.4 points).'
      )
    })

    it('says looking worse at 20 percent or less, with the minus sign', () => {
      const exp = running({
        result: result(
          {},
          {
            p_better: 0.1,
            variant_rate: 0.44,
            control_rate: 0.49,
            diff_points: -5,
          }
        ),
      })
      expect(lineOf(exp)).toBe(
        'Looking worse: 44% finished against 49% (-5.0 points).'
      )
    })

    it('says there is no clear difference yet in between', () => {
      const exp = running({
        result: result(
          {},
          {
            p_better: 0.55,
            variant_rate: 0.49,
            control_rate: 0.48,
            diff_points: 1,
          }
        ),
      })
      expect(lineOf(exp)).toBe(
        'No clear difference yet: 49% finished against 48% (+1.0 points).'
      )
    })

    it('adds the guardrail that is at least half as likely as not to be worse', () => {
      const exp = running({ result: result({ guardrails: [guardrail()] }) })
      expect(lineOf(exp)).toBe(
        'Looking better: 51% finished against 46% (+5.4 points). Watch: the share of submissions that failed.'
      )
    })

    it('adds the guardrail to the too few people line too', () => {
      const exp = running({
        result: result({ guardrails: [guardrail()] }, { enough: false }),
      })
      expect(lineOf(exp)).toBe(
        'Too few people so far to say. Watch: the share of submissions that failed.'
      )
    })

    it('leaves the watch sentence out for a caller that shows the warning itself', () => {
      const exp = running({ result: result({ guardrails: [guardrail()] }) })
      expect(lineOf(exp, { watch: false })).toBe(
        'Looking better: 51% finished against 46% (+5.4 points).'
      )
      expect(lineOf(exp, { watch: true })).toContain('Watch:')
    })

    it('adds a guardrail that is exactly as likely as not to be worse', () => {
      const exp = running({
        result: result({ guardrails: [guardrail({ p_worse: 0.5 })] }),
      })
      expect(lineOf(exp)).toContain(
        'Watch: the share of submissions that failed.'
      )
    })

    it('adds nothing for a guardrail that is probably fine', () => {
      const exp = running({
        result: result({ guardrails: [guardrail({ p_worse: 0.49 })] }),
      })
      expect(lineOf(exp)).not.toContain('Watch')
    })

    it('names the worst guardrail when several are worrying', () => {
      const exp = running({
        result: result({
          guardrails: [
            guardrail({ p_worse: 0.6 }),
            guardrail({
              name: 'flow:login',
              label: 'finishing "Signing in or joining"',
              p_worse: 0.9,
            }),
          ],
        }),
      })
      expect(lineOf(exp)).toContain('Watch: finishing "Signing in or joining".')
    })

    it('gives the figures for an experiment that was kept', () => {
      const exp = running({
        status: 'adopted',
        result: result({}, { variant_rate: 0.52, diff_points: 6.1 }),
      })
      expect(lineOf(exp)).toBe('Kept: 52% finished against 46% (+6.1 points).')
    })

    it('says kept for now, and until when, inside the holdout', () => {
      const exp = running({
        status: 'adopted',
        holdout_until: '2026-10-20 09:00:00',
        result: result({}, { variant_rate: 0.52, diff_points: 6.1 }),
      })
      expect(lineOf(exp)).toBe(
        'Kept for now: 52% finished against 46% (+6.1 points). Some members keep the old version until 20 Oct.'
      )
    })

    it('says the old code has been removed once the cleanup is done', () => {
      expect(lineOf(running({ status: 'closed' }))).toBe(
        'Kept. The old code has been removed.'
      )
    })

    it('gives the figures for an experiment that was taken off', () => {
      const exp = running({
        status: 'reverted',
        result: result(
          {},
          {
            p_better: 0.05,
            variant_rate: 0.44,
            control_rate: 0.49,
            diff_points: -5,
          }
        ),
      })
      expect(lineOf(exp)).toBe(
        'Taken off: 44% finished against 49% (-5.0 points).'
      )
    })

    it('says it could not tell, and that the change was taken off', () => {
      expect(lineOf(running({ status: 'inconclusive' }))).toBe(
        'Could not tell. The change was taken off.'
      )
    })

    describe('why it was taken off, from the decision the batch stored', () => {
      // The decisions the engine writes (UxExperimentService::decide), one sentence for the reason and
      // then the figures.
      const GUARDRAIL =
        'Reverted at the end: the share of submissions that failed was probably worse with the change (probability 99%), whatever the main result.'
      const FIGURES =
        '398 started with the change and 400 without; 53% finished against 46% (+7.1 points); probability the change is better: 97%.'
      const better = result(
        {},
        {
          p_better: 0.97,
          variant_rate: 0.53,
          control_rate: 0.46,
          diff_points: 7.1,
        }
      )

      it('ends a taken-off line with the first sentence of the decision, so the figures are not left to read as a contradiction', () => {
        const exp = running({
          status: 'reverted',
          result: better,
          decision: `${GUARDRAIL} ${FIGURES}`,
        })
        expect(lineOf(exp)).toBe(
          `Taken off: 53% finished against 46% (+7.1 points). ${GUARDRAIL}`
        )
      })

      it("does so when the change looked worse, too, with the batch's sentence as it was written", () => {
        const exp = running({
          status: 'reverted',
          result: result(
            {},
            {
              p_better: 0.05,
              variant_rate: 0.44,
              control_rate: 0.49,
              diff_points: -5,
            }
          ),
          decision: `Reverted at the end: the change was probably worse. ${FIGURES}`,
        })
        expect(lineOf(exp)).toBe(
          'Taken off: 44% finished against 49% (-5.0 points). Reverted at the end: the change was probably worse.'
        )
      })

      it('does not stop at a decimal point inside the reason', () => {
        const exp = running({
          status: 'reverted',
          result: better,
          decision:
            'Reverted early: the share of submissions that failed got worse with the change (9.1% against 5.2% without it; probability it is worse by more than 1 point: 99%). 398 started.',
        })
        expect(lineOf(exp)).toContain(
          '(9.1% against 5.2% without it; probability it is worse by more than 1 point: 99%).'
        )
        expect(lineOf(exp)).not.toContain('398 started')
      })

      it('gives the reason for a change that could not be told apart, in place of the fixed sentence', () => {
        const why =
          'Time was up before enough people had started with each version to say whether it worked (each needs at least 100), so the change was taken off.'
        expect(
          lineOf(
            running({ status: 'inconclusive', decision: `${why} ${FIGURES}` })
          )
        ).toBe(why)
      })

      it('keeps the fixed sentence for one that could not be told apart and has no decision', () => {
        expect(
          lineOf(running({ status: 'inconclusive', decision: null }))
        ).toBe('Could not tell. The change was taken off.')
        expect(
          lineOf(running({ status: 'inconclusive', decision: '   ' }))
        ).toBe('Could not tell. The change was taken off.')
      })

      it('keeps the figures alone for a taken-off line with no decision', () => {
        expect(
          lineOf(
            running({ status: 'reverted', result: better, decision: null })
          )
        ).toBe('Taken off: 53% finished against 46% (+7.1 points).')
      })

      it('adds no reason to a line that was kept', () => {
        const exp = running({
          status: 'adopted',
          result: better,
          decision: `Adopted at the end: the change was probably better. ${FIGURES}`,
        })
        expect(lineOf(exp)).toBe(
          'Kept: 53% finished against 46% (+7.1 points).'
        )
      })
    })

    it('says it was stopped by hand', () => {
      expect(lineOf(running({ status: 'stopped' }))).toBe('Stopped by hand.')
    })

    it('uses the first sentence of the decision for a finished experiment with no figures', () => {
      const decision =
        'Adopted at the end: the change was probably better. 400 started with the change and 400 without.'
      expect(
        lineOf({ status: 'adopted', result: [], decision, flow: 'give' })
      ).toBe('Adopted at the end: the change was probably better.')
      expect(
        lineOf({ status: 'reverted', result: null, decision, flow: 'give' })
      ).toBe('Adopted at the end: the change was probably better.')
    })

    it('falls back to the status word when a finished experiment has neither figures nor a decision', () => {
      expect(lineOf({ status: 'adopted', result: null, decision: null })).toBe(
        'Kept.'
      )
      expect(lineOf({ status: 'reverted', result: null })).toBe('Taken off.')
    })

    it('does not show a watch warning on a finished experiment', () => {
      const exp = running({
        status: 'adopted',
        result: result({ guardrails: [guardrail()] }),
      })
      expect(lineOf(exp)).not.toContain('Watch')
    })
  })

  describe('worryOf', () => {
    it('is null with no read, and with nothing worrying', () => {
      expect(worryOf(null)).toBeNull()
      expect(worryOf(readOf(running()))).toBeNull()
    })

    it('is the guardrail that is most likely to be worse, from 50 percent', () => {
      const read = readOf(
        running({
          result: result({
            guardrails: [
              guardrail({ p_worse: 0.5 }),
              guardrail({ p_worse: 0.8, label: 'x' }),
            ],
          }),
        })
      )
      expect(worryOf(read).label).toBe('x')
    })
  })

  describe('the read-out lines', () => {
    const lines = (over, primary) =>
      readLines(readOf(running({ result: result(over, primary) })))

    it('is null with no read', () => {
      expect(readLines(null)).toBeNull()
    })

    it('says how many started, with the change first', () => {
      expect(lines().started).toBe('Started: 210 with the change, 200 without.')
    })

    it('puts thousands separators in the counts', () => {
      const l = readLines(
        readOf(
          running({
            result: result({
              arms: {
                control: { starts: 12345, completes: 1 },
                b: { starts: 1200000, completes: 1 },
              },
            }),
          })
        )
      )
      expect(l.started).toBe(
        'Started: 1,200,000 with the change, 12,345 without.'
      )
    })

    it('says how many finished, and the difference in points', () => {
      expect(
        lines({}, { variant_rate: 0.61, control_rate: 0.55, diff_points: 6 })
          .finished
      ).toBe(
        'Finished: 61% with the change, 55% without. That is 6 points more.'
      )
    })

    it('says fewer when the change did worse, and one point is singular', () => {
      expect(
        lines({}, { variant_rate: 0.5, control_rate: 0.55, diff_points: -5.2 })
          .finished
      ).toBe(
        'Finished: 50% with the change, 55% without. That is 5.2 points fewer.'
      )
      expect(lines({}, { diff_points: 1 }).finished).toContain(
        'That is 1 point more.'
      )
      expect(lines({}, { diff_points: -1 }).finished).toContain(
        'That is 1 point fewer.'
      )
    })

    it('says there is no difference we can measure when the difference is nothing, or unknown', () => {
      expect(lines({}, { diff_points: 0 }).finished).toContain(
        'That is no difference we can measure.'
      )
      expect(lines({}, { diff_points: null }).finished).toContain(
        'That is no difference we can measure.'
      )
    })

    it('leaves out the finished line when a side has no rate yet', () => {
      expect(
        lines({}, { variant_rate: null, diff_points: null }).finished
      ).toBeNull()
    })

    it('gives the chance the change is better as a whole percent', () => {
      expect(lines().chance).toBe('Chance the change is better: 91%.')
    })

    it('never calls a day of data certain', () => {
      expect(lines({}, { p_better: 0.999 }).chance).toBe(
        'Chance the change is better: over 99%.'
      )
      expect(lines({}, { p_better: 0.001 }).chance).toBe(
        'Chance the change is better: under 1%.'
      )
    })

    it('has one line for each guardrail', () => {
      const l = lines({
        guardrails: [
          guardrail(),
          guardrail({
            name: 'flow:reply',
            label: 'finishing "Replying to a post"',
            control_rate: 0.73,
            variant_rate: 0.72,
            p_worse: 0.2,
          }),
        ],
      })
      expect(l.others).toEqual([
        'The share of submissions that failed: 9% with the change, 5% without. It may be worse.',
        'Finishing "Replying to a post": 72% with the change, 73% without. Fine.',
      ])
    })

    it('has no guardrail lines when there are no guardrails', () => {
      expect(lines().others).toEqual([])
    })
  })

  describe('guardrailLine', () => {
    it('begins with a capital letter and uses the batch label as it is', () => {
      expect(
        guardrailLine({
          label: 'the number of validation failures per start',
          change: 0.12,
          control: 0.1,
          pWorse: 0.1,
        })
      ).toBe(
        'The number of validation failures per start: 12% with the change, 10% without. Fine.'
      )
    })

    it('gives a rate to one decimal place and drops a trailing zero', () => {
      expect(
        guardrailLine({
          label: 'x',
          change: 0.0463,
          control: 0.05,
          pWorse: 0.1,
        })
      ).toBe('X: 4.6% with the change, 5% without. Fine.')
    })

    it('says it may be worse from 50 percent, and fine below', () => {
      const g = (pWorse) =>
        guardrailLine({ label: 'x', change: 0.1, control: 0.1, pWorse })
      expect(g(0.5)).toContain('It may be worse.')
      expect(g(0.499)).toContain('Fine.')
    })

    it('says unknown for a rate it does not have', () => {
      expect(
        guardrailLine({ label: 'x', change: null, control: 0.1, pWorse: 0.1 })
      ).toBe('X: unknown with the change, 10% without. Fine.')
    })

    it('adds no verdict when it has no chance of being worse to go on', () => {
      expect(
        guardrailLine({ label: 'x', change: 0.1, control: 0.1, pWorse: null })
      ).toBe('X: 10% with the change, 10% without.')
    })
  })

  describe('shareWarning', () => {
    const warn = (primary) =>
      shareWarning(readOf(running({ result: result({}, primary) })))

    it('is null when the share of starts is as expected', () => {
      expect(warn({ share_off: false })).toBeNull()
      expect(shareWarning(null)).toBeNull()
    })

    it('says fewer people started with the change than expected, with the numbers', () => {
      expect(
        warn({ share_off: true, observed_share: 40, expected_share: 50 })
      ).toBe(
        'Fewer people started with the change than expected. The rates cannot be compared yet. 40% of starts were on the change, and about 50% were expected.'
      )
    })

    it('says more when more started', () => {
      expect(
        warn({ share_off: true, observed_share: 61, expected_share: 50 })
      ).toBe(
        'More people started with the change than expected. The rates cannot be compared yet. 61% of starts were on the change, and about 50% were expected.'
      )
    })

    it('still warns, without the numbers, when the share is not known', () => {
      const text = warn({
        share_off: true,
        observed_share: null,
        expected_share: null,
      })
      expect(text).toContain('The rates cannot be compared yet.')
      expect(text).not.toContain('%')
    })
  })

  describe('words for numbers', () => {
    it('writes points with a sign and one decimal', () => {
      expect(signedPoints(5.4)).toBe('+5.4')
      expect(signedPoints(-5)).toBe('-5.0')
      expect(signedPoints(1)).toBe('+1.0')
      expect(signedPoints(0)).toBe('0.0')
    })

    it('does not write a minus in front of zero', () => {
      expect(signedPoints(-0.04)).toBe('0.0')
      expect(signedPoints(-0)).toBe('0.0')
    })

    it('takes the first sentence of a decision, and not at a decimal point', () => {
      expect(firstSentence('One. Two.')).toBe('One.')
      expect(firstSentence('It gained 6.1 points. Then more.')).toBe(
        'It gained 6.1 points.'
      )
      expect(firstSentence('No full stop here')).toBe('No full stop here')
      expect(firstSentence('')).toBe('')
      expect(firstSentence(null)).toBe('')
    })

    it('writes a date as day and month, and a time with the year and UTC', () => {
      expect(dateWord('2026-10-20 09:00:00')).toBe('20 Oct')
      expect(dateWord('2026-01-05T09:00:00Z')).toBe('5 Jan')
      expect(stampWord('2026-10-14 10:05:00')).toBe('14 Oct 2026, 10:05 UTC')
      expect(dateWord('nonsense')).toBe('')
      expect(stampWord(null)).toBe('')
    })

    it('writes how long it ran in minutes under an hour, and hours after', () => {
      expect(durationWord(0.25)).toBe('15 minutes')
      expect(durationWord(1 / 60)).toBe('1 minute')
      expect(durationWord(1)).toBe('1 hour')
      expect(durationWord(6.5)).toBe('6.5 hours')
      expect(durationWord(24)).toBe('24 hours')
      expect(durationWord(null)).toBe('')
    })
  })

  describe('watchedFunnels', () => {
    it('names the other funnels it was set up to watch, once each, not its own', () => {
      expect(
        watchedFunnels({
          flow: 'give',
          design: { touches: ['give', 'login', 'login', 'reply'] },
        })
      ).toEqual(['login', 'reply'])
    })

    it('is an empty list when only its own funnel is watched', () => {
      expect(
        watchedFunnels({ flow: 'give', design: { touches: ['give'] } })
      ).toEqual([])
    })

    it('is null when the experiment does not say', () => {
      expect(watchedFunnels({ flow: 'give', design: null })).toBeNull()
      expect(watchedFunnels({ flow: 'give', design: {} })).toBeNull()
      expect(
        watchedFunnels({ flow: 'give', design: { touches: 'login' } })
      ).toBeNull()
      expect(watchedFunnels(null)).toBeNull()
    })
  })

  describe('orderExperiments', () => {
    const e = (id, status, over = {}) => ({
      id,
      status,
      created_at: '2026-10-01 00:00:00',
      ...over,
    })

    it('puts running first, then not started, then finished', () => {
      const list = [
        e(1, 'adopted', { decided_at: '2026-10-10 00:00:00' }),
        e(2, 'draft'),
        e(3, 'running', { started_at: '2026-10-12 00:00:00' }),
        e(4, 'stopped', { decided_at: '2026-10-11 00:00:00' }),
        e(5, 'inconclusive', { decided_at: '2026-10-09 00:00:00' }),
      ]
      expect(orderExperiments(list).map((x) => x.id)).toEqual([3, 2, 4, 1, 5])
    })

    it('puts the newest first within a group: started, made, decided', () => {
      const list = [
        e(1, 'running', { started_at: '2026-10-10 08:00:00' }),
        e(2, 'running', { started_at: '2026-10-12 08:00:00' }),
        e(3, 'draft', { created_at: '2026-10-02 00:00:00' }),
        e(4, 'draft', { created_at: '2026-10-05 00:00:00' }),
        e(5, 'reverted', { decided_at: '2026-10-03 00:00:00' }),
        e(6, 'adopted', { decided_at: '2026-10-08 00:00:00' }),
      ]
      expect(orderExperiments(list).map((x) => x.id)).toEqual([
        2, 1, 4, 3, 6, 5,
      ])
    })

    it('orders a finished one that has no decision date by when it started, then was made', () => {
      const list = [
        e(1, 'stopped', { started_at: '2026-10-04 00:00:00' }),
        e(2, 'stopped', {}),
        e(3, 'stopped', { decided_at: '2026-10-06 00:00:00' }),
      ]
      expect(orderExperiments(list).map((x) => x.id)).toEqual([3, 1, 2])
    })

    it('lets the higher id win a tie, and leaves the list given as it was', () => {
      const list = [e(1, 'draft'), e(2, 'draft'), e(3, 'draft')]
      const before = JSON.stringify(list)
      expect(orderExperiments(list).map((x) => x.id)).toEqual([3, 2, 1])
      expect(JSON.stringify(list)).toBe(before)
    })

    it('gives an empty list for none', () => {
      expect(orderExperiments(null)).toEqual([])
      expect(orderExperiments([])).toEqual([])
    })
  })

  describe('holdoutText', () => {
    const held = {
      status: 'adopted',
      holdout_until: '2026-10-25 09:00:00',
      result: {},
    }

    it('says who still has the old version and until when, and why it is looked at', () => {
      const t = holdoutText(held)
      expect(t).toContain(
        'Some members still have the old version until 25 Oct, and it is compared with the new one once a day.'
      )
      expect(t).toContain(
        'The change is taken off again if the old version does clearly better on repeated looks.'
      )
    })

    it('says the first look is a day after it was kept, before there is one', () => {
      expect(holdoutText(held)).toContain(
        'The first look is a day after it was kept.'
      )
    })

    it('says the last look, with the chance the change is better and the looks against', () => {
      const t = holdoutText({
        ...held,
        result: { holdout_check: { days: 3, p_better: 0.62, strikes: 0 } },
      })
      expect(t).toContain(
        'Last look, 3 days in: chance the change is better: 62%, 0 against so far.'
      )
    })

    it('says 1 day, not 1 days, at the first look', () => {
      const t = holdoutText({
        ...held,
        result: { holdout_check: { days: 1, p_better: 0.5, strikes: 1 } },
      })
      expect(t).toContain('Last look, 1 day in:')
      expect(t).toContain('1 against so far.')
    })

    it('says there were too few people when the look had no figure', () => {
      const t = holdoutText({
        ...held,
        result: { holdout_check: { days: 2, p_better: null } },
      })
      expect(t).toContain('too few people to say, 0 against so far.')
    })

    it('never calls the last look certain: over 99 percent and under 1 percent, as the chance line does', () => {
      // The batch stores p_better rounded to four places, so 0.9996 and 0.0002 are real values.
      const look = (p) =>
        holdoutText({
          ...held,
          result: { holdout_check: { days: 2, p_better: p, strikes: 0 } },
        })
      expect(look(0.9996)).toContain(
        'Last look, 2 days in: chance the change is better: over 99%, 0 against so far.'
      )
      expect(look(0.0002)).toContain(
        'Last look, 2 days in: chance the change is better: under 1%, 0 against so far.'
      )
      expect(look(0.9996)).not.toContain('100%')
      expect(look(0.0002)).not.toContain(': 0%')
    })

    it('rounds to the same edges the chance line does', () => {
      const look = (p) =>
        holdoutText({
          ...held,
          result: { holdout_check: { days: 2, p_better: p, strikes: 0 } },
        })
      // 0.995 is the first value that would round to 100%, and 0.0049 the last that rounds to 0%.
      expect(look(0.995)).toContain('chance the change is better: over 99%,')
      expect(look(0.994)).toContain('chance the change is better: 99%,')
      expect(look(0.0049)).toContain('chance the change is better: under 1%,')
      expect(look(0.005)).toContain('chance the change is better: 1%,')
    })
  })

  describe('safeLink', () => {
    it('gives back an address that starts with https://', () => {
      expect(safeLink('https://github.com/Freegle/Iznik/pull/1')).toBe(
        'https://github.com/Freegle/Iznik/pull/1'
      )
    })

    it('gives back nothing for any other address, or for no address', () => {
      for (const bad of [
        'javascript:alert(1)',
        'JAVASCRIPT:alert(1)',
        'http://example.com',
        ' https://example.com',
        'data:text/html,<script>alert(1)</script>',
        '//example.com',
        '/relative',
        '',
        null,
        undefined,
        42,
        {},
      ]) {
        expect(safeLink(bad), String(bad)).toBe('')
      }
    })
  })
  // An experiment decided on the server (mail, push, server code) is counted from the database by a
  // metric source. It sends its own nouns in result.words and how many were exposed and counted in
  // result.detail; the lines use them when they are there and the funnel's own words when they are not.
  describe('an experiment decided on the server', () => {
    const words = {
      who: 'members',
      started: 'were counted',
      finished: 'clicked a link within a day',
      tab_started: 'Counted',
      tab_finished: 'Clicked within a day',
      note: 'Each member is counted once, for the first email they were sent, and only when that was at least a day ago.',
    }

    const sourceResult = (over = {}, primary = {}) => ({
      read_at: '2026-10-21T16:00:00+00:00',
      arms: {
        control: { starts: 12389, completes: 1239 },
        items: { starts: 12402, completes: 2232 },
      },
      primary: {
        items: {
          control_rate: 0.1,
          variant_rate: 0.18,
          diff_points: 8,
          p_better: 0.97,
          enough: true,
          observed_share: 50,
          expected_share: 50,
          share_off: false,
          ...primary,
        },
      },
      guardrails: [],
      words,
      detail: {
        exposed: { control: 12500, items: 12512 },
        counted: { control: 12389, items: 12402 },
      },
      ...over,
    })

    const email = (over = {}) => ({
      id: 9,
      flow: 'reply',
      name: 'digest-subject',
      title: 'A plainer digest subject',
      status: 'running',
      created_at: '2026-10-20 09:00:00',
      started_at: '2026-10-20 10:00:00',
      ends_at: '2026-10-22 10:00:00',
      design: {
        channel: 'email',
        type: 'UnifiedDigestDaily',
        source: 'email_click',
        window_hours: 24,
        intake_hours: 24,
        max_hours: 48,
        fixed_split: true,
        touches: [],
        copy: { items: 'Items near you', control: 'Your daily digest' },
      },
      result: sourceResult(),
      ...over,
    })

    describe('the word in the funnel column', () => {
      it('is Email, Push or Server, and empty for a change to the site', () => {
        expect(channelWord({ design: { channel: 'email' } })).toBe('Email')
        expect(channelWord({ design: { channel: 'push' } })).toBe('Push')
        expect(channelWord({ design: { channel: 'server' } })).toBe('Server')
        expect(channelWord({ design: { channel: 'web' } })).toBe('')
        expect(channelWord({ design: { touches: ['give'] } })).toBe('')
        expect(channelWord({ design: null })).toBe('')
        expect(channelWord(null)).toBe('')
        expect(channelWord({ design: { channel: 'sms' } })).toBe('')
        expect(CHANNEL_WORDS).toEqual({
          email: 'Email',
          push: 'Push',
          server: 'Server',
        })
      })
    })

    describe('the slot', () => {
      it('is the design slot, else the channel and type, else the funnel', () => {
        expect(
          slotOf({ flow: 'reply', design: { slot: 'shared:thing' } })
        ).toBe('shared:thing')
        expect(
          slotOf({
            flow: 'reply',
            design: { channel: 'email', type: 'UnifiedDigestDaily' },
          })
        ).toBe('email:UnifiedDigestDaily')
        expect(
          slotOf({
            flow: 'reply',
            design: { slot: 'mine', channel: 'email', type: 'x' },
          })
        ).toBe('mine')
        expect(slotOf({ flow: 'reply', design: { channel: 'email' } })).toBe(
          'reply'
        )
        expect(slotOf({ flow: 'give', design: { touches: ['give'] } })).toBe(
          'give'
        )
        expect(slotOf({ flow: 'give', design: null })).toBe('give')
        expect(slotOf({ flow: 'give', design: { type: 'x' } })).toBe('web:x')
        expect(slotOf(null)).toBe('')
      })
    })

    describe('what a shared slot is for', () => {
      it('names the kind of experiment and the type it changes, and never says slot', () => {
        expect(sharedSlotWords(email())).toBe(
          'Another email experiment is already running for UnifiedDigestDaily'
        )
        expect(
          sharedSlotWords({
            design: { channel: 'push', type: 'daily_new_posts' },
          })
        ).toBe('Another push experiment is already running for daily_new_posts')
        expect(
          sharedSlotWords({ design: { channel: 'server', type: 'confirm' } })
        ).toBe('Another server experiment is already running for confirm')
      })

      it('gives the label the design chose for its slot, whatever its type', () => {
        expect(
          sharedSlotWords({
            design: { channel: 'email', type: 'x', slot: 'shared:thing' },
          })
        ).toBe('Another experiment is already running for shared:thing')
      })

      it('leaves out the channel of an experiment with no channel, and gives nothing with neither', () => {
        expect(sharedSlotWords({ design: { type: 'x' } })).toBe(
          'Another experiment is already running for x'
        )
        expect(sharedSlotWords({ design: { touches: ['give'] } })).toBe('')
        expect(sharedSlotWords({ design: null })).toBe('')
        expect(sharedSlotWords(null)).toBe('')
      })
    })

    describe('the words of the read', () => {
      it('are kept from the read, and a read with none has none', () => {
        const read = readOf(email())
        expect(read.words).toEqual({
          who: 'members',
          finished: 'clicked a link within a day',
          tabStarted: 'Counted',
          tabFinished: 'Clicked within a day',
          note: words.note,
        })
        expect(readOf(running()).words).toBeNull()
        expect(
          readOf(email({ result: sourceResult({ words: 'nope' }) })).words
        ).toBeNull()
        expect(
          readOf(email({ result: sourceResult({ words: { who: 'members' } }) }))
            .words
        ).toBeNull()
      })

      it('say how many were counted and what they did, in the read-out lines', () => {
        const lines = readLines(readOf(email()))
        // Counted, not sent: the members counted are fewer than the members sent so far.
        expect(lines.started).toBe(
          'Counted: 12,402 with the change, 12,389 without.'
        )
        expect(lines.finished).toBe(
          'Clicked within a day: 18.0% with the change, 10.0% without. That is 8 points more.'
        )
        expect(lines.chance).toBe('Chance the change is better: 97%.')
        expect(lines.note).toBe(words.note)
      })

      it('leave a read from Loki saying what it always said, with no note', () => {
        const lines = readLines(readOf(running()))
        expect(lines.started).toBe('Started: 210 with the change, 200 without.')
        expect(lines.finished).toContain('Finished: 51% with the change')
        expect(lines.note).toBeNull()
      })

      it('are in the one line on the row', () => {
        expect(lineOf(email())).toBe(
          'Looking better: 18.0% clicked within a day against 10.0% (+8.0 points).'
        )
        expect(
          lineOf(email({ status: 'adopted', decision: 'Adopted early.' }))
        ).toBe('Kept: 18.0% clicked within a day against 10.0% (+8.0 points).')
        expect(
          lineOf(
            email({
              status: 'reverted',
              decision: 'Reverted early: it got worse.',
            })
          )
        ).toBe(
          'Taken off: 18.0% clicked within a day against 10.0% (+8.0 points). Reverted early: it got worse.'
        )
      })

      it('write a rate to one decimal place, so half a point can be read', () => {
        // 3.0% against 3.4%. Whole percents print "3% against 3%".
        const closeRead = sourceResult(
          {},
          { control_rate: 0.03, variant_rate: 0.034, diff_points: 0.4 }
        )
        const close = email({ result: closeRead })
        expect(lineOf(close)).toBe(
          'Looking better: 3.4% clicked within a day against 3.0% (+0.4 points).'
        )
        expect(readLines(readOf(close)).finished).toBe(
          'Clicked within a day: 3.4% with the change, 3.0% without. That is 0.4 points more.'
        )
        // 4.4% against 4.9% would read "5% against 4%".
        const other = email({
          result: sourceResult(
            {},
            { control_rate: 0.044, variant_rate: 0.049, diff_points: 0.5 }
          ),
        })
        expect(lineOf(other)).toBe(
          'Looking better: 4.9% clicked within a day against 4.4% (+0.5 points).'
        )
        expect(
          lineOf(
            email({
              status: 'adopted',
              decision: 'Adopted early.',
              result: closeRead,
            })
          )
        ).toBe('Kept: 3.4% clicked within a day against 3.0% (+0.4 points).')
      })

      it('keep whole percents for a funnel read from Loki', () => {
        expect(lineOf(running())).toContain('51% finished against 46%')
        expect(readLines(readOf(running())).finished).toContain(
          'Finished: 51% with the change, 46% without.'
        )
      })

      it('say members, not people, when there are too few to say', () => {
        const few = email({
          result: sourceResult({}, { enough: false }),
        })
        expect(lineOf(few)).toBe('Too few members so far to say.')
        expect(lineOf(running({ result: result({}, { enough: false }) }))).toBe(
          'Too few people so far to say.'
        )
      })

      it('say members, not starts, when the share is not what was served', () => {
        const off = (seen, expected) =>
          shareWarning(
            readOf(
              email({
                result: sourceResult(
                  {},
                  {
                    share_off: true,
                    observed_share: seen,
                    expected_share: expected,
                  }
                ),
              })
            )
          )
        expect(off(30, 50)).toBe(
          'Fewer members were counted with the change than expected. The rates cannot be compared yet. 30% of those counted were on the change, and about 50% were expected.'
        )
        expect(off(70, 50)).toContain(
          'More members were counted with the change than expected.'
        )
        const unknown = shareWarning(
          readOf(
            email({
              result: sourceResult(
                {},
                { share_off: true, observed_share: null, expected_share: null }
              ),
            })
          )
        )
        expect(unknown).toBe(
          'The members counted with the change are not the share expected. The rates cannot be compared yet.'
        )
      })
    })

    describe('what it watched', () => {
      it('is nothing but itself: another funnel cannot be split by a server arm', () => {
        expect(watchedFunnels(email())).toBeNull()
        expect(
          watchedFunnels({
            flow: 'reply',
            design: { source: 'x', touches: ['reply', 'give'] },
          })
        ).toBeNull()
        expect(
          watchedFunnels({
            flow: 'give',
            design: { touches: ['give', 'login'] },
          })
        ).toEqual(['login'])
      })
    })

    describe('the window', () => {
      it('is a day, 7 days, an hour or hours, as the batch writes it', () => {
        expect(windowWord(24)).toBe('a day')
        expect(windowWord(168)).toBe('7 days')
        expect(windowWord(1)).toBe('1 hour')
        expect(windowWord(36)).toBe('36 hours')
        expect(windowWord(0)).toBe('')
        expect(windowWord(null)).toBe('')
        expect(windowWord('24')).toBe('')
      })
    })

    describe('the text each version shows', () => {
      it('is a line for each arm, control first, then the others by name', () => {
        expect(copyLines(email())).toEqual([
          'Control: Your daily digest',
          'Items: Items near you',
        ])
        expect(
          copyLines({
            design: { copy: { z: 'last', a: 'first', control: 'base' } },
          })
        ).toEqual(['Control: base', 'A: first', 'Z: last'])
      })

      it('leaves out an arm with no text, and gives nothing for no copy', () => {
        expect(
          copyLines({ design: { copy: { control: ' ', b: 'x', c: 4 } } })
        ).toEqual(['B: x'])
        expect(copyLines({ design: { copy: ['x'] } })).toEqual([])
        expect(copyLines({ design: {} })).toEqual([])
        expect(copyLines(null)).toEqual([])
      })
    })

    describe('how long members are reached', () => {
      it('is the start plus the intake, and the due time while it runs', () => {
        expect(servingLine(email())).toBe(
          'Served until 21 Oct 2026, 10:00 UTC. Decision on 22 Oct 2026, 10:00 UTC.'
        )
      })

      it('gives no decision date once it has been decided', () => {
        expect(servingLine(email({ status: 'adopted' }))).toBe(
          'Served until 21 Oct 2026, 10:00 UTC.'
        )
      })

      it('crosses a day, a month and a year as a clock does', () => {
        const at = (started, hours) =>
          servingLine({
            status: 'adopted',
            started_at: started,
            design: { intake_hours: hours },
          })
        expect(at('2026-10-20 23:30:00', 1)).toBe(
          'Served until 21 Oct 2026, 00:30 UTC.'
        )
        expect(at('2026-10-31 22:00:00', 4)).toBe(
          'Served until 1 Nov 2026, 02:00 UTC.'
        )
        expect(at('2026-12-31 23:00:00', 2)).toBe(
          'Served until 1 Jan 2027, 01:00 UTC.'
        )
        expect(at('2026-10-20 10:00:00', 168)).toBe(
          'Served until 27 Oct 2026, 10:00 UTC.'
        )
      })

      it('says nothing before it starts or when the intake is not known', () => {
        expect(servingLine(email({ started_at: null }))).toBe('')
        expect(servingLine(email({ design: { channel: 'email' } }))).toBe('')
        expect(servingLine(null)).toBe('')
      })
    })

    describe('how many were exposed and counted', () => {
      it('is a line each, in the words and the order of the read-out, with thousands separated', () => {
        // The change first, then the old version, as "Counted: 12,402 with the change, 12,389 without." reads above it.
        expect(exposureLines(email())).toEqual({
          exposed: 'Exposed: 12,512 with the change, 12,500 without.',
          counted: 'Counted: 12,402 with the change, 12,389 without.',
        })
      })

      it('names each version when there are more than two, control first', () => {
        expect(
          exposureLines(
            email({
              result: sourceResult({
                detail: {
                  exposed: { c: 4110, control: 4100, b: 4090 },
                  counted: { b: 3990, c: 4010, control: 4000 },
                },
              }),
            })
          )
        ).toEqual({
          exposed: 'Exposed: control 4,100, b 4,090, c 4,110.',
          counted: 'Counted: control 4,000, b 3,990, c 4,010.',
        })
      })

      it('names the versions when the two are not the old version and one change', () => {
        expect(
          exposureLines(
            email({
              result: sourceResult({
                detail: { exposed: { a: 5, b: 7 }, counted: { a: 1, b: 2 } },
              }),
            })
          )
        ).toEqual({
          exposed: 'Exposed: a 5, b 7.',
          counted: 'Counted: a 1, b 2.',
        })
      })

      it('is nothing before the first read, and a part is left out if it is missing', () => {
        expect(exposureLines(email({ result: null }))).toBeNull()
        expect(exposureLines(email({ result: { arms: {} } }))).toBeNull()
        expect(
          exposureLines(email({ result: sourceResult({ detail: {} }) }))
        ).toBeNull()
        expect(
          exposureLines(
            email({
              result: sourceResult({
                detail: { counted: { control: 0, items: 3 } },
              }),
            })
          )
        ).toEqual({
          exposed: null,
          counted: 'Counted: 3 with the change, 0 without.',
        })
      })

      it('ignores a count that is not a number', () => {
        expect(
          exposureLines(
            email({
              result: sourceResult({
                detail: { exposed: { control: 'many', items: 5 } },
              }),
            })
          )
        ).toEqual({ exposed: 'Exposed: items 5.', counted: null })
      })
    })
  })
})
