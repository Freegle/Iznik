import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

import MyPostsInterestedRow from '~/components/MyPostsInterestedRow.vue'

const { display } = vi.hoisted(() => ({ display: { current: null } }))

vi.mock('~/composables/useMessageDisplay', () => ({
  useMessageDisplay: () => display.current,
}))

function mountRow(message) {
  display.current = {
    message: ref(message),
    subjectItemName: ref('Armchair'),
    subjectLocation: ref('EX1 1SR'),
    placeholderClass: ref('offer'),
  }
  return mount(MyPostsInterestedRow, {
    props: { id: 7 },
    global: {
      stubs: {
        'nuxt-link': {
          props: ['to'],
          template: '<a :href="to"><slot /></a>',
        },
        'b-button': {
          props: ['to'],
          emits: ['click'],
          template:
            '<button :data-to="to" @click="$emit(\'click\')"><slot /></button>',
        },
        OurUploadedImage: {
          props: ['src'],
          template: '<img class="our" :data-src="src" />',
        },
        ProxyImage: {
          props: ['src'],
          template: '<img class="proxy" :data-src="src" />',
        },
        MessagePhotoPlaceholder: { template: '<div class="placeholder" />' },
      },
    },
  })
}

describe('MyPostsInterestedRow', () => {
  it('names the item and where it is, linking to the post', () => {
    const w = mountRow({ id: 7, attachments: [] })
    expect(w.text()).toContain('Armchair')
    expect(w.text()).toContain('EX1 1SR')
    expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual([
      '/message/7',
      '/message/7',
    ])
  })

  it('opens the reply pane from Reply', () => {
    const w = mountRow({ id: 7, attachments: [] })
    expect(
      w.find('[data-testid="interested-reply-7"]').attributes('data-to')
    ).toBe('/message/7?reply=1')
  })

  it('asks its list to remove it', async () => {
    const w = mountRow({ id: 7, attachments: [] })
    await w.find('[data-testid="interested-remove-7"]').trigger('click')
    expect(w.emitted('remove')).toEqual([[7]])
  })

  it('shows an uploaded photo, a proxied photo, or the placeholder', () => {
    expect(
      mountRow({ id: 7, attachments: [{ ouruid: 'u1' }] })
        .find('.our')
        .attributes('data-src')
    ).toBe('u1')
    expect(
      mountRow({ id: 7, attachments: [{ path: 'https://x/p.jpg' }] })
        .find('.proxy')
        .attributes('data-src')
    ).toBe('https://x/p.jpg')
    expect(
      mountRow({ id: 7, attachments: [] }).find('.placeholder').exists()
    ).toBe(true)
  })
})
