<template>
  <div>
    <NoticeMessage v-if="!id" variant="warning">
      No chat id given
    </NoticeMessage>
    <div v-else-if="supportOrAdmin">
      <NoticeMessage v-if="notfound" variant="warning">
        Chat {{ id }} not found
      </NoticeMessage>
      <NoticeMessage
        v-if="latestCase"
        variant="info"
        data-test="refer-case-link"
      >
        This chat has been referred to Support.
        <nuxt-link :to="'/support/cases/' + latestCase.id">
          See case {{ latestCase.id }}
        </nuxt-link>
      </NoticeMessage>
      <ModChatModal v-if="chat" :id="id" ref="modChatModal" :pov="pov" />
    </div>
    <NoticeMessage v-else variant="warning">
      You don't have access to Support Tools.
    </NoticeMessage>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from '#imports'
import { useChatStore } from '~/stores/chat'
import { useMessageStore } from '~/stores/message'
import { useMe } from '~/composables/useMe'
import api from '~/api'

// Stores
const chatStore = useChatStore()
const messageStore = useMessageStore()

// Composables
const { supportOrAdmin } = useMe()

// Route
const route = useRoute()

// Template refs
const modChatModal = ref(null)

// Local state (formerly data())
const id = ref('id' in route.params ? parseInt(route.params.id) : 0)
const notfound = ref(false)
const latestCase = ref(null)
const chat = ref(null)
const pov = ref(null)

// Lifecycle
onMounted(async () => {
  await messageStore.clear()
  await chatStore.clear()

  if (!id.value) {
    return
  }

  // The newest case for this chat, if there is one. Not finding it never blocks the chat.
  try {
    const cases = await api(useRuntimeConfig()).supportcases.fetchCases({
      chatid: id.value,
    })
    latestCase.value = Array.isArray(cases) && cases.length ? cases[0] : null
  } catch (e) {
    latestCase.value = null
  }

  await chatStore.fetchChat(id.value)

  chat.value = chatStore.byChatId(id.value)

  if (chat.value) {
    pov.value =
      typeof chat.value.user1 === 'object'
        ? chat.value.user1.id
        : chat.value.user1
  } else {
    notfound.value = true
  }
})
</script>
