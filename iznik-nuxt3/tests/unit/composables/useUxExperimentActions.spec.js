import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { effectScope, ref } from 'vue'
import {
  ASK_AFTER_MS,
  TOO_LONG_MS,
  REQUESTED_LABEL,
  serverMessage,
  useUxExperimentActions,
} from '~/modtools/composables/useUxExperimentActions'

const draft = { id: 5, flow: 'give', status: 'draft', title: 'A draft' }
const running = { id: 3, flow: 'give', status: 'running', title: 'Running' }
const refusal = {
  action: 'start',
  at: '2026-10-14T10:00:05+00:00',
  message:
    'Experiment give-photos-skip is already running on the funnel "Offering something". Only one change at a time can run on a funnel, so the results can be told apart.',
}

describe('useUxExperimentActions', () => {
  let store
  let items
  let actions
  let scope

  const make = (list = [draft, running]) => {
    store = {
      experimentAction: vi.fn().mockResolvedValue({ queued: true }),
      load: vi.fn().mockResolvedValue(undefined),
    }
    items = ref(list)
    scope = effectScope()
    actions = scope.run(() => useUxExperimentActions(store, () => items.value))
    return actions
  }

  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    scope?.stop()
    vi.useRealTimers()
  })

  describe('serverMessage', () => {
    it('gives the message the server sent with a refusal', () => {
      const err = Object.assign(
        new Error('API Error PATCH /x -> status: 400'),
        {
          response: {
            status: 400,
            data: {
              error: 400,
              message: 'Only a running experiment can be stopped',
            },
          },
        }
      )
      expect(serverMessage(err)).toBe(
        'Only a running experiment can be stopped'
      )
    })

    it('gives a plain fallback, never the technical message, when the server said nothing', () => {
      expect(
        serverMessage(new Error('API Error PATCH /x -> status: 500'))
      ).toBe('That did not work.')
      expect(serverMessage(undefined)).toBe('That did not work.')
      expect(serverMessage({ response: { data: { message: '  ' } } })).toBe(
        'That did not work.'
      )
      expect(serverMessage(new Error('x'), 'Could not save.')).toBe(
        'Could not save.'
      )
    })
  })

  describe('asking for an action', () => {
    it('sends the action for the experiment through the store', async () => {
      make()
      await actions.act(draft, 'start')
      expect(store.experimentAction).toHaveBeenCalledWith(5, {
        action: 'start',
      })
    })

    it('names the three requests in words', () => {
      expect(REQUESTED_LABEL).toEqual({
        start: 'Start requested',
        stop: 'Stop requested',
        close: 'Cleanup noted',
      })
    })

    it('is busy for that experiment only, while the call is on its way', async () => {
      make()
      let finish
      store.experimentAction.mockReturnValue(new Promise((r) => (finish = r)))
      const p = actions.act(draft, 'start')
      expect(actions.busy[5]).toBe(true)
      expect(actions.busy[3]).toBeFalsy()
      finish({ queued: true })
      await p
      expect(actions.busy[5]).toBe(false)
    })

    it('records the request, so a second click cannot queue another', async () => {
      make()
      expect(actions.pending(draft)).toBeNull()
      await actions.act(draft, 'start')
      expect(actions.pending(draft)).toBe('start')
      expect(actions.pending(running)).toBeNull()
    })

    it('puts away the question that asked first', async () => {
      make()
      actions.askToStop(running)
      expect(actions.isConfirmingStop(running)).toBe(true)
      await actions.act(running, 'stop')
      expect(actions.isConfirmingStop(running)).toBe(false)
    })

    it('keeps nothing and shows the server message when the call fails', async () => {
      make()
      store.experimentAction.mockRejectedValue(
        Object.assign(new Error('API Error'), {
          response: { status: 409, data: { message: 'Already at the cap' } },
        })
      )
      await actions.act(draft, 'start')
      expect(actions.problem(draft)).toBe('Already at the cap')
      expect(actions.pending(draft)).toBeNull()
      expect(actions.busy[5]).toBe(false)
    })

    it('clears the last failure when the next try is made', async () => {
      make()
      store.experimentAction.mockRejectedValueOnce(new Error('x'))
      await actions.act(draft, 'start')
      expect(actions.problem(draft)).toBe('That did not work.')
      await actions.act(draft, 'start')
      expect(actions.problem(draft)).toBe('')
    })
  })

  describe('letting go of a request', () => {
    it('lets go once the experiment has a new status', async () => {
      make()
      await actions.act(draft, 'start')
      expect(actions.pending(draft)).toBe('start')
      // The batch started it: the same id is now running.
      items.value = [{ ...draft, status: 'running' }, running]
      expect(actions.pending(items.value[0])).toBeNull()
    })
  })

  describe('a request belongs to one run of an experiment', () => {
    // Starting an experiment stamps a new start time, and stopping or deciding it keeps that time. So
    // the same experiment in the same status twice has two different start times.
    const RUN_ONE = '2026-10-14 10:00:00'
    const RUN_TWO = '2026-10-14 12:00:00'
    const live = (startedAt) => ({
      id: 3,
      flow: 'give',
      status: 'running',
      title: 'Running',
      started_at: startedAt,
    })
    const finished = (status, startedAt) => ({ ...live(startedAt), status })

    it('does not bring a stop request back after a stop and a start again', async () => {
      make([live(RUN_ONE)])
      await actions.act(items.value[0], 'stop')
      expect(actions.pending(items.value[0])).toBe('stop')

      // The batch stopped it, and the person pressed Start again.
      items.value = [finished('stopped', RUN_ONE)]
      expect(actions.pending(items.value[0])).toBeNull()
      await actions.act(items.value[0], 'start')
      expect(actions.pending(items.value[0])).toBe('start')

      // The batch started it again: a new run, with nothing asked of it yet.
      items.value = [live(RUN_TWO)]
      expect(actions.pending(items.value[0])).toBeNull()
      expect(actions.anyPending.value).toBe(false)
    })

    it('does not say the second run was not applied, however long ago the first stop was pressed', async () => {
      make([live(RUN_ONE)])
      await actions.act(items.value[0], 'stop')
      await vi.advanceTimersByTimeAsync(60000)
      items.value = [finished('stopped', RUN_ONE)]
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS)
      await actions.act(items.value[0], 'start')
      items.value = [live(RUN_TWO)]
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS)
      expect(actions.pendingTooLong(items.value[0])).toBe(false)
    })

    it('does not bring a start request back after a start and a second stop', async () => {
      make([finished('stopped', RUN_ONE)])
      await actions.act(items.value[0], 'start')
      items.value = [live(RUN_TWO)]
      await actions.act(items.value[0], 'stop')
      expect(actions.pending(items.value[0])).toBe('stop')

      // Stopped a second time: Start again must be on offer, not a start that was asked for before.
      items.value = [finished('stopped', RUN_TWO)]
      expect(actions.pending(items.value[0])).toBeNull()
    })

    it('does not bring a start request back when it could not tell twice', async () => {
      make([finished('inconclusive', RUN_ONE)])
      await actions.act(items.value[0], 'start')
      items.value = [live(RUN_TWO)]
      expect(actions.pending(items.value[0])).toBeNull()
      items.value = [finished('inconclusive', RUN_TWO)]
      expect(actions.pending(items.value[0])).toBeNull()
    })

    it('does not bring a stop request back when someone else starts it again', async () => {
      make([live(RUN_ONE)])
      await actions.act(items.value[0], 'stop')
      items.value = [finished('stopped', RUN_ONE)]
      // Started again from the command line, with no click in this page.
      items.value = [live(RUN_TWO)]
      expect(actions.pending(items.value[0])).toBeNull()
    })

    it('still holds a request while the same run is in the same status', async () => {
      make([live(RUN_ONE)])
      await actions.act(items.value[0], 'stop')
      // A fresh copy of the same row from a later look at the list.
      items.value = [{ ...live(RUN_ONE), hours: 6.5 }]
      expect(actions.pending(items.value[0])).toBe('stop')
    })

    it('holds a start requested for a draft, which has no start time yet', async () => {
      make([{ id: 5, flow: 'give', status: 'draft', started_at: null }])
      await actions.act(items.value[0], 'start')
      expect(actions.pending({ id: 5, status: 'draft' })).toBe('start')
      expect(actions.pending(items.value[0])).toBe('start')
    })
  })

  describe('when the batch refuses', () => {
    it('shows why, and gives the request back', async () => {
      make()
      await actions.act(draft, 'start')
      const refused = { ...draft, result: { last_refusal: refusal } }
      expect(actions.pending(refused)).toBeNull()
      expect(actions.problem(refused)).toBe(
        `That was not done: ${refusal.message}`
      )
    })

    it('shows a refusal that was there before any click', () => {
      make()
      const old = { ...draft, result: { last_refusal: refusal } }
      expect(actions.problem(old)).toBe(`That was not done: ${refusal.message}`)
    })

    it('does not take an older refusal for the answer to a new click', async () => {
      make()
      const old = { ...draft, result: { last_refusal: refusal } }
      await actions.act(old, 'start')
      expect(actions.pending(old)).toBe('start')
      expect(actions.problem(old)).toBe('')

      const newer = {
        ...draft,
        result: {
          last_refusal: { ...refusal, at: '2026-10-14T11:00:00+00:00' },
        },
      }
      expect(actions.pending(newer)).toBeNull()
      expect(actions.problem(newer)).toContain('That was not done:')
    })
  })

  describe('asking again while a request is waiting', () => {
    it('has six waits, each longer than the last, from 5 seconds', () => {
      expect(ASK_AFTER_MS).toEqual([5000, 10000, 20000, 40000, 80000, 160000])
    })

    it('loads the list after the first wait, and again after the next', async () => {
      make()
      await actions.act(draft, 'start')
      expect(store.load).not.toHaveBeenCalled()
      await vi.advanceTimersByTimeAsync(5000)
      expect(store.load).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(10000)
      expect(store.load).toHaveBeenCalledTimes(2)
    })

    it('asks six times at most, however long the request is waiting, apart from the one look at ten minutes', async () => {
      make()
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(
        ASK_AFTER_MS.reduce((a, b) => a + b, 0) + 60000
      )
      expect(store.load).toHaveBeenCalledTimes(6)
      // The look at ten minutes is the seventh and last (see "the look when a request is ten minutes
      // old").
      await vi.advanceTimersByTimeAsync(600000)
      expect(store.load).toHaveBeenCalledTimes(7)
      await vi.advanceTimersByTimeAsync(3600000)
      expect(store.load).toHaveBeenCalledTimes(7)
    })

    it('stops asking once nothing is waiting', async () => {
      make()
      await actions.act(draft, 'start')
      items.value = [{ ...draft, status: 'running' }, running]
      await vi.advanceTimersByTimeAsync(5000)
      expect(store.load).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(300000)
      expect(store.load).toHaveBeenCalledTimes(1)
    })

    it('gives a later request its own run of looks, even after an earlier one used them all', async () => {
      make()
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(
        ASK_AFTER_MS.reduce((a, b) => a + b, 0) + 1000
      )
      expect(store.load).toHaveBeenCalledTimes(6)
      // The batch applies it at last, with nothing looking. Then the person stops it.
      items.value = [{ ...draft, status: 'running' }, running]
      await actions.act(items.value[0], 'stop')
      await vi.advanceTimersByTimeAsync(5000)
      expect(store.load).toHaveBeenCalledTimes(7)
    })

    it('does not mind a load that fails: the next look tries again', async () => {
      make()
      store.load.mockRejectedValueOnce(new Error('offline'))
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(5000)
      await vi.advanceTimersByTimeAsync(10000)
      expect(store.load).toHaveBeenCalledTimes(2)
    })

    it('does not ask when a call failed and nothing is waiting', async () => {
      make()
      store.experimentAction.mockRejectedValue(new Error('x'))
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(60000)
      expect(store.load).not.toHaveBeenCalled()
    })

    it('stops asking when the page is left', async () => {
      make()
      await actions.act(draft, 'start')
      scope.stop()
      await vi.advanceTimersByTimeAsync(600000)
      expect(store.load).not.toHaveBeenCalled()
    })
  })

  describe('a request that nothing has answered', () => {
    it('is not too long at first, nor before ten minutes', async () => {
      make()
      await actions.act(draft, 'start')
      expect(actions.pendingTooLong(draft)).toBe(false)
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS - 31000)
      expect(actions.pendingTooLong(draft)).toBe(false)
    })

    it('is too long at ten minutes', async () => {
      make()
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS)
      expect(actions.pendingTooLong(draft)).toBe(true)
    })

    it('is about the request that was made, not another experiment', async () => {
      make()
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS)
      expect(actions.pendingTooLong(running)).toBe(false)
    })

    it('stops being too long when the experiment changes status', async () => {
      make()
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS)
      expect(actions.pendingTooLong({ ...draft, status: 'running' })).toBe(
        false
      )
    })

    it('counts from the click that is waiting, not an earlier one', async () => {
      make()
      store.experimentAction.mockResolvedValue({ queued: true })
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS - 60000)
      // A refusal arrives, and the person tries again at nine minutes.
      const refused = { ...draft, result: { last_refusal: refusal } }
      await actions.act(refused, 'start')
      await vi.advanceTimersByTimeAsync(120000)
      expect(actions.pendingTooLong(refused)).toBe(false)
    })
  })

  describe('the look when a request is ten minutes old', () => {
    // The first looks end after about five minutes, so the page has seen nothing for the last five of
    // the ten. It looks once more before it says that nothing was applied. `server` is what the batch
    // has done: a look puts it in the list.
    const SIX_LOOKS_MS = ASK_AFTER_MS.reduce((a, b) => a + b, 0)
    let server

    const makeServed = (first) => {
      server = [first]
      make([first])
      store.load.mockImplementation(async () => {
        items.value = server
      })
    }

    it('looks once more at ten minutes, after the six looks of the first five', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS - 1000)
      expect(store.load).toHaveBeenCalledTimes(6)
      await vi.advanceTimersByTimeAsync(31000)
      expect(store.load).toHaveBeenCalledTimes(7)
    })

    it('looks only once, however long the request goes on waiting', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS + 3600000)
      expect(store.load).toHaveBeenCalledTimes(7)
    })

    it('says it was not applied when that look finds the experiment as it was', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS + 31000)
      expect(actions.pending(items.value[0])).toBe('start')
      expect(actions.pendingTooLong(items.value[0])).toBe(true)
    })

    it('says nothing until that look is back', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(SIX_LOOKS_MS + 1000)
      let back
      store.load.mockImplementation(
        () =>
          new Promise((resolve) => {
            back = () => {
              items.value = server
              resolve()
            }
          })
      )
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS - SIX_LOOKS_MS + 30000)
      expect(store.load).toHaveBeenCalledTimes(7)
      expect(actions.pendingTooLong(draft)).toBe(false)
      back()
      await vi.advanceTimersByTimeAsync(0)
      expect(actions.pendingTooLong(items.value[0])).toBe(true)
    })

    it('does not say it when the batch applied the request after the first looks had ended', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      // Seven minutes in, past the sixth look: the batch applies it, and nothing looks.
      await vi.advanceTimersByTimeAsync(7 * 60 * 1000)
      server = [
        { ...draft, status: 'running', started_at: '2026-10-14 10:00:00' },
      ]
      expect(items.value[0].status).toBe('draft')
      await vi.advanceTimersByTimeAsync(4 * 60 * 1000)
      // The look at ten minutes found it running.
      expect(items.value[0].status).toBe('running')
      expect(actions.pending(items.value[0])).toBeNull()
      expect(actions.pendingTooLong(items.value[0])).toBe(false)
    })

    it('looks again for a new click on the same experiment, and does not count the old look', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS + 31000)
      expect(actions.pendingTooLong(items.value[0])).toBe(true)
      // A refusal comes, and the person presses Start again.
      server = [{ ...draft, result: { last_refusal: refusal } }]
      await store.load()
      expect(actions.pending(items.value[0])).toBeNull()
      await actions.act(items.value[0], 'start')
      expect(actions.pendingTooLong(items.value[0])).toBe(false)

      const before = store.load.mock.calls.length
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS - 1000)
      expect(actions.pendingTooLong(items.value[0])).toBe(false)
      await vi.advanceTimersByTimeAsync(31000)
      // The six looks of the new click, and the look at ten minutes.
      expect(store.load.mock.calls.length - before).toBe(7)
      expect(actions.pendingTooLong(items.value[0])).toBe(true)
    })

    it('does not take a look that was on its way for the look of a click made after it was sent', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(SIX_LOOKS_MS + 1000)
      let back
      store.load.mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            back = () => {
              items.value = server
              resolve()
            }
          })
      )
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS - SIX_LOOKS_MS + 9000)
      expect(store.load).toHaveBeenCalledTimes(7)

      // The person presses Start again while that look is out, and then the look comes back.
      await actions.act(draft, 'start')
      back()
      await vi.advanceTimersByTimeAsync(0)
      const before = store.load.mock.calls.length
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS + 31000)
      // The six looks of the new click, and its own look at ten minutes.
      expect(store.load.mock.calls.length - before).toBe(7)
    })

    it('does not mind a look that fails: it tries again at the next tick', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(SIX_LOOKS_MS + 1000)
      store.load.mockRejectedValueOnce(new Error('offline'))
      // To ten minutes and ten seconds: the look at ten minutes failed, and the next tick is at ten
      // minutes and thirty seconds.
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS - SIX_LOOKS_MS + 9000)
      expect(store.load).toHaveBeenCalledTimes(7)
      expect(actions.pendingTooLong(items.value[0])).toBe(false)
      await vi.advanceTimersByTimeAsync(30000)
      expect(store.load).toHaveBeenCalledTimes(8)
      expect(actions.pendingTooLong(items.value[0])).toBe(true)
    })

    it('does not look when the page is left', async () => {
      makeServed(draft)
      await actions.act(draft, 'start')
      await vi.advanceTimersByTimeAsync(SIX_LOOKS_MS + 1000)
      scope.stop()
      await vi.advanceTimersByTimeAsync(TOO_LONG_MS)
      expect(store.load).toHaveBeenCalledTimes(6)
    })
  })

  describe('stopping an experiment needs a yes', () => {
    it('asks about one experiment at a time', () => {
      make()
      expect(actions.isConfirmingStop(running)).toBe(false)
      actions.askToStop(running)
      expect(actions.isConfirmingStop(running)).toBe(true)
      expect(actions.isConfirmingStop(draft)).toBe(false)
      actions.askToStop(draft)
      expect(actions.isConfirmingStop(running)).toBe(false)
      expect(actions.isConfirmingStop(draft)).toBe(true)
    })

    it('puts the question away when it is cancelled', () => {
      make()
      actions.askToStop(running)
      actions.cancelStop()
      expect(actions.isConfirmingStop(running)).toBe(false)
    })

    it('keeps the question open when the stop fails, so the reason shows beside it', async () => {
      make()
      store.experimentAction.mockRejectedValue(new Error('x'))
      actions.askToStop(running)
      await actions.act(running, 'stop')
      expect(actions.isConfirmingStop(running)).toBe(true)
    })
  })
})
