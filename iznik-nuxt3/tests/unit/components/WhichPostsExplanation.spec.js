import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import WhichPostsExplanation from '~/components/WhichPostsExplanation.vue'

describe('WhichPostsExplanation', () => {
  function createWrapper() {
    return mount(WhichPostsExplanation)
  }

  it('explains the browse filters', () => {
    const wrapper = createWrapper()
    const text = wrapper.text()
    expect(text).toContain('Show posts from')
    expect(text).toContain('Show these posts')
    expect(text).toContain('Sort by')
    expect(text).toContain('distance slider')
  })

  it('mentions relevance ordering (most relevant posts first)', () => {
    const wrapper = createWrapper()
    expect(wrapper.text()).toContain('most relevant posts first')
  })

  it('tells members an out-of-reach reply is held briefly (not blocked) and then passed on', () => {
    const wrapper = createWrapper()
    const text = wrapper.text().replace(/\s+/g, ' ')
    // Rippling-out hold: you can always reply; a reply to a post that hasn't reached you yet is
    // held for a bounded spell so nearer people get first go, then passed on either way. Assert
    // the hold BEHAVIOUR, not brittle exact wording, so the test survives minor copy tweaks.
    expect(text).toContain('go ahead and reply')
    expect(text).toContain('pass yours on')
    expect(text).toMatch(/first go/i)
  })

  it('does not promise delivery only once the post reaches you', () => {
    const wrapper = createWrapper()
    const text = wrapper.text().replace(/\s+/g, ' ')
    // The hold ends on a timer as well as on coverage, and most held repliers are somewhere
    // the ripple never reaches. "The moment the post reaches you" was a promise we don't keep.
    expect(text).not.toMatch(/the moment the post reaches you/i)
  })

  it('does not use "this is new / we have changed" framing', () => {
    const wrapper = createWrapper()
    const text = wrapper.text().toLowerCase()
    expect(text).not.toContain('new change')
    expect(text).not.toContain('we have changed')
    expect(text).not.toContain('this is new')
  })
})
