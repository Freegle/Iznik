import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, ref, watch } from 'vue'
import { getSendIdempotencyKey } from '~/composables/useChat'

// ChatFooter.vue can't be mounted directly in this suite - it pulls in ~10 Pinia
// stores, several defineAsyncComponent children and Nuxt auto-imports with no test
// harness for them (see ChatFooter.spec.js's header comment and
// ChatFooterDraftPersistence.spec.js, which uses the same minimal-harness technique
// for a different ChatFooter bug). This harness mirrors ONLY the send()/pendingSend
// logic ChatFooter.vue now has (Discourse #9913) byte-for-byte in shape, uses the
// REAL getSendIdempotencyKey (composables/useChat.js) rather than a re-implementation,
// and is driven through real DOM interaction via @vue/test-utils mount() - not source
// regex matching.
function buildSendHarness(chatSend) {
  return defineComponent({
    props: { id: { type: Number, required: true } },
    setup(props) {
      const sendmessage = ref('')
      const sendError = ref(null)
      const pendingSend = ref(null)
      const sentPayloads = []

      // Mirrors the pendingSend-clear-on-chat-switch watcher added to ChatFooter.vue.
      watch(
        () => props.id,
        () => {
          pendingSend.value = null
        }
      )

      const send = async () => {
        const msg = sendmessage.value
        if (!msg) return

        const idempotencyKey = getSendIdempotencyKey(pendingSend.value, msg)
        pendingSend.value = { message: msg, key: idempotencyKey }

        try {
          sendError.value = null
          await chatSend({ chatid: props.id, message: msg, idempotencyKey })
          sentPayloads.push({ message: msg, idempotencyKey })
        } catch (e) {
          const status = e?.response?.status
          if (status === 404) {
            pendingSend.value = null
            sendError.value = 'gone'
          } else {
            sendError.value = 'retry'
          }
          return
        }

        pendingSend.value = null
        sendmessage.value = ''
      }

      return { sendmessage, sendError, pendingSend, sentPayloads, send }
    },
    render() {
      return h('div', [
        h('textarea', {
          value: this.sendmessage,
          onInput: (e) => {
            this.sendmessage = e.target.value
          },
        }),
        h('button', { onClick: this.send }, 'Send'),
        h('span', { class: 'send-error' }, this.sendError || ''),
      ])
    },
  })
}

describe('ChatFooter send idempotency key handling (Discourse #9913)', () => {
  let wrapper

  async function typeAndSend(text) {
    await wrapper.find('textarea').setValue(text)
    await wrapper.find('button').trigger('click')
    await wrapper.vm.$nextTick()
  }

  it('keeps the typed message and reuses the idempotency key when a send fails with an ambiguous (non-404) error, so a retry of the same attempt dedupes server-side', async () => {
    let attempt = 0
    const chatSend = () => {
      attempt++
      if (attempt === 1) {
        const err = new Error('Network error')
        err.response = null
        return Promise.reject(err)
      }
      return Promise.resolve()
    }
    const Harness = buildSendHarness(chatSend)
    wrapper = mount(Harness, { props: { id: 1 } })

    await typeAndSend('hello there')
    expect(wrapper.vm.sendError).toBe('retry')
    // The typed text must not be lost on a failed send.
    expect(wrapper.vm.sendmessage).toBe('hello there')
    const keyAfterFailure = wrapper.vm.pendingSend.key

    // Retry (tap Send again) with the same text - must reuse the same key.
    await wrapper.find('button').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.vm.sentPayloads).toHaveLength(1)
    expect(wrapper.vm.sentPayloads[0].idempotencyKey).toBe(keyAfterFailure)
    expect(wrapper.vm.pendingSend).toBeNull()
  })

  it('clears the pending key on a definitive 404 (post gone), so a further attempt is a genuinely fresh send', async () => {
    const chatSend = () => {
      const err = new Error('Not found')
      err.response = { status: 404 }
      return Promise.reject(err)
    }
    const Harness = buildSendHarness(chatSend)
    wrapper = mount(Harness, { props: { id: 1 } })

    await typeAndSend('hello there')
    expect(wrapper.vm.sendError).toBe('gone')
    expect(wrapper.vm.pendingSend).toBeNull()
  })

  it('clears pendingSend and the typed text after a successful send', async () => {
    const chatSend = () => Promise.resolve()
    const Harness = buildSendHarness(chatSend)
    wrapper = mount(Harness, { props: { id: 1 } })

    await typeAndSend('hello there')
    expect(wrapper.vm.pendingSend).toBeNull()
    expect(wrapper.vm.sendmessage).toBe('')
  })

  it('generates a fresh key rather than reusing a stale one when the text is edited before retrying', async () => {
    let attempt = 0
    const chatSend = () => {
      attempt++
      if (attempt === 1) {
        const err = new Error('Network error')
        err.response = null
        return Promise.reject(err)
      }
      return Promise.resolve()
    }
    const Harness = buildSendHarness(chatSend)
    wrapper = mount(Harness, { props: { id: 1 } })

    await typeAndSend('first draft')
    const keyAfterFailure = wrapper.vm.pendingSend.key

    // Edit the text before retrying - counts as a new logical send.
    await typeAndSend('edited draft')

    expect(wrapper.vm.sentPayloads[0].idempotencyKey).not.toBe(keyAfterFailure)
  })

  it('clears a pending key when the chat (props.id) changes, so it is never reused across chats', async () => {
    const chatSend = () => {
      const err = new Error('Network error')
      err.response = null
      return Promise.reject(err)
    }
    const Harness = buildSendHarness(chatSend)
    wrapper = mount(Harness, { props: { id: 1 } })

    await typeAndSend('hello there')
    expect(wrapper.vm.pendingSend).not.toBeNull()

    await wrapper.setProps({ id: 2 })
    await wrapper.vm.$nextTick()

    expect(wrapper.vm.pendingSend).toBeNull()
  })
})
