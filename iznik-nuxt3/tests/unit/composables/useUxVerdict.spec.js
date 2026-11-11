import { describe, it, expect } from 'vitest'
import {
  verdictOf,
  verdictCounts,
  verdictTitle,
  verdictRowText,
  verdictNextStep,
} from '~/modtools/composables/useUxVerdict'

describe('useUxVerdict', () => {
  describe('verdictOf', () => {
    it('asks for a confirmation of an adopted experiment that is not closed', () => {
      expect(verdictOf({ status: 'adopted' })).toBe('confirm')
    })

    it.each(['reverted', 'inconclusive', 'stopped'])(
      'asks for the code to be removed from a %s experiment',
      (status) => {
        expect(verdictOf({ status })).toBe('remove')
        expect(verdictOf({ status, cleanup_pr_url: '' })).toBe('remove')
      }
    )

    it.each(['draft', 'running', 'closed'])(
      'has no verdict for %s',
      (status) => {
        expect(verdictOf({ status })).toBe(null)
      }
    )

    it('has none once a cleanup pull request is recorded', () => {
      const cleanup_pr_url = 'https://github.com/Freegle/Iznik/pull/1'
      expect(verdictOf({ status: 'adopted', cleanup_pr_url })).toBe(null)
      expect(verdictOf({ status: 'reverted', cleanup_pr_url })).toBe(null)
    })

    it('copes with nothing', () => {
      expect(verdictOf(null)).toBe(null)
      expect(verdictOf(undefined)).toBe(null)
    })
  })

  describe('verdictCounts', () => {
    it('counts across funnels and across running and history', () => {
      const flows = [
        {
          flow: 'give',
          running: [{ status: 'running' }],
          history: [{ status: 'adopted' }, { status: 'closed' }],
        },
        {
          flow: 'ask',
          running: [],
          history: [
            { status: 'reverted' },
            { status: 'stopped', cleanup_pr_url: 'https://x' },
            { status: 'inconclusive' },
            { status: 'draft' },
          ],
        },
      ]
      expect(verdictCounts(flows)).toEqual({ confirm: 1, remove: 2, total: 3 })
    })

    it('is zero for no list at all', () => {
      expect(verdictCounts(undefined)).toEqual({
        confirm: 0,
        remove: 0,
        total: 0,
      })
      expect(verdictCounts([{ flow: 'give' }])).toEqual({
        confirm: 0,
        remove: 0,
        total: 0,
      })
    })
  })

  describe('verdictTitle', () => {
    it('says what is waiting', () => {
      expect(verdictTitle(1, 1)).toBe(
        '2 experiments have a verdict: 1 to confirm, 1 to remove code'
      )
      expect(verdictTitle(0, 3)).toBe(
        '3 experiments have a verdict: 3 to remove code'
      )
      expect(verdictTitle(1, 0)).toBe(
        '1 experiment has a verdict: 1 to confirm'
      )
    })

    it('says nothing when nothing is waiting', () => {
      expect(verdictTitle(0, 0)).toBe('')
      expect(verdictTitle(undefined, undefined)).toBe('')
    })
  })

  describe('what to do next', () => {
    it('tells an adopted one to be confirmed and closed, with the command', () => {
      const e = { status: 'adopted', name: 'give-shorter' }
      expect(verdictRowText(e)).toContain('Confirm')
      expect(verdictNextStep(e)).toContain('Mark as cleaned up')
      expect(verdictNextStep(e)).toContain('ux:experiment close give-shorter')
      expect(verdictNextStep(e)).toContain('ux_cleanups.php')
    })

    it('tells an ended one to have its code removed, with the command', () => {
      const e = { status: 'reverted', name: 'ask-photos' }
      expect(verdictRowText(e)).toContain('Remove the variant code')
      expect(verdictNextStep(e)).toContain('paste the pull request')
      expect(verdictNextStep(e)).toContain('ux:experiment close ask-photos')
      expect(verdictNextStep(e)).toContain('ux_cleanups.php')
    })

    it('says nothing for one with no verdict', () => {
      expect(verdictRowText({ status: 'running' })).toBe('')
      expect(verdictNextStep({ status: 'closed' })).toBe('')
    })
  })
})
