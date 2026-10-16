import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxIdeaRow from '~/modtools/components/ModSysAdminUxIdeaRow.vue'

// The registry is F's. A fake one says which sections there are, as the tab would.
const registry = vi.hoisted(() => ({ keys: ['overview', 'funnels', 'pages'] }))
vi.mock('~/modtools/composables/useUxSections', () => ({
  uxSections: () => registry.keys.map((key) => ({ key })),
}))

const idea = (over = {}) => ({
  id: 3,
  flow: 'give',
  title: 'Ask to sign in first',
  rationale: 'People stall at photos.',
  expected: 'More people finish.',
  evidence: {
    category: 'copy',
    expected_points: 4,
    confidence: 0.5,
    locality: 5,
    risk: 'Fewer photos.',
    files: ['iznik-nuxt3/components/PostPhoto.vue'],
  },
  score: 8.25,
  status: 'open',
  type: 'experiment',
  source: 'ai',
  problem_id: 12,
  problem_title: 'The title the idea kept',
  problem: { id: 12, title: 'Photos will not upload' },
  attempts: 0,
  experiment: {
    id: 4,
    name: 'give-photos-skip',
    title: 'Make skipping photos obvious',
    status: 'running',
  },
  created_at: '2026-10-14 09:00:00',
  ...over,
})

const hrefFor = (patch) =>
  `/sysadmin?${new URLSearchParams(
    Object.fromEntries(
      Object.entries(patch).map(([k, v]) => [
        k === 'section' ? 'ux_section' : `ux_${k}`,
        v,
      ])
    )
  )}`

describe('ModSysAdminUxIdeaRow', () => {
  beforeEach(() => {
    registry.keys = ['overview', 'funnels', 'pages']
  })

  const make = (props = {}, provide = { uxHrefFor: hrefFor }) =>
    mount(ModSysAdminUxIdeaRow, {
      props: { idea: idea(), ...props },
      global: { provide },
    })

  describe('the row', () => {
    it('is one button with the score, the type, the title and the status', () => {
      const w = make()
      const button = w.find('button.ux-idea-row')
      expect(w.findAll('button')).toHaveLength(1)
      expect(button.find('.score').text()).toContain('8.25')
      expect(button.find('.type').text()).toBe('Experiment')
      expect(button.find('.title').text()).toBe('Ask to sign in first')
      expect(button.find('.status').text()).toBe('Waiting')
    })

    it('says in words that the number is the score', () => {
      expect(make().find('.score').text()).toBe('Score 8.25')
    })

    it('shows that word to everybody, and does not hide it for a screen reader alone', () => {
      const score = make().find('.score')
      expect(score.find('.score-label').text()).toBe('Score')
      expect(score.find('.score-value').text()).toBe('8.25')
      expect(score.find('.visually-hidden').exists()).toBe(false)
      expect(score.find('.score-label').classes()).not.toContain(
        'visually-hidden'
      )
      expect(
        score.find('.score-label').attributes('aria-hidden')
      ).toBeUndefined()
    })

    it('has a word for every status', () => {
      const words = {
        open: 'Waiting',
        scheduled: 'Queued',
        running: 'Being tried',
        done: 'Tried',
        rejected: 'Set aside',
      }
      for (const [status, word] of Object.entries(words)) {
        const w = make({ idea: idea({ status }) })
        expect(w.find('.status').text(), status).toBe(word)
      }
    })

    it('marks a fix apart from an experiment', () => {
      const fix = make({ idea: idea({ type: 'fix' }) }).find('.type')
      expect(fix.text()).toBe('Fix')
      expect(fix.classes()).toContain('ux-fix')
      const test = make().find('.type')
      expect(test.classes()).not.toContain('ux-fix')
    })

    it('names the funnel only when asked to', () => {
      expect(make().find('.funnel').exists()).toBe(false)
      expect(make().find('button').classes()).not.toContain('with-funnel')
      const w = make({ showFunnel: true })
      expect(w.find('.funnel').text()).toBe('Offering')
      expect(w.find('button').classes()).toContain('with-funnel')
    })

    it('is closed by default and says so', () => {
      const w = make()
      expect(w.find('button').attributes('aria-expanded')).toBe('false')
      expect(w.find('.ux-idea-detail').exists()).toBe(false)
      expect(w.find('button').classes()).not.toContain('open')
    })

    it('asks to be opened or closed when it is pressed', async () => {
      const w = make()
      await w.find('button').trigger('click')
      expect(w.emitted('toggle')).toHaveLength(1)
    })
  })

  describe('the reasons below it', () => {
    it('are drawn when it is open, in the order why, what we expect, evidence, problem, experiment', () => {
      const w = make({ open: true, controls: 'ux-idea-3' })
      expect(w.find('button').attributes('aria-expanded')).toBe('true')
      expect(w.find('button').attributes('aria-controls')).toBe('ux-idea-3')
      const detail = w.find('.ux-idea-detail')
      expect(detail.attributes('id')).toBe('ux-idea-3')
      expect(detail.findAll('h6').map((h) => h.text())).toEqual([
        'Why',
        'What we expect',
        'Evidence',
        'The problem it answers',
        'The experiment it became',
      ])
      expect(detail.find('.why').text()).toContain('People stall at photos.')
      expect(detail.find('.expect').text()).toContain('More people finish.')
    })

    it('start with what changes, apart from why, when the generator wrote the change', () => {
      const w = make({
        open: true,
        idea: idea({
          evidence: {
            ...idea().evidence,
            change: 'A plain link above the camera button.',
          },
        }),
      })
      const detail = w.find('.ux-idea-detail')
      expect(detail.findAll('h6').map((h) => h.text())).toEqual([
        'What changes',
        'Why',
        'What we expect',
        'Evidence',
        'The problem it answers',
        'The experiment it became',
      ])
      expect(detail.find('.change').text()).toContain(
        'A plain link above the camera button.'
      )
      // The reason is the reason alone: it does not begin with the change, or run on from it.
      expect(detail.find('.why').text()).toContain('People stall at photos.')
      expect(detail.find('.why').text()).not.toContain('camera button')
    })

    it('leave out what changes when the generator kept no change', () => {
      for (const change of [undefined, '', '   ', 4]) {
        const w = make({
          open: true,
          idea: idea({ evidence: { ...idea().evidence, change } }),
        })
        expect(w.find('.change').exists(), String(change)).toBe(false)
        expect(w.findAll('h6').map((h) => h.text())[0]).toBe('Why')
      }
    })

    it('ends with where the idea came from, in words a reader knows', () => {
      const w = make({ open: true })
      expect(w.find('.source').text()).toBe('From the idea generator')
      expect(w.find('.source').text()).not.toMatch(/loop/i)
    })

    it('prints any other source as it is', () => {
      for (const source of ['human', 'member', 'survey']) {
        const w = make({ open: true, idea: idea({ source }) })
        expect(w.find('.source').text(), source).toBe(source)
      }
    })

    it('writes the evidence in words', () => {
      const evidence = make({ open: true }).find('.evidence')
      expect(evidence.find('.line-category').text()).toBe(
        'Kind of change: Wording'
      )
      expect(evidence.find('.line-points').text()).toBe(
        'Expected gain: 4 more people finish in every 100 who start'
      )
      expect(evidence.find('.line-confidence').text()).toBe('How sure: 50%')
      expect(evidence.find('.line-locality').text()).toBe(
        'How small the change is: 5 of 5'
      )
      expect(evidence.find('.line-risk').text()).toBe('Risk: Fewer photos.')
      expect(evidence.find('.line-files').text()).toBe(
        'Files: iznik-nuxt3/components/PostPhoto.vue'
      )
    })

    it('says one person in the singular in the expected gain', () => {
      const w = make({
        open: true,
        idea: idea({ evidence: { category: 'copy', expected_points: 1 } }),
      })
      expect(w.find('.line-points').text()).toBe(
        'Expected gain: 1 more person finishes in every 100 who start'
      )
      expect(w.find('.line-points').text()).not.toContain('1 points')
    })

    it('lists a few files and says how many more', () => {
      const files = Array.from({ length: 8 }, (_, i) => `file${i}.vue`)
      const w = make({
        open: true,
        idea: idea({ evidence: { category: 'copy', files } }),
      })
      expect(w.find('.file-list').text()).toBe(files.slice(0, 5).join(', '))
      expect(w.find('.more-files').text()).toBe(', and 3 more')
      expect(w.find('.line-files').text()).not.toContain('file5.vue')
    })

    it('says so when the generator kept no figures', () => {
      for (const evidence of [null, {}, 'text', undefined]) {
        const w = make({ open: true, idea: idea({ evidence }) })
        expect(w.find('.evidence .none').text(), String(evidence)).toBe(
          'The idea generator kept no figures for this one.'
        )
      }
    })

    it('draws nothing for an experiment the idea has not become', () => {
      const w = make({ open: true, idea: idea({ experiment: null }) })
      expect(w.find('.experiment').exists()).toBe(false)
      expect(w.findAll('h6').map((h) => h.text())).not.toContain(
        'The experiment it became'
      )
    })

    it('shows the experiment’s title and its status in words', () => {
      const w = make({ open: true })
      expect(w.find('.experiment-title').text()).toBe(
        'Make skipping photos obvious'
      )
      expect(w.find('.experiment-status').text()).toBe('Running')
      for (const [status, word] of Object.entries({
        draft: 'Not started',
        adopted: 'Kept',
        reverted: 'Taken off',
        inconclusive: 'Could not tell',
        stopped: 'Stopped',
      })) {
        const x = make({
          open: true,
          idea: idea({ experiment: { id: 4, title: 'T', status } }),
        })
        expect(x.find('.experiment-status').text(), status).toBe(word)
      }
    })

    it('says an adopted experiment is kept for now while its holdout lasts, as the Experiments section does', () => {
      const x = make({
        open: true,
        idea: idea({
          experiment: {
            id: 4,
            title: 'T',
            status: 'adopted',
            holdout_until: '2026-10-30 09:00:00',
          },
        }),
      })
      expect(x.find('.experiment-status').text()).toBe('Kept for now')
    })
  })

  describe('the problem it answers', () => {
    it('is the member problem’s own title when the idea is tied to one', () => {
      const w = make({ open: true })
      expect(w.find('.problem-title').text()).toBe('Photos will not upload')
    })

    it('is the generator’s own title for the problem when no member problem is joined', () => {
      const w = make({ open: true, idea: idea({ problem: null }) })
      expect(w.find('.problem-title').text()).toBe('The title the idea kept')
    })

    it('draws no block when there is no title at all', () => {
      const w = make({
        open: true,
        idea: idea({ problem: null, problem_title: null, problem_id: null }),
      })
      expect(w.find('.problem').exists()).toBe(false)
      expect(w.findAll('h6').map((h) => h.text())).not.toContain(
        'The problem it answers'
      )
    })
  })

  describe('the link to what members said', () => {
    it('is drawn only when that section is there', () => {
      expect(make({ open: true }).find('.problem-link').exists()).toBe(false)
      registry.keys = ['overview', 'members']
      const link = make({ open: true }).find('.problem-link')
      expect(link.text()).toBe('See what members said')
      expect(link.attributes('href')).toBe(
        '/sysadmin?ux_section=members&ux_problem=12'
      )
    })

    it('is not drawn for an idea with no member problem, even when the section is there', () => {
      registry.keys = ['members']
      const w = make({
        open: true,
        idea: idea({ problem: null, problem_id: null }),
      })
      expect(w.find('.problem-link').exists()).toBe(false)
      expect(w.find('.problem-title').exists()).toBe(true)
    })

    it('moves within the tab on a plain click and leaves a click with a modifier to the browser', async () => {
      registry.keys = ['members']
      const w = make({ open: true })
      const link = w.find('.problem-link')
      await link.trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ section: 'members', problem: '12' }],
      ])
      await link.trigger('click', { ctrlKey: true })
      await link.trigger('click', { metaKey: true })
      await link.trigger('click', { button: 1 })
      expect(w.emitted('navigate')).toHaveLength(1)
    })

    it('prevents the browser from following a plain click, and not a click with a modifier', async () => {
      registry.keys = ['members']
      const w = make({ open: true })
      const plain = new MouseEvent('click', { bubbles: true, cancelable: true })
      w.find('.problem-link').element.dispatchEvent(plain)
      expect(plain.defaultPrevented).toBe(true)
      const modified = new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        ctrlKey: true,
      })
      w.find('.problem-link').element.dispatchEvent(modified)
      expect(modified.defaultPrevented).toBe(false)
    })
  })

  describe('the link to the experiment', () => {
    it('is drawn only when the Experiments section is there', () => {
      expect(make({ open: true }).find('.experiment-link').exists()).toBe(false)
      registry.keys = ['overview', 'experiments']
      const link = make({ open: true }).find('.experiment-link')
      expect(link.text()).toBe('Open the experiment')
      expect(link.attributes('href')).toBe(
        '/sysadmin?ux_section=experiments&ux_exp=4'
      )
    })

    it('moves within the tab on a plain click', async () => {
      registry.keys = ['experiments']
      const w = make({ open: true })
      await w.find('.experiment-link').trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ section: 'experiments', exp: '4' }],
      ])
    })

    it('is not drawn when the idea has no experiment', () => {
      registry.keys = ['experiments']
      const w = make({ open: true, idea: idea({ experiment: null }) })
      expect(w.find('.experiment-link').exists()).toBe(false)
    })

    it('does not fail outside the tab, where there is no way to get an address', () => {
      registry.keys = ['experiments', 'members']
      const w = make({ open: true }, {})
      expect(w.find('.experiment-link').attributes('href')).toBe('#')
      expect(w.find('.problem-link').attributes('href')).toBe('#')
    })
  })
})
