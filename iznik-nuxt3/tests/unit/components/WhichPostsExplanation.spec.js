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

  it('makes the reply caveat conditional on changing from the default view', () => {
    const wrapper = createWrapper()
    const text = wrapper.text()
    expect(text).toContain('On the default view')
    expect(text).toContain(
      "you'll only see posts that have already reached your area"
    )
    expect(text).toContain('widen the distance, change the sort')
  })

  it('does not use "this is new / we have changed" framing', () => {
    const wrapper = createWrapper()
    const text = wrapper.text().toLowerCase()
    expect(text).not.toContain('new change')
    expect(text).not.toContain('we have changed')
    expect(text).not.toContain('this is new')
  })
})
