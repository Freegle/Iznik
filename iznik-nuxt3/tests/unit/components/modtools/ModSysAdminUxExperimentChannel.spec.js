import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxExperimentChannel from '~/modtools/components/ModSysAdminUxExperimentChannel.vue'

const experiment = (over = {}) => ({
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
    copy: { items: 'Items near you', control: 'Your daily digest' },
  },
  result: {
    arms: { control: { starts: 3 }, items: { starts: 3 } },
    detail: {
      exposed: { control: 12500, items: 12512 },
      counted: { control: 12389, items: 12402 },
    },
  },
  ...over,
})

const make = (exp) =>
  mount(ModSysAdminUxExperimentChannel, { props: { experiment: exp } })

describe('ModSysAdminUxExperimentChannel', () => {
  it('draws nothing for a change to the site', () => {
    const w = make(experiment({ design: { touches: ['reply'] }, result: null }))
    expect(w.find('.ux-exp-channel').exists()).toBe(false)
    expect(w.html()).not.toContain('experiment')
    expect(
      make(experiment({ design: null }))
        .find('section')
        .exists()
    ).toBe(false)
    expect(
      make(experiment({ design: { channel: 'web' } }))
        .find('section')
        .exists()
    ).toBe(false)
  })

  it('names the channel and the funnel it feeds', () => {
    const w = make(experiment())
    expect(w.find('.ux-exp-h').text()).toBe('Email experiment')
    expect(w.find('.funnel').text()).toBe('Funnel: Replying to a post.')
    expect(
      make(experiment({ design: { channel: 'push', type: 'p' } }))
        .find('.ux-exp-h')
        .text()
    ).toBe('Push experiment')
    expect(
      make(experiment({ design: { channel: 'server' } }))
        .find('.ux-exp-h')
        .text()
    ).toBe('Server experiment')
  })

  it('shows the text each version shows, control first', () => {
    const lines = make(experiment())
      .findAll('.copy li')
      .map((l) => l.text())
    expect(lines).toEqual([
      'Control: Your daily digest',
      'Items: Items near you',
    ])
  })

  it('shows plain text only: markup in the text is not drawn as markup', () => {
    const w = make(
      experiment({
        design: {
          channel: 'email',
          copy: { control: '<b>bold</b> <img src=x onerror=alert(1)>' },
        },
      })
    )
    expect(w.find('.copy li').text()).toBe(
      'Control: <b>bold</b> <img src=x onerror=alert(1)>'
    )
    expect(w.find('.copy b').exists()).toBe(false)
    expect(w.find('.copy img').exists()).toBe(false)
  })

  it('says how long members are reached and when it is decided', () => {
    expect(make(experiment()).find('.serving').text()).toBe(
      'Served until 21 Oct 2026, 10:00 UTC. Decision on 22 Oct 2026, 10:00 UTC.'
    )
    expect(
      make(experiment({ status: 'adopted' }))
        .find('.serving')
        .text()
    ).toBe('Served until 21 Oct 2026, 10:00 UTC.')
  })

  it('says how many were exposed and how many were counted, and why they differ', () => {
    const w = make(experiment())
    // In the words and the order of the read-out: the change first, then the old version.
    expect(w.find('.exposed').text()).toBe(
      'Exposed: 12,512 with the change, 12,500 without.'
    )
    expect(w.find('.counted').text()).toBe(
      'Counted: 12,402 with the change, 12,389 without.'
    )
    expect(w.find('.gloss').text()).toBe(
      'Exposed: members it has reached so far. Counted: members it first reached at least a day ago, so they have had time to act.'
    )
  })

  it('names each version when there are more than two', () => {
    const w = make(
      experiment({
        result: {
          detail: {
            exposed: { control: 4100, b: 4090, c: 4110 },
            counted: { control: 4000, b: 3990, c: 4010 },
          },
        },
      })
    )
    expect(w.find('.exposed').text()).toBe(
      'Exposed: control 4,100, b 4,090, c 4,110.'
    )
    expect(w.find('.counted').text()).toBe(
      'Counted: control 4,000, b 3,990, c 4,010.'
    )
  })

  it('says reached, not sent, because server code sends nothing', () => {
    const w = make(
      experiment({
        design: { channel: 'server', type: 'confirm', window_hours: 24 },
      })
    )
    expect(w.find('.gloss').text()).not.toMatch(/\bsent\b/i)
    expect(w.find('.gloss').text()).toContain('reached')
  })

  it("names a window in the batch's words", () => {
    const gloss = (hours) =>
      make(
        experiment({
          design: { channel: 'email', window_hours: hours },
        })
      )
        .find('.gloss')
        .text()
    expect(gloss(168)).toContain('at least 7 days ago')
    expect(gloss(36)).toContain('at least 36 hours ago')
    expect(gloss(undefined)).toContain('long enough ago')
  })

  it('draws the text of each version before it starts, and no counts', () => {
    const w = make(
      experiment({
        status: 'draft',
        started_at: null,
        ends_at: null,
        result: null,
      })
    )
    expect(w.find('.copy').text()).toContain('Control: Your daily digest')
    expect(w.find('.serving').exists()).toBe(false)
    expect(w.find('.exposed').exists()).toBe(false)
    expect(w.find('.counted').exists()).toBe(false)
    expect(w.find('.gloss').exists()).toBe(false)
  })

  it('has no list of versions when the design gave no text', () => {
    const w = make(
      experiment({ design: { channel: 'email', type: 'x' }, result: null })
    )
    expect(w.find('.copy').exists()).toBe(false)
    expect(w.find('.ux-exp-h').text()).toBe('Email experiment')
  })
})
