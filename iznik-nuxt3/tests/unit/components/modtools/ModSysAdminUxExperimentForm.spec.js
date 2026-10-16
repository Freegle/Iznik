import { describe, it, expect, vi, afterEach } from 'vitest'
import { effectScope } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxExperimentForm from '~/modtools/components/ModSysAdminUxExperimentForm.vue'
import { useUxExperimentsStore } from '~/modtools/stores/uxExperiments'
import { useUxExperimentActions } from '~/modtools/composables/useUxExperimentActions'

// The switches the member site has code for.
vi.mock('~/ux-experiments.manifest.json', () => ({
  default: {
    experiments: [{ name: 'give-photos-skip', opened: '2026-10-14' }],
  },
}))

const NAME_RULE = 'Use letters, digits, - and _ only, up to 64 characters.'

describe('ModSysAdminUxExperimentForm', () => {
  let store
  let actions
  let scope

  const make = (props = {}) => {
    setActivePinia(createPinia())
    store = useUxExperimentsStore()
    store.createExperiment = vi
      .fn()
      .mockResolvedValue({ id: 7, status: 'draft' })
    store.experimentAction = vi.fn().mockResolvedValue({ queued: true })
    store.load = vi.fn().mockResolvedValue(undefined)
    scope = effectScope()
    actions = scope.run(() => useUxExperimentActions(store))
    return mount(ModSysAdminUxExperimentForm, {
      props: { defaultFlow: 'give', actions, ...props },
    })
  }

  afterEach(() => {
    scope?.stop()
    vi.useRealTimers()
  })

  // Fill every required field with something valid, then any overrides.
  async function fill(w, over = {}) {
    const v = {
      name: 'give-photos-skip',
      title: 'Make skipping photos obvious',
      hypothesis: 'People give up at the camera button.',
      change: 'A plain link above the camera button.',
      expected: 'More people reach the next step.',
      ...over,
    }
    for (const [key, value] of Object.entries(v)) {
      await w.find(`.f-${key}`).setValue(value)
    }
  }

  const problems = (w) => w.findAll('.problems li').map((li) => li.text())

  describe('the fields', () => {
    it('asks each question in plain words', () => {
      const t = make().text()
      for (const label of [
        'Which funnel is this about?',
        'Short name',
        'Title',
        'What is wrong now, and why should this help?',
        'What does the change do?',
        'What do we expect to see?',
        'Which other funnels could this change affect?',
        'How big a change do we expect, in points of finishing?',
        'Link to the code change (optional)',
      ]) {
        expect(t).toContain(label)
      }
    })

    it('has the three buttons', () => {
      const w = make()
      expect(w.find('.save-draft').text()).toBe('Save as draft')
      expect(w.find('.save-start').text()).toBe('Save and start')
      expect(w.find('.cancel').text()).toBe('Cancel')
    })

    it('takes its funnel from the view, and falls back to offering something', () => {
      expect(make({ defaultFlow: 'reply' }).find('.f-flow').element.value).toBe(
        'reply'
      )
      expect(make({ defaultFlow: '' }).find('.f-flow').element.value).toBe(
        'give'
      )
      expect(
        make({ defaultFlow: 'nonsense' }).find('.f-flow').element.value
      ).toBe('give')
    })

    it('lists the seven funnels by their titles', () => {
      const options = make()
        .findAll('.f-flow option')
        .map((o) => o.text())
      expect(options).toEqual([
        'Offering something',
        'Asking for something',
        'Replying to a post',
        'Promising an item',
        'Marking taken or received',
        'Signing in or joining',
        'Settings',
      ])
    })
  })

  describe('the short name', () => {
    it('says it is found in the code when the member site has that switch', async () => {
      const w = make()
      await w.find('.f-name').setValue('give-photos-skip')
      expect(w.find('.hint-found').text()).toBe('Found in the code')
      expect(w.find('.hint-missing').exists()).toBe(false)
    })

    it('says everyone will see the old version until it is, when it is not found', async () => {
      const w = make()
      await w.find('.f-name').setValue('brand-new-idea')
      expect(w.find('.hint-missing').text()).toBe(
        'Not found in the code yet. Everyone will see the old version until it is.'
      )
      expect(w.find('.hint-found').exists()).toBe(false)
    })

    it('says nothing about the code until a name is typed', () => {
      const w = make()
      expect(w.find('.hint-found').exists()).toBe(false)
      expect(w.find('.hint-missing').exists()).toBe(false)
    })

    it('gives the rule, and no hint about the code, for a name that breaks it', async () => {
      const w = make()
      for (const bad of ['has space', 'bang!', 'a'.repeat(65), 'dot.dot']) {
        await w.find('.f-name').setValue(bad)
        expect(w.find('.name-rule').text(), bad).toBe(NAME_RULE)
        expect(w.find('.hint-found').exists(), bad).toBe(false)
        expect(w.find('.hint-missing').exists(), bad).toBe(false)
      }
    })

    it('refuses the three words that would be keys on the object the browser reads', async () => {
      const w = make()
      for (const bad of ['__proto__', 'constructor', 'prototype']) {
        await w.find('.f-name').setValue(bad)
        expect(w.find('.name-rule').text(), bad).toBe(NAME_RULE)
      }
    })

    it('accepts letters, digits, a hyphen and an underscore, up to 64 characters', async () => {
      const w = make()
      for (const ok of ['a', 'A1_b-2', 'x'.repeat(64)]) {
        await w.find('.f-name').setValue(ok)
        expect(w.find('.name-rule').exists(), ok).toBe(false)
      }
    })
  })

  describe('checking before it sends', () => {
    it('names every field that is missing, and sends nothing', async () => {
      const w = make()
      await w.find('.save-draft').trigger('click')
      expect(problems(w)).toEqual([
        'Add a short name.',
        'Add a title.',
        'Say what is wrong now, and why this should help.',
        'Say what the change does.',
        'Say what we expect to see.',
      ])
      expect(store.createExperiment).not.toHaveBeenCalled()
    })

    it('counts a field of spaces as missing', async () => {
      const w = make()
      await fill(w, { title: '   ' })
      await w.find('.save-draft').trigger('click')
      expect(problems(w)).toEqual(['Add a title.'])
      expect(store.createExperiment).not.toHaveBeenCalled()
    })

    it('refuses a name that breaks the rule', async () => {
      const w = make()
      await fill(w, { name: 'not valid!' })
      await w.find('.save-start').trigger('click')
      expect(problems(w)).toEqual([NAME_RULE])
      expect(store.createExperiment).not.toHaveBeenCalled()
    })

    it('refuses a title longer than 160 characters rather than cutting it', async () => {
      const w = make()
      await fill(w, { title: 'x'.repeat(161) })
      await w.find('.save-draft').trigger('click')
      expect(problems(w)).toEqual([
        'Keep the title to 160 characters or fewer.',
      ])
      expect(store.createExperiment).not.toHaveBeenCalled()
    })

    it('refuses a link address that does not start with https://, and sends nothing', async () => {
      const w = make()
      await fill(w)
      for (const bad of [
        'http://example.com',
        'javascript:alert(1)',
        'ftp://example.com',
        'github.com/Freegle/Iznik/pull/9',
      ]) {
        await w.find('.f-pr').setValue(bad)
        await w.find('.save-draft').trigger('click')
        expect(problems(w), bad).toEqual([
          'Use an address that starts with https://.',
        ])
      }
      expect(store.createExperiment).not.toHaveBeenCalled()
    })

    it('refuses a size of change outside 0.1 to 50 points, or no number', async () => {
      const w = make()
      await fill(w)
      for (const bad of ['0', '0.05', '51', '', 'abc']) {
        await w.find('.f-points').setValue(bad)
        await w.find('.save-draft').trigger('click')
        expect(problems(w), bad).toEqual(['Use a number from 0.1 to 50.'])
      }
      expect(store.createExperiment).not.toHaveBeenCalled()
    })

    it('clears the messages when the next try is right', async () => {
      const w = make()
      await w.find('.save-draft').trigger('click')
      expect(problems(w).length).toBeGreaterThan(0)
      await fill(w)
      await w.find('.save-draft').trigger('click')
      await flushPromises()
      expect(problems(w)).toEqual([])
    })
  })

  describe('which funnels it can affect', () => {
    it('shows its own funnel ticked and fixed, and the other six to choose from', () => {
      const w = make()
      const own = w.find('.touch-own input')
      expect(own.element.checked).toBe(true)
      expect(own.attributes('disabled')).toBeDefined()
      expect(w.find('.touch-own').text()).toContain('Offering something')
      const others = w.findAll('.touch-other')
      expect(others).toHaveLength(6)
      expect(others.map((o) => o.text())).not.toContain('Offering something')
    })

    it('ticks signing in to begin with, and nothing else', () => {
      const ticked = make()
        .findAll('.touch-other')
        .filter((o) => o.find('input').element.checked)
        .map((o) => o.text())
      expect(ticked).toEqual(['Signing in or joining'])
    })

    it('sends its own funnel and the ones ticked', async () => {
      const w = make()
      await fill(w)
      await w.find('.save-draft').trigger('click')
      expect(store.createExperiment.mock.calls[0][0].design.touches).toEqual([
        'give',
        'login',
      ])
    })

    it('follows the ticks', async () => {
      const w = make()
      await fill(w)
      const box = (label) =>
        w
          .findAll('.touch-other')
          .find((o) => o.text() === label)
          .find('input')
      await box('Signing in or joining').setValue(false)
      await box('Replying to a post').setValue(true)
      await w.find('.save-draft').trigger('click')
      expect(store.createExperiment.mock.calls[0][0].design.touches).toEqual([
        'give',
        'reply',
      ])
    })

    it('moves its own funnel out of the choices when another funnel is picked', async () => {
      const w = make()
      await w.find('.f-flow').setValue('login')
      expect(w.find('.touch-own').text()).toContain('Signing in or joining')
      expect(w.findAll('.touch-other')).toHaveLength(6)
      expect(w.findAll('.touch-other').map((o) => o.text())).toContain(
        'Offering something'
      )
      await fill(w)
      await w.find('.save-draft').trigger('click')
      expect(store.createExperiment.mock.calls[0][0].design.touches).toEqual([
        'login',
      ])
    })
  })

  describe('saving as a draft', () => {
    it('sends the body once, and asks to be closed on the new draft', async () => {
      const w = make()
      await fill(w)
      await w.find('.save-draft').trigger('click')
      await flushPromises()
      expect(store.createExperiment).toHaveBeenCalledTimes(1)
      expect(store.createExperiment).toHaveBeenCalledWith({
        flow: 'give',
        name: 'give-photos-skip',
        title: 'Make skipping photos obvious',
        hypothesis: 'People give up at the camera button.',
        change: 'A plain link above the camera button.',
        expected: 'More people reach the next step.',
        design: { touches: ['give', 'login'], expected_points: 5 },
      })
      expect(store.experimentAction).not.toHaveBeenCalled()
      expect(w.emitted('saved')).toEqual([[7, 'give']])
    })

    it('sends the link address and the size of change when they are given', async () => {
      const w = make()
      await fill(w, {
        points: '2.5',
        pr: 'https://github.com/Freegle/Iznik/pull/9',
      })
      await w.find('.save-draft').trigger('click')
      const body = store.createExperiment.mock.calls[0][0]
      expect(body.pr_url).toBe('https://github.com/Freegle/Iznik/pull/9')
      expect(body.design.expected_points).toBe(2.5)
    })

    it('trims spaces round a pasted link, which Go would otherwise refuse', async () => {
      const w = make()
      await fill(w, { pr: '  https://github.com/Freegle/Iznik/pull/9 ' })
      await w.find('.save-draft').trigger('click')
      expect(store.createExperiment.mock.calls[0][0].pr_url).toBe(
        'https://github.com/Freegle/Iznik/pull/9'
      )
    })

    it('leaves out the link when it is empty', async () => {
      const w = make()
      await fill(w)
      await w.find('.save-draft').trigger('click')
      expect('pr_url' in store.createExperiment.mock.calls[0][0]).toBe(false)
    })

    it('trims the words it sends', async () => {
      const w = make()
      await fill(w, { name: '  give-photos-skip  ', title: '  A title  ' })
      await w.find('.save-draft').trigger('click')
      const body = store.createExperiment.mock.calls[0][0]
      expect(body.name).toBe('give-photos-skip')
      expect(body.title).toBe('A title')
    })

    it('cannot be sent twice while it is on its way', async () => {
      const w = make()
      await fill(w)
      let finish
      store.createExperiment.mockReturnValue(new Promise((r) => (finish = r)))
      await w.find('.save-draft').trigger('click')
      expect(w.find('.save-draft').attributes('disabled')).toBeDefined()
      expect(w.find('.save-start').attributes('disabled')).toBeDefined()
      await w.find('.save-draft').trigger('click')
      expect(store.createExperiment).toHaveBeenCalledTimes(1)
      finish({ id: 7, status: 'draft' })
      await flushPromises()
      expect(w.find('.save-draft').attributes('disabled')).toBeUndefined()
    })
  })

  describe('saving and starting', () => {
    it('creates the draft, then asks the batch to start it, in that order', async () => {
      const w = make()
      await fill(w)
      await w.find('.save-start').trigger('click')
      await flushPromises()
      expect(store.createExperiment).toHaveBeenCalledTimes(1)
      expect(store.experimentAction).toHaveBeenCalledWith(7, {
        action: 'start',
      })
      expect(store.createExperiment.mock.invocationCallOrder[0]).toBeLessThan(
        store.experimentAction.mock.invocationCallOrder[0]
      )
      expect(w.emitted('saved')).toEqual([[7, 'give']])
    })

    it('records the start as requested, so the new row says so', async () => {
      const w = make()
      await fill(w)
      await w.find('.save-start').trigger('click')
      await flushPromises()
      expect(actions.pending({ id: 7, status: 'draft' })).toBe('start')
    })

    it('does not ask to start when the draft could not be saved', async () => {
      const w = make()
      await fill(w)
      store.createExperiment.mockRejectedValue(
        Object.assign(new Error('API Error'), {
          response: { status: 400, data: { message: 'title is required' } },
        })
      )
      await w.find('.save-start').trigger('click')
      await flushPromises()
      expect(store.experimentAction).not.toHaveBeenCalled()
      expect(w.emitted('saved')).toBeUndefined()
    })

    it('leaves the draft when the start is refused, and its row shows why', async () => {
      const w = make()
      await fill(w)
      store.experimentAction.mockRejectedValue(
        Object.assign(new Error('API Error'), {
          response: {
            status: 400,
            data: { message: 'Only a draft can be started' },
          },
        })
      )
      await w.find('.save-start').trigger('click')
      await flushPromises()
      // The draft exists, so the form closes on it.
      expect(w.emitted('saved')).toEqual([[7, 'give']])
      expect(actions.problem({ id: 7, status: 'draft' })).toBe(
        'Only a draft can be started'
      )
      expect(actions.pending({ id: 7, status: 'draft' })).toBeNull()
    })
  })

  describe('when the server says no', () => {
    const refusal = (status, message) =>
      Object.assign(new Error('API Error'), {
        response: { status, data: { error: status, message } },
      })

    it('shows its message under the form for a 400, and keeps what was typed', async () => {
      const w = make()
      await fill(w)
      store.createExperiment.mockRejectedValue(
        refusal(
          400,
          'name must be 1 to 64 letters, digits, _ or -, and not a reserved word'
        )
      )
      await w.find('.save-draft').trigger('click')
      await flushPromises()
      expect(w.find('.server-error').text()).toBe(
        'name must be 1 to 64 letters, digits, _ or -, and not a reserved word'
      )
      expect(w.find('.f-title').element.value).toBe(
        'Make skipping photos obvious'
      )
      expect(w.find('.save-draft').attributes('disabled')).toBeUndefined()
      expect(w.emitted('saved')).toBeUndefined()
    })

    it('shows its message for a 409, when the name is taken', async () => {
      const w = make()
      await fill(w)
      store.createExperiment.mockRejectedValue(
        refusal(409, 'An experiment with that name already exists')
      )
      await w.find('.save-draft').trigger('click')
      await flushPromises()
      expect(w.find('.server-error').text()).toBe(
        'An experiment with that name already exists'
      )
    })

    it('gives a plain message when the server said nothing useful', async () => {
      const w = make()
      await fill(w)
      store.createExperiment.mockRejectedValue(
        new Error('API Error POST /x -> status: 500')
      )
      await w.find('.save-draft').trigger('click')
      await flushPromises()
      expect(w.find('.server-error').text()).toBe('That did not work.')
    })

    it('clears the message on the next try', async () => {
      const w = make()
      await fill(w)
      store.createExperiment.mockRejectedValueOnce(refusal(409, 'Taken'))
      await w.find('.save-draft').trigger('click')
      await flushPromises()
      expect(w.find('.server-error').exists()).toBe(true)
      await w.find('.save-draft').trigger('click')
      await flushPromises()
      expect(w.find('.server-error').exists()).toBe(false)
    })
  })

  it('asks to be closed when cancelled, and sends nothing', async () => {
    const w = make()
    await w.find('.cancel').trigger('click')
    expect(w.emitted('cancel')).toHaveLength(1)
    expect(store.createExperiment).not.toHaveBeenCalled()
  })

  describe('cancelling while a save is on its way', () => {
    // The draft is made as soon as it is sent and cannot be taken back. The start is the step that
    // reaches members, so it is not asked for after Cancel.
    it('does not ask the batch to start the draft when Cancel was pressed before it was made', async () => {
      const w = make()
      await fill(w)
      let finish
      store.createExperiment.mockReturnValue(new Promise((r) => (finish = r)))
      await w.find('.save-start').trigger('click')
      expect(w.find('.cancel').attributes('disabled')).toBeUndefined()
      await w.find('.cancel').trigger('click')
      expect(w.emitted('cancel')).toHaveLength(1)

      finish({ id: 7, status: 'draft' })
      await flushPromises()
      expect(store.experimentAction).not.toHaveBeenCalled()
      expect(actions.pending({ id: 7, status: 'draft' })).toBeNull()
    })

    it('says nothing more to the section after Cancel, which has already closed the form', async () => {
      const w = make()
      await fill(w)
      let finish
      store.createExperiment.mockReturnValue(new Promise((r) => (finish = r)))
      await w.find('.save-start').trigger('click')
      await w.find('.cancel').trigger('click')
      finish({ id: 7, status: 'draft' })
      await flushPromises()
      expect(w.emitted('saved')).toBeUndefined()
    })

    it('lets the buttons go again after a save that was cancelled', async () => {
      const w = make()
      await fill(w)
      let finish
      store.createExperiment.mockReturnValue(new Promise((r) => (finish = r)))
      await w.find('.save-draft').trigger('click')
      await w.find('.cancel').trigger('click')
      finish({ id: 7, status: 'draft' })
      await flushPromises()
      expect(w.find('.save-draft').attributes('disabled')).toBeUndefined()
      expect(w.find('.save-start').attributes('disabled')).toBeUndefined()
    })

    it('does not remember an earlier Cancel when the same form is saved again', async () => {
      // Nothing closes the form in this test, so it can be saved a second time.
      const w = make()
      await fill(w)
      let finish
      store.createExperiment.mockReturnValueOnce(
        new Promise((r) => (finish = r))
      )
      await w.find('.save-start').trigger('click')
      await w.find('.cancel').trigger('click')
      finish({ id: 7, status: 'draft' })
      await flushPromises()
      expect(store.experimentAction).not.toHaveBeenCalled()

      store.createExperiment.mockResolvedValue({ id: 8, status: 'draft' })
      await w.find('.save-start').trigger('click')
      await flushPromises()
      expect(store.experimentAction).toHaveBeenCalledWith(8, {
        action: 'start',
      })
      expect(w.emitted('saved')).toEqual([[8, 'give']])
    })

    it('still makes the draft when Save as draft is cancelled, because it was already sent', async () => {
      const w = make()
      await fill(w)
      await w.find('.save-draft').trigger('click')
      await w.find('.cancel').trigger('click')
      await flushPromises()
      expect(store.createExperiment).toHaveBeenCalledTimes(1)
      expect(store.experimentAction).not.toHaveBeenCalled()
    })

    it('does not offer Cancel once the start is on its way, because that can no longer be stopped', async () => {
      const w = make()
      await fill(w)
      let finish
      store.experimentAction.mockReturnValue(new Promise((r) => (finish = r)))
      await w.find('.save-start').trigger('click')
      await flushPromises()
      expect(store.experimentAction).toHaveBeenCalledTimes(1)
      expect(w.find('.cancel').attributes('disabled')).toBeDefined()
      await w.find('.cancel').trigger('click')
      expect(w.emitted('cancel')).toBeUndefined()

      finish({ queued: true })
      await flushPromises()
      expect(w.emitted('saved')).toEqual([[7, 'give']])
      expect(w.find('.cancel').attributes('disabled')).toBeUndefined()
    })

    it('is on offer all the time before anything is sent', async () => {
      const w = make()
      expect(w.find('.cancel').attributes('disabled')).toBeUndefined()
      await fill(w)
      expect(w.find('.cancel').attributes('disabled')).toBeUndefined()
    })
  })
})
