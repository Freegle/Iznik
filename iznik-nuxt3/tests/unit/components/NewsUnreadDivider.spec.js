import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import NewsUnreadDivider from '~/components/NewsUnreadDivider.vue'

describe('NewsUnreadDivider', () => {
  it('renders the plural label', () => {
    const wrapper = mount(NewsUnreadDivider, { props: { count: 3 } })
    expect(wrapper.text()).toContain('3 new replies since your last visit')
  })

  it('renders the singular label', () => {
    const wrapper = mount(NewsUnreadDivider, { props: { count: 1 } })
    expect(wrapper.text()).toContain('1 new reply since your last visit')
  })

  it('is exposed to assistive tech as a labelled separator', () => {
    const wrapper = mount(NewsUnreadDivider, { props: { count: 2 } })
    const root = wrapper.find('[data-unread-divider]')
    expect(root.exists()).toBe(true)
    expect(root.attributes('role')).toBe('separator')
    expect(root.attributes('aria-label')).toContain('2 new replies')
  })
})
