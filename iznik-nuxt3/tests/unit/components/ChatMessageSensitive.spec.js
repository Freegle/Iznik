import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ChatMessageSensitive from '~/components/ChatMessageSensitive.vue'

// Experiment: a chat message the content check held is delivered behind a warning the
// member taps through, instead of waiting for a moderator. This is the warning.

function mountWith(reason) {
  return mount(ChatMessageSensitive, {
    props: { reason },
    global: {
      stubs: {
        'b-button': {
          template:
            '<button class="b-button" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size'],
          emits: ['click'],
        },
        'v-icon': { template: '<i class="v-icon" />', props: ['icon'] },
      },
    },
  })
}

describe('ChatMessageSensitive', () => {
  it('explains what kind of care to take for each reason', () => {
    const expectations = {
      money: /money/i,
      link: /link/i,
      contact: /contact details/i,
      language: /language/i,
      concern: /concern/i,
      scam: /scam/i,
      checked: /being checked/i,
    }
    for (const [reason, pattern] of Object.entries(expectations)) {
      const wrapper = mountWith(reason)
      expect(wrapper.text(), reason).toMatch(pattern)
      expect(wrapper.find('[data-testid="sensitive-reveal"]').exists()).toBe(
        true
      )
    }
  })

  it('falls back to the generic wording for a reason it does not know', () => {
    const wrapper = mountWith('something-new')
    expect(wrapper.text()).toMatch(/being checked/i)
  })

  it('emits reveal when the member chooses to see the message', async () => {
    const wrapper = mountWith('money')
    await wrapper.find('[data-testid="sensitive-reveal"]').trigger('click')
    expect(wrapper.emitted('reveal')).toHaveLength(1)
  })

  it('never shows the message text itself', () => {
    const wrapper = mountWith('money')
    expect(wrapper.props('reason')).toBe('money')
    expect(wrapper.text()).not.toMatch(/£/)
  })
})
