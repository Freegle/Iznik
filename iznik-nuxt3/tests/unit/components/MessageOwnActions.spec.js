import { describe, it, expect } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MessageOwnActions from '~/components/MessageOwnActions.vue'

function createWrapper(type = 'Offer') {
  return mount(MessageOwnActions, {
    props: { message: { id: 123, type } },
    global: {
      stubs: {
        'b-button': {
          template:
            '<button :data-testid="$attrs[\'data-testid\']" :data-to="to" @click="$emit(\'click\', $event)"><slot /></button>',
          props: ['variant', 'size', 'to'],
        },
        'v-icon': { template: '<span />', props: ['icon'] },
        OutcomeModal: {
          template: '<div class="outcome-modal" :data-type="type" />',
          props: ['id', 'type'],
        },
      },
    },
  })
}

describe('MessageOwnActions', () => {
  it('offers Mark as TAKEN on an offer', () => {
    const wrapper = createWrapper('Offer')
    expect(wrapper.find('[data-testid="own-post-mark"]').text()).toContain(
      'Mark as TAKEN'
    )
  })

  it('offers Mark as RECEIVED on a wanted', () => {
    const wrapper = createWrapper('Wanted')
    expect(wrapper.find('[data-testid="own-post-mark"]').text()).toContain(
      'Mark as RECEIVED'
    )
  })

  it('opens the outcome modal for the right outcome', async () => {
    const wrapper = createWrapper('Offer')
    expect(wrapper.find('.outcome-modal').exists()).toBe(false)
    await wrapper.find('[data-testid="own-post-mark"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('.outcome-modal').attributes('data-type')).toBe('Taken')
  })

  it('links to My posts', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('[data-to="/myposts"]').exists()).toBe(true)
  })
})
