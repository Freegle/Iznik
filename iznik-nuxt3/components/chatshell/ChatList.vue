<template>
  <div class="chat-list">
    <ShellHeader title="Chats" subtitle="Freegle" avatar="/icon.png">
      <template #menu>
        <b-dropdown variant="link" no-caret toggle-class="shell-menu-btn" right>
          <template #button-content>
            <v-icon icon="ellipsis-v" />
            <span class="visually-hidden">Menu</span>
          </template>
          <b-dropdown-item @click="markAllRead">Mark all read</b-dropdown-item>
          <b-dropdown-item to="/settings">Settings</b-dropdown-item>
          <b-dropdown-item to="/help">Help</b-dropdown-item>
          <b-dropdown-item data-testid="menu-classic" @click="goClassic">Classic Freegle</b-dropdown-item>
          <b-dropdown-item @click="logout">Sign out</b-dropdown-item>
        </b-dropdown>
      </template>
    </ShellHeader>
    <div class="list-tools">
      <label for="chat-search" class="visually-hidden">Search chats</label>
      <input id="chat-search" v-model="search" type="search" class="list-search" placeholder="Search chats" data-testid="chat-search" />
      <div class="list-filters" role="tablist" aria-label="Filter chats">
        <button
          v-for="f in filters"
          :key="f.value"
          type="button"
          role="tab"
          class="list-filter"
          :class="{ active: filter === f.value }"
          :aria-selected="filter === f.value"
          :data-testid="'filter-' + f.value"
          @click="filter = f.value"
        >
          {{ f.label }}
          <span v-if="f.value === 'unread' && unread > 0" class="list-count">{{ unread }}</span>
        </button>
      </div>
    </div>
    <div class="list-scroll" data-testid="chat-rows">
      <template v-if="filter !== 'people' && !search">
        <nuxt-link no-prefetch to="/?chat=1" class="list-row pinned" data-testid="row-freegle">
          <img src="/icon.png" alt="" class="row-avatar" />
          <div class="row-body">
            <div class="row-name">Freegle <v-icon icon="thumbtack" class="row-pin" /></div>
            <div class="row-snippet">{{ freegleSnippet }}</div>
          </div>
        </nuxt-link>
        <nuxt-link v-if="openPosts.length" no-prefetch to="/chats/posts" class="list-row pinned" data-testid="row-yourposts">
          <div class="row-avatar row-avatar-posts"><v-icon icon="gift" /></div>
          <div class="row-body">
            <div class="row-name">Your posts <v-icon icon="thumbtack" class="row-pin" /></div>
            <div class="row-snippet">{{ postsSnippet }}</div>
          </div>
          <span v-if="postsUnread" class="row-badge">{{ postsUnread }}</span>
        </nuxt-link>
        <nuxt-link no-prefetch to="/chitchat" class="list-row" data-testid="row-chitchat">
          <div class="row-avatar row-avatar-group"><v-icon icon="users" /></div>
          <div class="row-body">
            <div class="row-name">{{ chitchatName }}</div>
            <div class="row-snippet">Your local chit-chat</div>
          </div>
          <span v-if="newsfeedCount" class="row-badge">{{ newsfeedCount }}</span>
        </nuxt-link>
      </template>
      <div v-for="c in rows" :key="'chat-' + c.id" class="list-row-wrap" @click="open(c.id)">
        <ChatListEntry :id="c.id" class="list-entry" />
      </div>
      <div v-if="!rows.length && filter === 'unread'" class="list-empty">Nothing unread.</div>
      <div v-if="!rows.length && search" class="list-empty">No chats match.</div>
      <div v-if="!rows.length && !search && filter === 'people'" class="list-empty">
        When you reply to a post, or someone replies to yours, the chat appears here.
      </div>
    </div>
  </div>
</template>
<script setup>
import { computed, ref, onMounted } from '#imports'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import ChatListEntry from '~/components/ChatListEntry.vue'
import { useChatStore } from '~/stores/chat'
import { useAuthStore } from '~/stores/auth'
import { useAssistantStore } from '~/stores/assistant'
import { useMessageStore } from '~/stores/message'
import { useNewsfeedStore } from '~/stores/newsfeed'
import { useUiMode } from '~/composables/useUiMode'
import { loadOwnActivePosts } from '~/composables/useCompose'

// The chat list: Freegle and Your posts pinned, your local ChitChat as a group, and
// the people you are talking to. Filters as WhatsApp has them.
const chatStore = useChatStore()
const authStore = useAuthStore()
const assistant = useAssistantStore()
const messageStore = useMessageStore()
const newsfeedStore = useNewsfeedStore()
const uiMode = useUiMode()
const router = useRouter()

const me = computed(() => authStore.user)
const search = ref('')
const filter = ref('all')
const filters = [
  { value: 'all', label: 'All' },
  { value: 'unread', label: 'Unread' },
  { value: 'people', label: 'People' },
]

const unread = computed(() => chatStore.unreadCount || 0)
const chats = computed(() => {
  const list = Object.values(chatStore.list || {}).filter((c) => c && !c.systemchat)
  return list.filter((c) => c.status !== 'Closed' && c.status !== 'Blocked')
})
const rows = computed(() => {
  let list = chats.value
  if (filter.value === 'unread') list = list.filter((c) => c.unseen > 0)
  const s = search.value.trim().toLowerCase()
  if (s) list = list.filter((c) => (c.name || '').toLowerCase().includes(s) || (c.snippet || '').toLowerCase().includes(s))
  return [...list].sort((a, b) => new Date(b.lastdate || 0) - new Date(a.lastdate || 0))
})

const freegleSnippet = computed(() => assistant.lastFreegleLine?.text || 'Give and get stuff for free, near you')
const openPosts = computed(() => (messageStore.byUserList || []).filter((m) => m && !m.outcomes?.length && !m.deleted))
const postsSnippet = computed(() => {
  const withReplies = openPosts.value.filter((m) => m.replycount > 0).length
  if (!openPosts.value.length) return 'Nothing open just now'
  if (withReplies) return `${withReplies} of your ${openPosts.value.length} posts ${withReplies === 1 ? 'has' : 'have'} replies`
  return `${openPosts.value.length} open post${openPosts.value.length === 1 ? '' : 's'}`
})
const postsUnread = computed(() => openPosts.value.reduce((n, m) => n + (m.unseenreplies || 0), 0))
const newsfeedCount = computed(() => newsfeedStore.count || 0)
const chitchatName = computed(() => {
  const area = me.value?.settings?.mylocation?.area?.name
  return area ? `${area} ChitChat` : 'ChitChat'
})

function open(id) {
  router.push('/chats/' + id)
}

async function markAllRead() {
  await chatStore.markAllRead?.()
}

async function goClassic() {
  await uiMode.setMode('classic')
  router.push('/chats')
}

async function logout() {
  await authStore.logout()
  router.push('/')
}

onMounted(async () => {
  await chatStore.listChats?.()
  if (me.value) {
    loadOwnActivePosts(messageStore, me.value.id)
    try {
      await newsfeedStore.fetchCount?.()
    } catch (e) {
      // not essential
    }
  }
})
</script>
<style scoped lang="scss">
.chat-list {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: #fff;
}

.list-tools {
  padding: 0.5rem 0.6rem 0.3rem;
  border-bottom: 1px solid #eef2f4;
}

.list-search {
  width: 100%;
  border: 0;
  background: #f0f2f5;
  border-radius: 10px;
  padding: 0.5rem 0.8rem;
  font-size: 0.95rem;
}

.list-filters {
  display: flex;
  gap: 0.4rem;
  margin-top: 0.4rem;
}

.list-filter {
  border: 1px solid #e0e4e8;
  background: #fff;
  border-radius: 999px;
  padding: 0.25rem 0.7rem;
  font-size: 0.85rem;
  color: #4a5561;

  &.active {
    background: #e8f5ee;
    border-color: #1e7c4f;
    color: #1e7c4f;
    font-weight: 600;
  }
}

.list-count {
  background: #1e7c4f;
  color: #fff;
  border-radius: 999px;
  font-size: 0.7rem;
  padding: 0 5px;
  margin-left: 0.2rem;
}

.list-scroll {
  flex: 1 1 auto;
  overflow-y: auto;
}

.list-row {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid #f0f2f5;
  color: inherit;
  text-decoration: none;

  &:hover {
    background: #f7f9fa;
  }
}

.row-avatar {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  object-fit: cover;
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #e8f5ee;
  color: #1e7c4f;
  font-size: 1.2rem;
}

.row-body {
  flex: 1 1 auto;
  min-width: 0;
}

.row-name {
  font-weight: 600;
}

.row-pin {
  font-size: 0.7rem;
  color: #9aa3ab;
  margin-left: 0.2rem;
}

.row-snippet {
  font-size: 0.85rem;
  color: #5a6470;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.row-badge {
  background: #1e7c4f;
  color: #fff;
  border-radius: 999px;
  font-size: 0.72rem;
  padding: 0 6px;
  line-height: 1.3rem;
}

.list-row-wrap {
  cursor: pointer;
  border-bottom: 1px solid #f0f2f5;
}

.list-empty {
  padding: 1.2rem 0.8rem;
  color: #5a6470;
  font-size: 0.9rem;
}

:deep(.shell-menu-btn) {
  color: #fff;
  padding: 0.25rem 0.5rem;
}
</style>
