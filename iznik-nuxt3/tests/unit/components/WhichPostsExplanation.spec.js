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

  it('tells members an out-of-reach reply is held (not blocked) and passed on when it reaches them', () => {
    const wrapper = createWrapper()
    const text = wrapper.text()
    // Rippling-out hold: you can always reply; a reply to a post that hasn't reached you yet is
    // held and delivered when it does. Assert the hold BEHAVIOUR, not brittle exact wording, so
    // the test survives minor copy tweaks.
    expect(text).toContain('go ahead and reply')
    expect(text).toContain('pass it on to the owner')
    expect(text).toMatch(/reaches you/i)
  })

  it('does not use "this is new / we have changed" framing', () => {
    const wrapper = createWrapper()
    const text = wrapper.text().toLowerCase()
    expect(text).not.toContain('new change')
    expect(text).not.toContain('we have changed')
    expect(text).not.toContain('this is new')
  })
})
