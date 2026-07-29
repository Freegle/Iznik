import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense } from 'vue'
import NotificationMatchedPost from '~/components/NotificationMatchedPost.vue'

const { mockNotification, mockNotificationago } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockNotification: ref({
      id: 1,
      title: 'Freegle matches for you',
      text: '3 posts near you match what you’ve offered or asked for',
    }),
    mockNotificationago: ref('5 minutes ago'),
  }
})

vi.mock('~/composables/useNotification', () => ({
  setupNotification: vi.fn(() =>
    Promise.resolve({
      notification: mockNotification,
      notificationago: mockNotificationago,
    })
  ),
}))

describe('NotificationMatchedPost', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockNotification.value = {
      id: 1,
      title: 'Freegle matches for you',
      text: '3 posts near you match what you’ve offered or asked for',
    }
    mockNotificationago.value = '5 minutes ago'
  })

  async function createWrapper(props = {}) {
    const TestWrapper = defineComponent({
      setup() {
        return () =>
          h(Suspense, null, {
            default: () => h(NotificationMatchedPost, { id: 1, ...props }),
            fallback: () => h('div', 'Loading...'),
          })
      },
    })

    const wrapper = mount(TestWrapper, {
      global: {
        stubs: {
          ProfileImage: {
            template:
              '<div class="profile-image" :data-image="image" :data-size="size" />',
            props: ['image', 'isThumbnail', 'size'],
          },
        },
      },
    })

    await flushPromises()
    return wrapper
  }

  it('renders the container and icon', async () => {
    const wrapper = await createWrapper()
    expect(wrapper.find('.clickme').exists()).toBe(true)
    const img = wrapper.find('.profile-image')
    expect(img.attributes('data-image')).toBe('/icon.png')
    expect(img.attributes('data-size')).toBe('lg')
  })

  it('displays the title and text', async () => {
    const wrapper = await createWrapper()
    expect(wrapper.text()).toContain('Freegle matches for you')
    expect(wrapper.text()).toContain('posts near you match')
  })

  it('omits the text section when there is no text', async () => {
    mockNotification.value = {
      id: 1,
      title: 'Someone is offering: Bike',
      text: null,
    }
    const wrapper = await createWrapper()
    expect(wrapper.text()).toContain('Someone is offering: Bike')
    expect(wrapper.find('.text-muted').exists()).toBe(false)
  })

  it('shows the relative time', async () => {
    const wrapper = await createWrapper()
    expect(wrapper.find('abbr.small').text()).toBe('5 minutes ago')
  })

  it('requires the id prop', async () => {
    const wrapper = await createWrapper({ id: 7 })
    expect(wrapper.findComponent(NotificationMatchedPost).props('id')).toBe(7)
  })
})
