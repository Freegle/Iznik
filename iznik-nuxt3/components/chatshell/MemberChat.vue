<template>
  <div class="member-chat">
    <ShellHeader
      :title="chat?.name || 'Chat'"
      :subtitle="subtitle"
      :avatar="chat?.icon || null"
      back="/chats"
      :badge="unread"
    >
      <template #menu>
        <b-dropdown v-if="chat && !chat.systemchat" variant="link" no-caret toggle-class="shell-menu-btn" right>
          <template #button-content>
            <v-icon icon="ellipsis-v" />
            <span class="visually-hidden">Menu</span>
          </template>
          <b-dropdown-item v-if="chat.chattype === 'User2User'" @click="showProfile = true">View profile</b-dropdown-item>
          <b-dropdown-item @click="hide">Hide chat</b-dropdown-item>
          <b-dropdown-item v-if="chat.chattype === 'User2User'" @click="showBlock = true">Block</b-dropdown-item>
          <b-dropdown-item v-if="chat.chattype === 'User2User'" @click="showReport = true">Report</b-dropdown-item>
        </b-dropdown>
      </template>
    </ShellHeader>
    <div class="member-pane">
      <ChatPane :id="id" embedded />
    </div>
    <ChatBlockModal v-if="showBlock" :id="id" :user="otheruser" @confirm="block" @hidden="showBlock = false" />
    <ChatReportModal v-if="showReport" :id="id" :user="otheruser" @hidden="showReport = false" />
    <ProfileModal v-if="showProfile && otheruser" :id="otheruser.id" @hidden="showProfile = false" />
  </div>
</template>
<script setup>
import { computed, ref } from '#imports'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import ChatPane from '~/components/ChatPane.vue'
import { useChatStore } from '~/stores/chat'
import { useUserStore } from '~/stores/user'
const ChatBlockModal = defineAsyncComponent(() => import('~/components/ChatBlockModal'))
const ChatReportModal = defineAsyncComponent(() => import('~/components/ChatReportModal'))
const ProfileModal = defineAsyncComponent(() => import('~/components/ProfileModal'))

// A chat with a person or a community's volunteers, inside the shell. The pane and
// footer are the ones the classic chat page uses; only the header is the shell's.
const props = defineProps({
  id: { type: Number, required: true },
})

const chatStore = useChatStore()
const userStore = useUserStore()
const router = useRouter()
const showBlock = ref(false)
const showReport = ref(false)
const showProfile = ref(false)

const chat = computed(() => chatStore.byChatId(props.id))
const otheruser = computed(() => (chat.value?.otheruid ? userStore.byId(chat.value.otheruid) : null))
const unread = computed(() => chatStore.unreadCount || 0)
const subtitle = computed(() => {
  if (!chat.value) return null
  if (chat.value.systemchat) return 'Freegle'
  if (chat.value.chattype === 'User2Mod') return 'Volunteers'
  const miles = otheruser.value?.info?.milesaway
  if (miles !== undefined && miles !== null) return `About ${Math.round(miles)} mile${Math.round(miles) === 1 ? '' : 's'} away`
  return null
})

async function hide() {
  await chatStore.hide(props.id)
  router.push('/chats')
}

async function block() {
  await chatStore.block(props.id)
  router.push('/chats')
}
</script>
<style scoped lang="scss">
.member-chat {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.member-pane {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;

  :deep(.chatHolder) {
    height: 100%;
  }
}

:deep(.shell-menu-btn) {
  color: #fff;
  padding: 0.25rem 0.5rem;
}
</style>
