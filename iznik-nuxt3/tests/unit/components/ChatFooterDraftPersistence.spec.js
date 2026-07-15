import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h, ref, watch, onBeforeUnmount, Suspense } from 'vue'
import { useChatDraftStore } from '~/stores/chatdraft'

// setupChat() (composables/useChat.js) is a plain synchronous function - it never
// awaits anything.
function setupChat(id) {
  return { id }
}

// Buggy shape: mirrors ChatFooter.vue as it stood before this fix - it awaited
// setupChat() (a top-level await in <script setup>, which makes Vue treat the
// whole component as async setup) BEFORE registering the sendmessage ref, the
// draft-save watchers and the onBeforeUnmount flush that PR #1031 (0d71728de)
// added. Vue's own docs warn that lifecycle hooks/watchers registered after the
// first await in an async setup() are not bound to the component instance, so
// onBeforeUnmount silently never fires - the root cause of topic 9884 post 2.
function buildBuggyFooter() {
  return defineComponent({
    props: { id: { type: Number, required: true } },
    async setup(props) {
      const chatDraftStore = useChatDraftStore()

      await setupChat(props.id)

      const sendmessage = ref(null)

      // Intentionally reproducing the historic bug for this regression test: these
      // hooks are registered after the await above, so they never bind to the
      // component instance.
      // eslint-disable-next-line vue/no-watch-after-await
      watch(
        () => props.id,
        (newId, oldId) => {
          if (oldId && oldId !== newId) {
            chatDraftStore.saveDraft(oldId, sendmessage.value)
          }
          const saved = chatDraftStore.getDraft(newId)
          if (saved && !sendmessage.value) {
            sendmessage.value = saved
          }
        },
        { immediate: true }
      )

      // eslint-disable-next-line vue/no-lifecycle-after-await
      onBeforeUnmount(() => {
        chatDraftStore.saveDraft(props.id, sendmessage.value)
      })

      return () =>
        h('textarea', {
          value: sendmessage.value,
          onInput: (e) => {
            sendmessage.value = e.target.value
          },
        })
    },
  })
}

// Fixed shape: the hooks are registered before the await, so they stay bound to
// the component instance and fire normally.
function buildFixedFooter() {
  return defineComponent({
    props: { id: { type: Number, required: true } },
    async setup(props) {
      const chatDraftStore = useChatDraftStore()
      const sendmessage = ref(null)

      watch(
        () => props.id,
        (newId, oldId) => {
          if (oldId && oldId !== newId) {
            chatDraftStore.saveDraft(oldId, sendmessage.value)
          }
          const saved = chatDraftStore.getDraft(newId)
          if (saved && !sendmessage.value) {
            sendmessage.value = saved
          }
        },
        { immediate: true }
      )

      onBeforeUnmount(() => {
        chatDraftStore.saveDraft(props.id, sendmessage.value)
      })

      await setupChat(props.id)

      return () =>
        h('textarea', {
          value: sendmessage.value,
          onInput: (e) => {
            sendmessage.value = e.target.value
          },
        })
    },
  })
}

function mountChatPane(Footer, chatId) {
  const Parent = defineComponent({
    props: { chatId: { type: Number, required: true } },
    setup(props) {
      return () =>
        h(Suspense, null, {
          default: () =>
            h(Footer, {
              id: props.chatId,
              // Mirrors pages/chats/[[id]].vue keying ChatPane by
              // 'chatpane-' + selectedChatId, which forces a full unmount/remount
              // of ChatFooter on every chat switch.
              key: 'chatpane-' + props.chatId,
            }),
          fallback: () => h('div', 'loading'),
        })
    },
  })
  return mount(Parent, { props: { chatId } })
}

async function settle(wrapper) {
  // Async setup() needs a couple of microtask/tick rounds to resolve and patch.
  await new Promise((resolve) => setTimeout(resolve, 0))
  await wrapper.vm.$nextTick()
  await new Promise((resolve) => setTimeout(resolve, 0))
  await wrapper.vm.$nextTick()
}

describe('ChatFooter chat-switch draft persistence (topic 9884 post 2)', () => {
  let originalWarn

  beforeEach(() => {
    setActivePinia(createPinia())
    // The repo's test setup escalates [Vue warn] console output into a thrown
    // error (see tests/unit/setup.ts) so it can't leak past dev builds. That's
    // exactly the warning this bug trips ("onBeforeUnmount is called when there
    // is no active component instance... register lifecycle hooks before the
    // first await"). In production Vue only warns (and only in dev builds) - it
    // does not throw - so silence it here to observe the real broken behaviour
    // instead of crashing the test.
    originalWarn = console.warn
    console.warn = () => {}
  })

  afterEach(() => {
    console.warn = originalWarn
  })

  it('does NOT save the draft on chat switch when hooks are registered after the await (bug)', async () => {
    const wrapper = mountChatPane(buildBuggyFooter(), 1)
    await settle(wrapper)

    await wrapper.find('textarea').setValue('hello world')

    await wrapper.setProps({ chatId: 2 })
    await settle(wrapper)

    const store = useChatDraftStore()
    expect(store.getDraft(1)).toBe('')
  })

  it('saves the draft on chat switch when hooks are registered before the await (fixed)', async () => {
    const wrapper = mountChatPane(buildFixedFooter(), 1)
    await settle(wrapper)

    await wrapper.find('textarea').setValue('hello world')

    await wrapper.setProps({ chatId: 2 })
    await settle(wrapper)

    const store = useChatDraftStore()
    expect(store.getDraft(1)).toBe('hello world')
  })

  it('ChatFooter.vue does not await setupChat() at the top level', () => {
    // Ties the reproduction above to the real file: setupChat() is a plain
    // synchronous function (composables/useChat.js), so awaiting it is not just
    // unnecessary - it's a top-level await in <script setup>, which makes Vue
    // treat the component as async setup. That silently detaches any watch()/
    // onBeforeUnmount() registered after it - the root cause of this bug.
    const source = readFileSync(
      resolve(__dirname, '../../../components/ChatFooter.vue'),
      'utf-8'
    )
    expect(source).not.toMatch(/await\s+setupChat\(/)
  })
})
