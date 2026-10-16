import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { effectScope, ref } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import ModSysAdminUxExperimentDetail from '~/modtools/components/ModSysAdminUxExperimentDetail.vue'
import { useUxExperimentActions } from '~/modtools/composables/useUxExperimentActions'

// The registry is F's. A fake one stands in for the pieces other parts of the tab add.
const hosted = vi.hoisted(() => ({ pieces: [] }))
vi.mock('~/modtools/composables/useUxSections', () => ({
  hostedFor: (slot) => (slot === 'experiment' ? hosted.pieces : []),
}))

const result = (over = {}, primary = {}) => ({
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
      share_off: false,
      ...primary,
    },
  },
  guardrails: [],
  ...over,
})

const running = {
  id: 3,
  flow: 'give',
  name: 'give-photos-skip',
  title: 'Make skipping photos obvious',
  hypothesis: 'People give up at the camera button.',
  change: 'A plain link above the camera button.',
  expected: 'More people reach the next step.',
  status: 'running',
  created_at: '2026-10-14 08:00:00',
  started_at: '2026-10-14 10:00:00',
  ends_at: '2026-10-15 10:00:00',
  decided_at: null,
  decision: null,
  hours: 6.5,
  pr_url: 'https://github.com/Freegle/Iznik/pull/1800',
  cleanup_pr_url: null,
  cleanup_overdue: false,
  holdout_until: null,
  design: { touches: ['give', 'login'], expected_points: 5 },
  result: result(),
  latest_read: {
    read_at: '2026-10-14 16:30:00',
    hours: 6.5,
    summary:
      'After 6.5 hours: 210 people started "Offering something" with the change and 200 without.',
  },
}

const draft = {
  id: 5,
  flow: 'give',
  name: 'give-new',
  title: 'Say photos get more replies',
  hypothesis: 'h',
  change: 'A line under the camera button.',
  expected: 'e',
  status: 'draft',
  created_at: '2026-10-14 08:00:00',
  started_at: null,
  ends_at: null,
  decided_at: null,
  hours: 0,
  design: null,
  result: null,
  latest_read: null,
}

const adopted = {
  ...running,
  id: 2,
  title: 'Shorter first screen',
  status: 'adopted',
  decided_at: '2026-10-11 09:00:00',
  decision: 'Adopted at the end: the change was probably better.',
  latest_read: null,
}

describe('ModSysAdminUxExperimentDetail', () => {
  let store
  let items
  let scope
  let actions

  const make = (experiment, { list, full = false } = {}) => {
    items = ref(list || [experiment])
    scope = effectScope()
    actions = scope.run(() => useUxExperimentActions(store, () => items.value))
    return mount(ModSysAdminUxExperimentDetail, {
      props: { experiment, experiments: items.value, full, actions },
    })
  }

  beforeEach(() => {
    hosted.pieces = []
    store = {
      experimentAction: vi.fn().mockResolvedValue({ queued: true }),
      load: vi.fn().mockResolvedValue(undefined),
    }
  })

  afterEach(() => {
    scope?.stop()
    vi.useRealTimers()
  })

  const headings = (w) => w.findAll('h6').map((h) => h.text())

  describe('what it shows', () => {
    it('tells the story of a running experiment, in order', () => {
      const w = make(running)
      expect(headings(w)).toEqual([
        'What changed',
        'Why we tried it',
        'What we expected',
        'How it is going',
        'Other funnels we watched',
        'Dates',
        'Links',
      ])
      const t = w.text()
      expect(t).toContain('A plain link above the camera button.')
      expect(t).toContain('People give up at the camera button.')
      expect(t).toContain('More people reach the next step.')
    })

    it('says how it ended for a finished one', () => {
      expect(headings(make(adopted))).toContain('How it ended')
      expect(headings(make(adopted))).not.toContain('How it is going')
    })

    it('says why a finished one was decided as it was, even when its figures look the other way', () => {
      // A guardrail can take a change off whatever the main result: 97 percent likely better, yet
      // taken off. Without the batch's reason the card reads as a contradiction.
      const reason =
        'Reverted at the end: the share of submissions that failed was probably worse with the change (probability 99%), whatever the main result.'
      const w = make({
        ...adopted,
        status: 'reverted',
        decision: `${reason} 398 started with the change and 400 without; 53% finished against 46% (+7.1 points); probability the change is better: 97%.`,
        result: result({}, { p_better: 0.97 }),
      })
      const how = w.find('.how')
      expect(how.text()).toContain(reason)
      expect(how.text()).toContain('Chance the change is better: 97%.')
      expect(how.find('.decision').exists()).toBe(true)
    })

    it('has no read-out for a draft, which has not started', () => {
      const w = make(draft)
      expect(headings(w)).toEqual([
        'What changed',
        'Why we tried it',
        'What we expected',
        'Dates',
      ])
    })

    it('draws the read-out inside how it is going', () => {
      const w = make(running)
      expect(w.find('.how .ux-exp-readout').exists()).toBe(true)
      expect(w.find('.how').text()).toContain(
        'Chance the change is better: 91%.'
      )
    })

    it('gives its short name, which the batch uses to name it in a refusal', () => {
      expect(make(running).find('.short-name').text()).toBe(
        'Short name: give-photos-skip'
      )
      expect(
        make({ ...running, name: undefined })
          .find('.short-name')
          .exists()
      ).toBe(false)
    })

    it('names the funnels it was set up to watch, apart from its own', () => {
      const w = make(running)
      const section = w
        .findAll('section')
        .find((s) => s.text().startsWith('Other funnels we watched'))
      expect(section.text()).toContain('Signing in or joining')
      expect(section.text()).not.toContain('Offering something')
    })

    it('says only its own funnel is watched when it names no other', () => {
      const w = make({ ...running, design: { touches: ['give'] } })
      expect(w.text()).toContain('None. Only this funnel is watched.')
    })

    it('leaves the section out when the experiment does not say which it watches', () => {
      expect(headings(make({ ...running, design: null }))).not.toContain(
        'Other funnels we watched'
      )
    })

    it('gives the dates, and how long it has run', () => {
      const t = make(running).find('.dates').text()
      expect(t).toContain('Created: 14 Oct 2026, 08:00 UTC')
      expect(t).toContain('Started: 14 Oct 2026, 10:00 UTC')
      expect(t).toContain('Ends: 15 Oct 2026, 10:00 UTC')
      expect(t).toContain('Running for 6.5 hours.')
    })

    it('shows minutes for a young experiment', () => {
      expect(
        make({ ...running, hours: 0.25 })
          .find('.dates')
          .text()
      ).toContain('Running for 15 minutes.')
    })

    it('gives the decision date and how long a finished one ran, and no end', () => {
      const t = make(adopted).find('.dates').text()
      expect(t).toContain('Decided: 11 Oct 2026, 09:00 UTC')
      expect(t).toContain('Ran for 6.5 hours.')
      expect(t).not.toContain('Ends:')
    })

    it('draws the pieces other parts of the tab add, each given the experiment', () => {
      hosted.pieces = [
        {
          name: 'Piece',
          props: ['experiment'],
          template: '<div class="piece">{{ experiment.name }}</div>',
        },
      ]
      const w = make(running)
      expect(w.find('.piece').text()).toBe('give-photos-skip')
    })
  })

  describe('a restarted experiment shows no old figures', () => {
    // An experiment that was stopped and started again: status running, result holding only the
    // start facts, and no latest read until the first new hour.
    const restarted = {
      ...running,
      result: { mde_points: 4.2 },
      latest_read: null,
      decision: null,
      decided_at: null,
    }

    it('says there is no count yet, and draws no verdict, no figures and no link to the full read', () => {
      const w = make(restarted)
      expect(w.text()).toContain(
        'No count yet. The first one comes within the hour.'
      )
      expect(w.find('.verdict').exists()).toBe(false)
      expect(w.text()).not.toContain('Started: 210')
      expect(w.text()).not.toContain('Chance the change is better')
      expect(w.text()).not.toContain('Show the full read')
    })

    it('shows the figures and the link when the first new read arrives', async () => {
      const w = make(restarted)
      await w.setProps({ experiment: running })
      expect(w.text()).toContain('Started: 210 with the change, 200 without.')
      expect(w.find('.verdict').exists()).toBe(true)
      expect(w.text()).toContain('Show the full read')
    })
  })

  describe('the full read', () => {
    it('is behind a link, and shown as the batch wrote it', async () => {
      const w = make(running)
      expect(w.text()).not.toContain('After 6.5 hours: 210 people')
      await w.find('.full-toggle').trigger('click')
      expect(w.find('.full-read').text()).toContain(
        'After 6.5 hours: 210 people started "Offering something"'
      )
      expect(w.find('.full-toggle').text()).toBe('Hide the full read')
      await w.find('.full-toggle').trigger('click')
      expect(w.find('.full-read').exists()).toBe(false)
    })

    it('keeps its line breaks and shows markup as text, never as html', async () => {
      const w = make({
        ...running,
        latest_read: { summary: 'One.\nTwo <b>bold</b>' },
      })
      await w.find('.full-toggle').trigger('click')
      const el = w.find('.full-read')
      expect(el.text()).toContain('Two <b>bold</b>')
      expect(el.find('b').exists()).toBe(false)
    })

    it('has no link when there is no latest read', () => {
      expect(
        make({ ...running, latest_read: null })
          .find('.full-toggle')
          .exists()
      ).toBe(false)
    })
  })

  describe('links', () => {
    it('draws an https address as a link that opens apart', () => {
      const w = make({
        ...running,
        pr_url: 'https://github.com/Freegle/Iznik/pull/1',
        cleanup_pr_url: 'https://github.com/Freegle/Iznik/pull/2',
      })
      const links = w.findAll('.links a')
      expect(links.map((a) => a.text())).toEqual([
        'The code change',
        'The cleanup',
      ])
      expect(links[0].attributes('href')).toBe(
        'https://github.com/Freegle/Iznik/pull/1'
      )
      expect(links[0].attributes('rel')).toContain('noopener')
      expect(links[0].attributes('target')).toBe('_blank')
    })

    it('shows any other address as text, with no href, so it cannot run', () => {
      const w = make({
        ...running,
        pr_url: 'javascript:alert(1)',
        cleanup_pr_url: 'javascript:alert(2)',
      })
      expect(w.findAll('.links a')).toHaveLength(0)
      expect(w.find('.links').html()).not.toContain('href')
      expect(w.find('.links').text()).toContain('javascript:alert(1)')
      expect(w.find('.links').text()).toContain('javascript:alert(2)')
    })

    it('treats http, a leading space and a data address the same way', () => {
      for (const bad of [
        'http://example.com',
        ' https://example.com',
        'data:text/html,x',
      ]) {
        const w = make({ ...running, pr_url: bad, cleanup_pr_url: null })
        expect(w.findAll('.links a'), bad).toHaveLength(0)
      }
    })

    it('draws no links section when there is no address', () => {
      const w = make({ ...running, pr_url: null, cleanup_pr_url: null })
      expect(headings(w)).not.toContain('Links')
    })
  })

  describe('stopping', () => {
    it('asks first, and does nothing until the answer is yes', async () => {
      const w = make(running)
      await w.find('button.stop').trigger('click')
      expect(store.experimentAction).not.toHaveBeenCalled()
      expect(w.text()).toContain(
        'Stop "Make skipping photos obvious" now? Everyone goes back to the old version.'
      )
      await w.find('button.cancel').trigger('click')
      expect(store.experimentAction).not.toHaveBeenCalled()
      expect(w.find('button.stop').exists()).toBe(true)
      expect(w.find('button.confirm-stop').exists()).toBe(false)
    })

    it('stops on yes, and says so, with when the batch will do it', async () => {
      const w = make(running)
      await w.find('button.stop').trigger('click')
      expect(w.find('button.confirm-stop').text()).toBe('Yes, stop it')
      expect(w.find('button.cancel').text()).toBe('Keep it running')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      expect(store.experimentAction).toHaveBeenCalledWith(3, { action: 'stop' })
      expect(w.text()).toContain('Stop requested')
      expect(w.text()).toContain(
        'The batch applies it within a few minutes; this card changes when it has.'
      )
    })

    it('keeps the button disabled after the request, so a second click cannot queue another', async () => {
      const w = make(running)
      await w.find('button.stop').trigger('click')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      expect(w.find('button.stop').exists()).toBe(false)
      expect(w.find('button.requested').attributes('disabled')).toBeDefined()
      await w.find('button.requested').trigger('click')
      expect(store.experimentAction).toHaveBeenCalledTimes(1)
    })

    it('shows a failure next to the button that was pressed, and offers nothing as requested', async () => {
      store.experimentAction.mockRejectedValueOnce(
        Object.assign(new Error('API Error'), {
          response: {
            status: 400,
            data: { message: 'Only a running experiment can be stopped' },
          },
        })
      )
      const w = make(running)
      await w.find('button.stop').trigger('click')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Only a running experiment can be stopped')
      expect(w.text()).not.toContain('Stop requested')
    })
  })

  describe('starting', () => {
    it('starts a draft, and then shows it as requested', async () => {
      const w = make(draft)
      expect(w.find('button.start').text()).toBe('Start')
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(store.experimentAction).toHaveBeenCalledWith(5, {
        action: 'start',
      })
      expect(w.text()).toContain('Start requested')
      expect(w.find('button.start').exists()).toBe(false)
    })

    it('says start again for one that has finished without being kept', () => {
      for (const status of ['stopped', 'inconclusive', 'reverted']) {
        const w = make({ ...draft, status })
        expect(w.find('button.start').text(), status).toBe('Start again')
      }
    })

    it('offers no start for a running, kept or cleaned up experiment', () => {
      for (const status of ['running', 'adopted', 'closed']) {
        expect(
          make({ ...running, status })
            .find('button.start')
            .exists(),
          status
        ).toBe(false)
      }
    })

    it('shows the error when starting fails, and gives the button back', async () => {
      store.experimentAction.mockRejectedValueOnce(
        Object.assign(new Error('API Error'), {
          response: {
            status: 400,
            data: { message: 'Could not queue the request' },
          },
        })
      )
      const w = make(draft)
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Could not queue the request')
      expect(w.find('button.start').exists()).toBe(true)
    })

    it('lets go of the request once the experiment has a new status', async () => {
      const w = make(draft)
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Start requested')
      // The batch started it: the same id is now running, not a draft.
      items.value = [{ ...draft, status: 'running', result: [] }]
      await w.setProps({ experiment: items.value[0] })
      expect(w.text()).not.toContain('Start requested')
    })
  })

  describe('an experiment stopped and started again, in the same view', () => {
    // Starting stamps a new start time, and stopping keeps it, so each run is its own.
    const stopped = {
      ...running,
      status: 'stopped',
      decided_at: '2026-10-14 11:00:00',
      decision: 'Stopped by hand.',
    }
    const second = {
      ...running,
      started_at: '2026-10-14 13:00:00',
      ends_at: '2026-10-15 13:00:00',
      result: { mde_points: 4.2 },
      latest_read: null,
    }
    const show = async (w, experiment) => {
      items.value = [experiment]
      await w.setProps({ experiment, experiments: items.value })
    }

    it('offers Stop now for the second run, with no note left from the first stop', async () => {
      const w = make(running)
      await w.find('button.stop').trigger('click')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Stop requested')

      await show(w, stopped)
      expect(w.find('button.start').text()).toBe('Start again')
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Start requested')

      await show(w, second)
      expect(w.find('button.stop').exists()).toBe(true)
      expect(w.find('button.requested').exists()).toBe(false)
      expect(w.text()).not.toContain('Stop requested')
      expect(w.text()).not.toContain('Start requested')
    })

    it('does not say the second run was not applied, a long time after the first stop', async () => {
      vi.useFakeTimers()
      const w = make(running)
      await w.find('button.stop').trigger('click')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      await show(w, stopped)
      await vi.advanceTimersByTimeAsync(11 * 60 * 1000)
      await w.find('button.start').trigger('click')
      await flushPromises()
      await show(w, second)
      await vi.advanceTimersByTimeAsync(11 * 60 * 1000)
      await flushPromises()
      expect(w.text()).not.toContain('Not applied yet')
      expect(w.find('button.stop').exists()).toBe(true)
    })

    it('offers Start again for the second ending, with no start left from the run before', async () => {
      const w = make(stopped)
      await w.find('button.start').trigger('click')
      await flushPromises()
      await show(w, second)
      await w.find('button.stop').trigger('click')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      await show(w, { ...stopped, started_at: second.started_at })
      expect(w.find('button.requested').exists()).toBe(false)
      expect(w.find('button.start').text()).toBe('Start again')
    })
  })

  describe('start is disabled when the batch would refuse it', () => {
    const otherRunningHere = { ...running, id: 9 }
    const otherRunningElsewhere = { ...running, id: 9, flow: 'ask' }
    const CAP = 'Already at the cap of running experiments'
    const SAME = 'Another change is running on this funnel'

    it('is disabled, with the reason, while another change runs on the same funnel', () => {
      const w = make(draft, { list: [draft, otherRunningHere] })
      const start = w.find('button.start')
      expect(start.attributes('disabled')).toBeDefined()
      expect(start.attributes('title')).toBe(SAME)
    })

    it('is not held up by an experiment on the same funnel that is not running', () => {
      const w = make(draft, {
        list: [draft, adopted, { ...draft, id: 12, status: 'stopped' }],
      })
      expect(w.find('button.start').attributes('disabled')).toBeUndefined()
    })

    it('is disabled the same way for start again', () => {
      const stopped = { ...draft, status: 'stopped' }
      const w = make(stopped, { list: [stopped, otherRunningHere] })
      expect(w.find('button.start').text()).toBe('Start again')
      expect(w.find('button.start').attributes('disabled')).toBeDefined()
      expect(w.find('button.start').attributes('title')).toBe(SAME)
    })

    it('is enabled when the running experiment is on another funnel', () => {
      const w = make(draft, { list: [draft, otherRunningElsewhere] })
      expect(w.find('button.start').attributes('disabled')).toBeUndefined()
      expect(w.find('button.start').attributes('title')).toBeFalsy()
    })

    it('is disabled at the cap, with the cap as the reason', () => {
      const w = make(draft, { list: [draft], full: true })
      expect(w.find('button.start').attributes('disabled')).toBeDefined()
      expect(w.find('button.start').attributes('title')).toBe(CAP)
    })

    it('gives the cap as the reason when both apply, as the batch checks the cap first', () => {
      const w = make(draft, { list: [draft, otherRunningHere], full: true })
      expect(w.find('button.start').attributes('title')).toBe(CAP)
    })

    it('reads the whole list, not only the rows on show', () => {
      // The list holds every experiment whatever the Funnel select shows.
      const w = make(draft, {
        list: [
          draft,
          { ...running, id: 11 },
          { ...running, id: 12, flow: 'ask' },
        ],
      })
      expect(w.find('button.start').attributes('title')).toBe(SAME)
    })

    it('does not send a start when it is disabled', async () => {
      const w = make(draft, { list: [draft, otherRunningHere] })
      await w.find('button.start').trigger('click')
      expect(store.experimentAction).not.toHaveBeenCalled()
    })

    // A disabled button takes no pointer, so its title is never shown, and a phone has no hover.
    // The reason is also written beside it, where anyone can read it.
    describe('and says why in words beside the button', () => {
      it('writes the same-funnel reason as text, tied to the button for a screen reader', () => {
        const w = make(draft, { list: [draft, otherRunningHere] })
        const reason = w.find('.start-reason')
        expect(reason.text()).toBe(`${SAME}.`)
        expect(w.find('button.start').attributes('aria-describedby')).toBe(
          reason.attributes('id')
        )
      })

      it('writes the cap as the reason when both apply', () => {
        const w = make(draft, { list: [draft, otherRunningHere], full: true })
        expect(w.find('.start-reason').text()).toBe(`${CAP}.`)
      })

      it('writes it beside start again too', () => {
        const stopped = { ...draft, status: 'stopped' }
        const w = make(stopped, { list: [stopped, otherRunningHere] })
        expect(w.find('.start-reason').text()).toBe(`${SAME}.`)
      })

      it('writes nothing when start is on', () => {
        const w = make(draft, { list: [draft, otherRunningElsewhere] })
        expect(w.find('.start-reason').exists()).toBe(false)
        expect(w.find('button.start').attributes('aria-describedby')).toBe(
          undefined
        )
      })

      it('writes nothing beside a button that is not start', () => {
        expect(
          make(running, { list: [running, otherRunningHere] })
            .find('.start-reason')
            .exists()
        ).toBe(false)
      })
    })
  })

  describe('marking the cleanup done', () => {
    it('is labelled as an action, and sends a close', async () => {
      const w = make(adopted)
      const close = w.find('button.close')
      expect(close.text()).toBe('Mark as cleaned up')
      await close.trigger('click')
      await flushPromises()
      expect(store.experimentAction).toHaveBeenCalledWith(2, {
        action: 'close',
      })
      expect(w.text()).toContain('Cleanup noted')
    })

    it('says on the button, while the holdout lasts, that it ends the holdout', () => {
      const held = { ...adopted, holdout_until: '2026-10-25 09:00:00' }
      expect(make(held).find('button.close').attributes('title')).toContain(
        'ends the holdout'
      )
      expect(
        make(adopted).find('button.close').attributes('title')
      ).not.toContain('holdout')
    })

    it('says beside the button, in words, what it also ends while the holdout lasts', () => {
      const held = { ...adopted, holdout_until: '2026-10-25 09:00:00' }
      const w = make(held)
      expect(w.find('.close-note').text()).toBe(
        'This also ends the daily comparison with the old version.'
      )
      expect(w.find('button.close').attributes('aria-describedby')).toBe(
        w.find('.close-note').attributes('id')
      )
    })

    it('says nothing extra once the holdout is over', () => {
      const w = make(adopted)
      expect(w.find('.close-note').exists()).toBe(false)
      expect(w.find('button.close').attributes('aria-describedby')).toBe(
        undefined
      )
    })

    it('offers nothing once the cleanup is done', () => {
      const w = make({ ...adopted, status: 'closed' })
      expect(w.find('.actions button').exists()).toBe(false)
    })
  })

  describe('when the batch refuses', () => {
    const refusal = {
      action: 'start',
      at: '2026-10-14T10:00:05+00:00',
      message:
        'Experiment give-photos-skip is already running on the funnel "Offering something". Only one change at a time can run on a funnel, so the results can be told apart.',
    }

    it('shows why, under the button, and not a raw funnel key', () => {
      const w = make({ ...draft, result: { last_refusal: refusal } })
      expect(w.text()).toContain(
        'That was not done: Experiment give-photos-skip is already running on the funnel "Offering something"'
      )
      expect(w.text()).not.toContain('give flow')
    })

    it('shows why and gives the button back after a request the batch refused', async () => {
      const w = make(draft)
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Start requested')
      items.value = [{ ...draft, result: { last_refusal: refusal } }]
      await w.setProps({ experiment: items.value[0] })
      expect(w.text()).toContain('That was not done:')
      expect(w.text()).not.toContain('Start requested')
      expect(w.find('button.start').exists()).toBe(true)
    })

    it('does not take an older refusal for the answer to a new click', async () => {
      const refused = { ...draft, result: { last_refusal: refusal } }
      const w = make(refused)
      expect(w.text()).toContain('That was not done:')
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Start requested')
      expect(w.text()).not.toContain('That was not done:')
    })
  })

  describe('a request that nothing has answered', () => {
    it('says the batch may not be running, after ten minutes', async () => {
      vi.useFakeTimers()
      const w = make(draft)
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Start requested')
      expect(w.text()).not.toContain('Not applied yet')
      await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
      await flushPromises()
      expect(w.text()).toContain(
        'Not applied yet. The batch may not be running.'
      )
      expect(w.text()).toContain('Start requested')
    })

    it('does not say it when the batch applied the request after the first looks had ended', async () => {
      vi.useFakeTimers()
      let server = draft
      const w = make(draft)
      // A look puts what the batch has done in front of the card, as the section does.
      store.load.mockImplementation(async () => {
        items.value = [server]
        await w.setProps({ experiment: server, experiments: items.value })
      })
      await w.find('button.start').trigger('click')
      await flushPromises()
      await vi.advanceTimersByTimeAsync(7 * 60 * 1000)
      expect(w.text()).toContain('Start requested')

      // Seven minutes in, past the sixth look: the batch applies it, and nothing has looked since.
      server = {
        ...draft,
        status: 'running',
        started_at: '2026-10-14 10:00:00',
        result: { mde_points: 4 },
      }
      await vi.advanceTimersByTimeAsync(4 * 60 * 1000)
      await flushPromises()
      expect(w.text()).not.toContain('Not applied yet')
      expect(w.text()).not.toContain('Start requested')
      expect(w.find('button.stop').exists()).toBe(true)
    })

    it('says it, after a look that finds the experiment as it was', async () => {
      vi.useFakeTimers()
      const w = make(draft)
      store.load.mockImplementation(async () => {
        items.value = [{ ...draft }]
        await w.setProps({
          experiment: items.value[0],
          experiments: items.value,
        })
      })
      await w.find('button.start').trigger('click')
      await flushPromises()
      await vi.advanceTimersByTimeAsync(11 * 60 * 1000)
      await flushPromises()
      expect(w.text()).toContain(
        'Not applied yet. The batch may not be running.'
      )
    })
  })

  describe('an adopted experiment inside its holdout', () => {
    const held = {
      ...adopted,
      holdout_until: '2026-10-25 09:00:00',
      result: {
        ...result(),
        holdout_check: { days: 1, p_better: 0.62, strikes: 0 },
      },
    }

    it('says who still has the old version, until when, and the last look', () => {
      const t = make(held).find('.holdout').text()
      expect(t).toContain(
        'Some members still have the old version until 25 Oct'
      )
      expect(t).toContain('chance the change is better: 62%')
      expect(t).toContain('0 against so far')
    })

    it('says before the first look that there has not been one', () => {
      const w = make({ ...held, result: result() })
      expect(w.find('.holdout').text()).toContain(
        'The first look is a day after it was kept.'
      )
    })

    it('is plainly kept once the holdout is over', () => {
      expect(make(adopted).find('.holdout').exists()).toBe(false)
    })
  })

  it('says a kept experiment is overdue for cleanup', () => {
    const w = make({ ...adopted, cleanup_overdue: true })
    expect(w.text()).toContain(
      'Adopted but its switch is still in the code: cleanup overdue.'
    )
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

    const digest = {
      ...running,
      id: 11,
      flow: 'reply',
      name: 'digest-subject',
      title: 'A plainer digest subject',
      change: 'The digest subject names the items.',
      started_at: '2026-10-20 10:00:00',
      ends_at: '2026-10-22 10:00:00',
      design: {
        channel: 'email',
        type: 'UnifiedDigestDaily',
        source: 'email_click',
        window_hours: 24,
        intake_hours: 24,
        max_hours: 48,
        touches: [],
        copy: { control: 'Your daily digest', items: 'Items near you' },
      },
      result: result(
        {
          arms: {
            control: { starts: 12389, completes: 1239 },
            b: { starts: 12402, completes: 2232 },
          },
          words,
          detail: {
            exposed: { control: 12500, b: 12512 },
            counted: { control: 12389, b: 12402 },
          },
        },
        { control_rate: 0.1, variant_rate: 0.18, diff_points: 8 }
      ),
      latest_read: null,
    }

    const webReply = {
      ...running,
      id: 12,
      flow: 'reply',
      name: 'reply-web',
      title: 'A reply test on the site',
      design: { touches: ['reply'] },
    }

    it('draws the channel block: the funnel, the text of each version, how long members are reached, and the counts', () => {
      const w = make(digest)
      const block = w.find('.ux-exp-channel')
      expect(block.exists()).toBe(true)
      expect(block.text()).toContain('Email experiment')
      expect(block.text()).toContain('Funnel: Replying to a post.')
      expect(block.text()).toContain('Control: Your daily digest')
      expect(block.text()).toContain('Items: Items near you')
      expect(block.text()).toContain(
        'Served until 21 Oct 2026, 10:00 UTC. Decision on 22 Oct 2026, 10:00 UTC.'
      )
      expect(block.text()).toContain(
        'Exposed: 12,512 with the change, 12,500 without.'
      )
    })

    it('draws the readout in the nouns of the source, with its note', () => {
      const w = make(digest)
      expect(w.find('.how').text()).toContain(
        'Counted: 12,402 with the change, 12,389 without.'
      )
      expect(w.find('.how .note').text()).toContain(
        'Each member is counted once'
      )
    })

    it('draws nothing in the channel block for a change to the site', () => {
      expect(make(running).find('.ux-exp-channel').exists()).toBe(false)
    })

    it('does not list other funnels it watched: another funnel cannot be split by a server arm', () => {
      expect(make(digest).text()).not.toContain('Other funnels we watched')
      expect(make(running).text()).toContain('Other funnels we watched')
    })

    it('draws the channel block before the pieces other parts of the tab add', () => {
      hosted.pieces = [
        {
          name: 'Piece',
          props: ['experiment'],
          template: '<p class="hosted-piece">hosted</p>',
        },
      ]
      const html = make(digest).html()
      expect(html.indexOf('ux-exp-channel')).toBeGreaterThan(-1)
      expect(html.indexOf('ux-exp-channel')).toBeLessThan(
        html.indexOf('hosted-piece')
      )
    })

    it('shows a refusal of the start like any other', () => {
      const refused = {
        ...digest,
        status: 'draft',
        started_at: null,
        ends_at: null,
        result: {
          last_refusal: {
            action: 'start',
            message:
              'Experiments in mail, push and server code are switched off. Set the config key ux_server_arms to 1, then start this one again.',
            at: '2026-10-20T09:00:00+00:00',
          },
        },
      }
      const w = make(refused)
      expect(w.find('.problem').text()).toBe(
        'That was not done: Experiments in mail, push and server code are switched off. Set the config key ux_server_arms to 1, then start this one again.'
      )
      expect(w.find('.ux-exp-channel').text()).toContain(
        'Control: Your daily digest'
      )
      expect(w.find('.ux-exp-channel').text()).not.toContain('Served until')
    })

    describe('the Start button follows what the experiment changes, not only the funnel', () => {
      const draftDigest = {
        ...digest,
        id: 13,
        name: 'digest-two',
        status: 'draft',
        started_at: null,
        ends_at: null,
        result: null,
      }
      const startOf = (w) => w.find('button.start')

      it('is off while another change runs for the same email type', () => {
        const w = make(draftDigest, { list: [draftDigest, digest] })
        expect(startOf(w).attributes('disabled')).toBeDefined()
        expect(w.find('.start-reason').text()).toBe(
          'Another email experiment is already running for UnifiedDigestDaily.'
        )
      })

      it('does not use the word slot, which the strip above the list uses for the cap on running experiments', () => {
        const w = make(draftDigest, { list: [draftDigest, digest] })
        expect(w.find('.start-reason').text()).not.toMatch(/slot/i)
      })

      it('is on for a mail test while a change to the site runs on the same funnel', () => {
        const w = make(draftDigest, { list: [draftDigest, webReply] })
        expect(startOf(w).attributes('disabled')).toBeUndefined()
        expect(w.find('.start-reason').exists()).toBe(false)
      })

      it('is on for a change to the site while a mail test runs on the same funnel', () => {
        const draftWeb = {
          ...webReply,
          id: 14,
          status: 'draft',
          started_at: null,
          ends_at: null,
          result: null,
        }
        const w = make(draftWeb, { list: [draftWeb, digest] })
        expect(startOf(w).attributes('disabled')).toBeUndefined()
      })

      it('is off for a change to the site while another runs on its funnel, as before', () => {
        const draftWeb = {
          ...webReply,
          id: 14,
          status: 'draft',
          started_at: null,
          ends_at: null,
          result: null,
        }
        const w = make(draftWeb, { list: [draftWeb, webReply] })
        expect(startOf(w).attributes('disabled')).toBeDefined()
        expect(w.find('.start-reason').text()).toBe(
          'Another change is running on this funnel.'
        )
      })

      it('is off for two that name the same slot, whatever their types', () => {
        const a = {
          ...draftDigest,
          design: { ...draftDigest.design, slot: 'shared:thing' },
        }
        const b = {
          ...digest,
          design: { ...digest.design, type: 'Other', slot: 'shared:thing' },
        }
        const w = make(a, { list: [a, b] })
        expect(startOf(w).attributes('disabled')).toBeDefined()
        expect(w.find('.start-reason').text()).toBe(
          'Another experiment is already running for shared:thing.'
        )
      })
    })
  })
})
