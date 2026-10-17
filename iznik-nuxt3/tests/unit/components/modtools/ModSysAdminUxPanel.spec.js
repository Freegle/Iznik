import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import { uxStubs } from './uxStubs'

const make = (props) =>
  mount(ModSysAdminUxPanel, {
    props: { title: 'My panel', explanation: 'What this means.', ...props },
    slots: { default: '<div class="body">content</div>' },
    global: { stubs: uxStubs },
  })

describe('ModSysAdminUxPanel', () => {
  it('shows the title, explanation and content', () => {
    const w = make({})
    expect(w.text()).toContain('My panel')
    expect(w.text()).toContain('What this means.')
    expect(w.find('.body').exists()).toBe(true)
  })

  it('shows a spinner and hides the content while loading', () => {
    const w = make({ loading: true })
    expect(w.find('.spinner').exists()).toBe(true)
    expect(w.find('.body').exists()).toBe(false)
  })

  it('shows the error in a danger notice and hides the content', () => {
    const w = make({ error: 'boom' })
    expect(w.find('.notice-message.danger').text()).toContain('boom')
    expect(w.find('.body').exists()).toBe(false)
  })

  it('shows the empty text when empty', () => {
    const w = make({ empty: true, emptyText: 'Nothing yet.' })
    expect(w.text()).toContain('Nothing yet.')
    expect(w.find('.body').exists()).toBe(false)
  })

  it('still shows the explanation in every state', () => {
    expect(make({ loading: true }).text()).toContain('What this means.')
    expect(make({ error: 'x' }).text()).toContain('What this means.')
    expect(make({ empty: true }).text()).toContain('What this means.')
  })

  it('has a plain heading and no button when it does not collapse', () => {
    const w = make({})
    expect(w.find('h4').text()).toBe('My panel')
    expect(w.find('button').exists()).toBe(false)
  })

  describe('a panel that opens and closes', () => {
    const collapsible = { collapsible: true, summary: 'Three things to know.' }

    it('is closed with one summary line', () => {
      const w = make(collapsible)
      expect(w.find('.ux-summary').text()).toBe('Three things to know.')
      expect(w.find('.body').exists()).toBe(false)
    })

    it('does not put the explanation or the content in the page while closed', () => {
      const w = make(collapsible)
      expect(w.text()).not.toContain('What this means.')
      expect(w.html()).not.toContain('content')
    })

    it('has a heading button that says whether it is open and what it controls', () => {
      const closed = make(collapsible)
      const button = closed.find('h4 > button')
      expect(button.text()).toBe('My panel')
      expect(button.attributes('aria-expanded')).toBe('false')
      const id = button.attributes('aria-controls')
      expect(id).toBeTruthy()
      expect(closed.find(`#${id}`).exists()).toBe(true)

      const open = make({ ...collapsible, open: true })
      expect(open.find('h4 > button').attributes('aria-expanded')).toBe('true')
      expect(open.find(`#${id}`).exists() || open.find('[id]').exists()).toBe(
        true
      )
    })

    it('asks the parent to open and close it, since the parent keeps that state', async () => {
      const w = make(collapsible)
      await w.find('h4 > button').trigger('click')
      expect(w.emitted('toggle')).toHaveLength(1)
      // Still closed: only the parent can open it.
      expect(w.find('.body').exists()).toBe(false)
    })

    it('shows the explanation and the content when the parent says it is open', () => {
      const w = make({ ...collapsible, open: true })
      expect(w.text()).toContain('What this means.')
      expect(w.find('.body').exists()).toBe(true)
      expect(w.find('.ux-summary').exists()).toBe(false)
    })

    it('is held open when it is told to be, whatever the parent says', () => {
      const w = make({ ...collapsible, forceOpen: true })
      expect(w.find('.body').exists()).toBe(true)
      expect(w.find('h4 > button').attributes('aria-expanded')).toBe('true')
    })

    it('opens itself to show an error, so a failure is never hidden', () => {
      const w = make({ ...collapsible, error: 'boom' })
      expect(w.find('.notice-message.danger').text()).toContain('boom')
      expect(w.find('h4 > button').attributes('aria-expanded')).toBe('true')
    })

    it('reads Loading... while loading, whatever the summary says', () => {
      expect(
        make({ ...collapsible, loading: true })
          .find('.ux-summary')
          .text()
      ).toBe('Loading...')
    })

    it('reads the empty text when there is nothing to show', () => {
      const w = make({
        ...collapsible,
        empty: true,
        emptyText: 'Nothing to report.',
      })
      expect(w.find('.ux-summary').text()).toBe('Nothing to report.')
    })

    it('shows its notes under the heading, closed or open', () => {
      const notes = ['Not filtered by device. Shows every device.']
      const closed = make({ ...collapsible, notes })
      expect(closed.find('.ux-note').text()).toBe(notes[0])
      expect(closed.find('.body').exists()).toBe(false)
      const open = make({ ...collapsible, notes, open: true })
      expect(open.find('.ux-note').text()).toBe(notes[0])
    })

    it('draws no note when it has none', () => {
      expect(make(collapsible).find('.ux-note').exists()).toBe(false)
    })
  })

  describe('a part the server does not have yet', () => {
    it('says it is not switched on, and shows no danger notice', () => {
      const w = make({ missing: true })
      expect(w.text()).toContain('This part is not switched on yet.')
      expect(w.find('.notice-message').exists()).toBe(false)
      expect(w.find('.body').exists()).toBe(false)
    })

    it('says the same on its closed line', () => {
      const w = make({ collapsible: true, missing: true, summary: 'ignored' })
      expect(w.find('.ux-summary').text()).toBe(
        'This part is not switched on yet.'
      )
    })

    it('does not draw the content', () => {
      expect(make({ missing: true, open: true }).find('.body').exists()).toBe(
        false
      )
    })
  })
})
