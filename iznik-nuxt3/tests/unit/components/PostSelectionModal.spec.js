import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import PostSelectionModal from '~/components/PostSelectionModal.vue'

// Shown when somebody presses Message on a profile whose owner has posts up
// (#11): most such messages are about one of the posts, and a message sent
// without the post attached leaves the poster guessing which one.

const { mockModal, mockHide } = vi.hoisted(() => {
  const { ref } = require('vue')
  return { mockModal: ref(null), mockHide: vi.fn() }
})

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: mockModal, hide: mockHide }),
}))

describe('PostSelectionModal', () => {
  const posts = [
    { id: 11, type: 'Offer', subject: 'OFFER: Garden chairs (Bath BA1)' },
    { id: 12, type: 'Wanted', subject: 'WANTED: Bike pump (Bath BA2)' },
  ]

  beforeEach(() => {
    vi.clearAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(PostSelectionModal, {
      props: { posts, name: 'Sam', ...props },
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="b-modal"><slot /><slot name="footer" /></div>',
            props: ['scrollable', 'title', 'size'],
            emits: ['hidden'],
          },
          'b-form-textarea': {
            template:
              '<textarea class="b-form-textarea" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
            props: ['modelValue', 'rows', 'placeholder', 'id'],
            emits: ['update:modelValue'],
          },
          'b-button': {
            template:
              '<button class="b-button" :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size', 'disabled'],
            emits: ['click'],
          },
          'v-icon': { template: '<i />', props: ['icon'] },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
            props: ['variant'],
          },
        },
      },
    })
  }

  function button(wrapper, text) {
    return wrapper.findAll('.b-button').find((b) => b.text().includes(text))
  }

  it('lists each active post with its type, plus Something else', () => {
    const wrapper = createWrapper()
    const text = wrapper.text()
    expect(text).toContain('OFFER')
    expect(text).toContain('Garden chairs (Bath BA1)')
    expect(text).toContain('WANTED')
    expect(text).toContain('Bike pump (Bath BA2)')
    expect(text).toContain('Something else')
    expect(wrapper.findAll('input[type="radio"]')).toHaveLength(3)
  })

  it('names the person whose posts they are', () => {
    expect(createWrapper().text()).toContain("Sam's posts")
  })

  it('cannot continue until something is chosen', async () => {
    const wrapper = createWrapper()
    expect(button(wrapper, 'Continue').attributes('disabled')).toBeDefined()

    await wrapper.find('input[value="other"]').setValue(true)
    expect(button(wrapper, 'Continue').attributes('disabled')).toBeUndefined()
  })

  it('does not ask for a message until a post is chosen', async () => {
    const wrapper = createWrapper()
    expect(wrapper.find('.b-form-textarea').exists()).toBe(false)

    await wrapper.find('input[value="11"]').setValue(true)
    expect(wrapper.find('.b-form-textarea').exists()).toBe(true)
  })

  it('needs a message before it will send about a post', async () => {
    const wrapper = createWrapper()
    await wrapper.find('input[value="11"]').setValue(true)
    await button(wrapper, 'Continue').trigger('click')

    expect(wrapper.emitted('select')).toBeFalsy()
    expect(wrapper.text()).toContain('say something')
    expect(mockHide).not.toHaveBeenCalled()
  })

  it('sends the chosen post and the message, and stays open meanwhile', async () => {
    // The parent sends and navigates; the question only goes when the chat
    // is on screen, so a failed send cannot lose what they typed.
    const wrapper = createWrapper()
    await wrapper.find('input[value="12"]').setValue(true)
    await wrapper
      .find('.b-form-textarea')
      .setValue('  I have one going spare. ')
    await button(wrapper, 'Continue').trigger('click')

    expect(wrapper.emitted('select')).toEqual([
      [{ id: 12, text: 'I have one going spare.' }],
    ])
    expect(mockHide).not.toHaveBeenCalled()
  })

  it('cannot be sent twice while the first send is in flight', async () => {
    const wrapper = createWrapper({ sending: true })
    await wrapper.find('input[value="12"]').setValue(true)
    await wrapper.find('.b-form-textarea').setValue('Still there?')

    const go = button(wrapper, 'Sending')
    expect(go.attributes('disabled')).toBeDefined()
    await go.trigger('click')

    expect(wrapper.emitted('select')).toBeFalsy()
  })

  it('shows why a send failed and keeps the message', async () => {
    const wrapper = createWrapper()
    await wrapper.find('input[value="12"]').setValue(true)
    await wrapper.find('.b-form-textarea').setValue('Still there?')
    await wrapper.setProps({
      error: "Sorry, that didn't send. Please try again.",
    })

    expect(wrapper.find('.notice-message').text()).toContain("didn't send")
    expect(wrapper.find('.b-form-textarea').element.value).toBe('Still there?')
    expect(button(wrapper, 'Continue').attributes('disabled')).toBeUndefined()
  })

  it('opens a plain chat for something else', async () => {
    const wrapper = createWrapper()
    await wrapper.find('input[value="other"]').setValue(true)
    await button(wrapper, 'Continue').trigger('click')

    expect(wrapper.emitted('other')).toHaveLength(1)
    expect(wrapper.emitted('select')).toBeFalsy()
    expect(mockHide).not.toHaveBeenCalled()
  })

  it('cancel just closes', async () => {
    const wrapper = createWrapper()
    await button(wrapper, 'Cancel').trigger('click')

    expect(mockHide).toHaveBeenCalled()
    expect(wrapper.emitted('select')).toBeFalsy()
    expect(wrapper.emitted('other')).toBeFalsy()
  })
})
