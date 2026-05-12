<template>
  <div>
    <ModSupportChat
      v-for="chat in chatsShown"
      :key="'chathistory-' + chat.id"
      :chatid="chat.id"
      :pov="pov"
    />
    <infinite-loading :identifier="infiniteBump" @infinite="loadMore">
      <template #spinner><span /></template>
      <template #complete><span /></template>
      <template #no-results><span /></template>
    </infinite-loading>
    <notice-message v-if="!props.chats?.length"> No chats. </notice-message>
  </div>
</template>
<script setup>
import { ref, computed, watch } from 'vue'
import { useChatStore } from '~/stores/chat'
import { useUserStore } from '~/stores/user'

const PAGE_SIZE = 10

const chatStore = useChatStore()
const userStore = useUserStore()

const props = defineProps({
  chats: {
    type: Array,
    required: true,
  },
  pov: {
    type: Number,
    required: false,
    default: null,
  },
})

const showCount = ref(0)
const infiniteBump = ref(0)

function otherUserId(chat) {
  if (chat.chattype !== 'User2User') return null
  return chat.user1 === props.pov ? chat.user2 : chat.user1
}

// Populate the chat store with chat objects so ModSupportChat can look them up by id.
function populateStore(chats) {
  if (chats) {
    chats.forEach((c) => {
      chatStore.listByChatId[c.id] = c
    })
  }
}

async function prefetchUsers(slice) {
  const ids = [...new Set(slice.map(otherUserId).filter(Boolean))]
  if (ids.length) {
    await userStore.fetchMultiple(ids)
  }
}

async function loadMore($state) {
  const nextSlice = props.chats.slice(showCount.value, showCount.value + PAGE_SIZE)

  if (!nextSlice.length) {
    $state.complete()
    return
  }

  await prefetchUsers(nextSlice)
  showCount.value += nextSlice.length

  if (showCount.value >= props.chats.length) {
    $state.complete()
  } else {
    $state.loaded()
  }
}

populateStore(props.chats)

watch(() => props.chats, (newChats) => {
  populateStore(newChats)
  showCount.value = 0
  infiniteBump.value++
})

const chatsShown = computed(() => {
  return props.chats ? props.chats.slice(0, showCount.value) : []
})
</script>
