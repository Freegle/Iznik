import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import NewsPreviews from '~/components/NewsPreviews.vue'

describe('NewsPreviews', () => {
  const mockPreviews = [
    { id: 1, title: 'Preview 1' },
    { id: 2, title: 'Preview 2' },
    { id: 3, title: 'Preview 3' },
  ]

  function createWrapper(props = {}) {
    return mount(NewsPreviews, {
      props: { previews: mockPreviews, ...props },
      global: {
        stubs: {
          NewsPreview: {
            template:
              '<div class="news-preview" :data-id="preview.id" :data-size="size"></div>',
            props: ['preview', 'size'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    /* A post with several links used to draw a full preview for each, which
       pushed the rest of the feed off the screen. */
    it('shows only the first preview to begin with', () => {
      const wrapper = createWrapper()
      const previews = wrapper.findAll('.news-preview')
      expect(previews).toHaveLength(1)
      expect(previews[0].attributes('data-id')).toBe('1')
    })

    it('offers to show the rest, saying how many', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.show-more-previews').text()).toBe(
        'Show 2 more links'
      )
    })

    it('says "link" when one is hidden', () => {
      const wrapper = createWrapper({ previews: mockPreviews.slice(0, 2) })
      expect(wrapper.find('.show-more-previews').text()).toBe(
        'Show 1 more link'
      )
    })

    it('has no button for a single preview', () => {
      const wrapper = createWrapper({ previews: mockPreviews.slice(0, 1) })
      expect(wrapper.findAll('.news-preview')).toHaveLength(1)
      expect(wrapper.find('.show-more-previews').exists()).toBe(false)
    })

    it('shows every preview once asked, and drops the button', async () => {
      const wrapper = createWrapper()
      await wrapper.find('.show-more-previews').trigger('click')
      const previews = wrapper.findAll('.news-preview')
      expect(previews[0].attributes('data-id')).toBe('1')
      expect(previews[1].attributes('data-id')).toBe('2')
      expect(previews[2].attributes('data-id')).toBe('3')
      expect(wrapper.find('.show-more-previews').exists()).toBe(false)
    })

    it('announces revealed previews to a screen reader', () => {
      const wrapper = createWrapper()
      expect(wrapper.attributes('aria-live')).toBe('polite')
    })

    it('uses default size md', () => {
      const wrapper = createWrapper()
      const preview = wrapper.find('.news-preview')
      expect(preview.attributes('data-size')).toBe('md')
    })

    it('passes custom size to NewsPreview', () => {
      const wrapper = createWrapper({ size: 'lg' })
      const preview = wrapper.find('.news-preview')
      expect(preview.attributes('data-size')).toBe('lg')
    })
  })

  describe('props', () => {
    it('previews prop is Array type', () => {
      const props = NewsPreviews.props
      expect(props.previews.type).toBe(Array)
    })

    it('size prop defaults to md', () => {
      const props = NewsPreviews.props
      expect(props.size.default).toBe('md')
    })
  })

  describe('empty state', () => {
    it('renders nothing when previews is empty', () => {
      const wrapper = createWrapper({ previews: [] })
      expect(wrapper.findAll('.news-preview')).toHaveLength(0)
    })
  })
})
