<template>
  <div class="feed-page">
    <MobileHeader
      :unread-count="unreadCount"
      :logged-in="isLoggedIn"
      @open-chats="showChats = true"
      @open-settings="showSettings = true"
      @open-login="showLogin = true"
    />

    <FeedSearch v-model="searchQuery" />
    <FeedFilter @update:type-filter="(v) => typeFilter = v" />

    <div class="feed-page__content mobile-content">
      <div v-if="loading" class="feed-page__loading">Loading...</div>
      <FeedEmpty v-else-if="displayItems.length === 0" />
      <template v-else>
        <SwipeableCard
          v-for="item in displayItems"
          :key="item.id"
          @swipe-left="onSwipeLeft(item)"
          @swipe-right="onSwipeRight(item)"
        >
          <FeedCard
            :post="item"
            @reply="openReply"
            @report="onReport"
            @hide="onHide"
            @open-detail="openDetail"
          />
        </SwipeableCard>

        <DonateCard
          v-if="showDonatePrompt"
          :message="donateMessage"
          @donate="onDonate"
          @dismiss="showDonatePrompt = false"
        />
      </template>
    </div>

    <FeedComposer @submit="onCompose" />

    <ConfirmPost
      v-if="pendingPost"
      :type="pendingPost.type"
      :title="pendingPost.title"
      :description="pendingPost.description"
      :image-preview="pendingPost.imagePreview"
      @edit="pendingPost = null"
      @confirm="confirmPost"
    />

    <NotifyChoice
      v-if="showNotifyChoice"
      @choose="onNotifyChoice"
    />

    <ChatList
      :visible="showChats"
      :conversations="conversations"
      @close="showChats = false"
      @open-chat="openChatWithUser"
    />

    <ChatSlideOver
      :visible="!!activeChat"
      :user-name="activeChat?.userName || ''"
      :user-avatar="activeChat?.userAvatar"
      :quoted-item="activeChat?.quotedItem"
      :messages="activeChat?.messages || []"
      @close="activeChat = null"
      @send="sendChatMessage"
      @mark-taken="markTaken"
    />

    <ProfileSheet
      :visible="!!viewingProfile"
      :user="viewingProfile || {}"
      @close="viewingProfile = null"
    />

    <PostDetail
      :post="detailPost"
      @close="detailPost = null"
      @reply="openReply"
    />

    <SettingsDrawer
      :visible="showSettings"
      @close="showSettings = false"
      @navigate="onSettingsNavigate"
    />

    <LoginModal
      :visible="showLogin"
      :hint="loginHint"
      @close="showLogin = false"
      @logged-in="onLoggedIn"
    />

    <SwipeFeedback
      :visible="!!swipeFeedbackItem"
      :direction="swipeFeedbackDirection"
      @feedback="handleSwipeFeedback"
      @dismiss="swipeFeedbackItem = null"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import MobileHeader from '~/components/MobileHeader.vue'
import FeedSearch from '~/components/FeedSearch.vue'
import FeedFilter from '~/components/FeedFilter.vue'
import FeedCard from '~/components/FeedCard.vue'
import FeedEmpty from '~/components/FeedEmpty.vue'
import FeedComposer from '~/components/FeedComposer.vue'
import ConfirmPost from '~/components/ConfirmPost.vue'
import NotifyChoice from '~/components/NotifyChoice.vue'
import ChatList from '~/components/ChatList.vue'
import ChatSlideOver from '~/components/ChatSlideOver.vue'
import ProfileSheet from '~/components/ProfileSheet.vue'
import SettingsDrawer from '~/components/SettingsDrawer.vue'
import SwipeableCard from '~/components/SwipeableCard.vue'
import DonateCard from '~/components/DonateCard.vue'
import SwipeFeedback from '~/components/SwipeFeedback.vue'
import PostDetail from '~/components/PostDetail.vue'
import LoginModal from '~/components/LoginModal.vue'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'
import { useGroupStore } from '~/stores/group'
import { useUserStore } from '~/stores/user'
import { useChatStore } from '~/stores/chat'
import { useNewsfeedStore } from '~/stores/newsfeed'
import { classifyPost } from '~/composables/usePostClassifier'
import { extractTitle } from '~/composables/useTitleExtractor'
import { filterFeed, searchFeed } from '~/composables/useFeedFilter'
import { detectTaken } from '~/composables/useTakenDetector'

dayjs.extend(relativeTime)

const messageStore = useMessageStore()
const groupStore = useGroupStore()
const userStore = useUserStore()
const chatStore = useChatStore()
const newsfeedStore = useNewsfeedStore()
const authStore = useAuthStore()

const isLoggedIn = computed(() => !!authStore.auth?.jwt)

// State
const searchQuery = ref('')
const currentView = ref('feed')
const typeFilter = ref(new Set(['Offer', 'Wanted', 'Discussion']))
const showChats = ref(false)
const showSettings = ref(false)
const showNotifyChoice = ref(false)
const showDonatePrompt = ref(false)
const donateMessage = ref('')
const pendingPost = ref(null)
const activeChat = ref(null)
const viewingProfile = ref(null)
const hasPostedBefore = ref(false)
const loading = ref(true)
const detailPost = ref(null)
const showLogin = ref(false)
const loginHint = ref('')
const swipeFeedbackItem = ref(null)
const swipeFeedbackDirection = ref('left')

// Feed items mapped from store
const feedItems = ref([])

// Unread count
const unreadCount = ref(0)

// Conversations from chat store
const conversations = ref([])

onMounted(async () => {
  const stored = localStorage.getItem('freegle-mobile-location')
  if (!stored) {
    useRouter().push('/')
    return
  }

  try {
    // Get location
    const loc = JSON.parse(stored)
    let lat = loc.lat || null
    let lng = loc.lng || null

    // If postcode only, geocode via the API
    if (!lat && loc.postcode) {
      try {
        const res = await $fetch(`https://api.ilovefreegle.org/apiv2/location/typeahead`, {
          params: { q: loc.postcode },
        })
        if (res?.locations?.length) {
          lat = res.locations[0].lat
          lng = res.locations[0].lng
          // Save for next time
          loc.lat = lat
          loc.lng = lng
          localStorage.setItem('freegle-mobile-location', JSON.stringify(loc))
        }
      } catch (e) {
        // Geocode failed, use default
      }
    }

    // Default to centre of England
    if (!lat) { lat = 52.5; lng = -1.5 }

    // Create bounds roughly 20 miles around location (~0.3 degrees)
    const offset = 0.3
    const list = await messageStore.fetchInBounds(
      lat - offset, lng - offset, lat + offset, lng + offset, null, 30, true
    )

    // Fetch full details for each message
    const fetchPromises = list.slice(0, 30).map((item) =>
      messageStore.fetch(item.id).catch(() => null)
    )
    await Promise.all(fetchPromises)

    // Collect unique user IDs to batch-fetch names
    const userIds = new Set()
    for (const item of list) {
      const msg = messageStore.list[item.id]
      if (msg && typeof msg.fromuser === 'number') {
        userIds.add(msg.fromuser)
      }
    }

    // Fetch user details for poster names and avatars
    const userFetches = [...userIds].map((uid) =>
      userStore.fetch(uid).catch(() => null)
    )
    await Promise.all(userFetches)

    // Map store messages to FeedCard format
    feedItems.value = list
      .map((item) => {
        const msg = messageStore.list[item.id]
        if (!msg) return null

        const hasTaken = msg.outcomes?.some(
          (o) => o.outcome === 'Taken' || o.outcome === 'Received'
        )

        const groupName = msg.groups?.[0]?.namedisplay || ''

        // Get user name and profile image
        let userName = 'Someone'
        let userAvatar = null
        const uid = typeof msg.fromuser === 'number' ? msg.fromuser : msg.fromuser?.id
        if (uid && userStore.list[uid]) {
          userName = userStore.list[uid].displayname || 'Someone'
          const thumb = userStore.list[uid].profile?.paththumb
          // Filter out default/placeholder profile images
          const isDefault = !thumb || thumb.includes('defaultprofile') || thumb.includes('profile-image?default=')
          userAvatar = isDefault ? null : thumb
        } else if (typeof msg.fromuser === 'object' && msg.fromuser?.displayname) {
          userName = msg.fromuser.displayname
        }

        // Use real attachments, fall back to sample image from API
        let imageUrls = (msg.attachments || []).map(
          (a) => a.paththumb || a.path || ''
        ).filter(Boolean)

        const isSample = !imageUrls.length && msg.sampleimage?.path
        if (isSample) {
          imageUrls = [msg.sampleimage.path]
        }

        // Strip "OFFER: " / "WANTED: " prefix and trailing location "(Place XX1)"
        let title = msg.subject || ''

        // Extract location from parenthetical before stripping
        const locMatch = title.match(/\(([^)]+)\)\s*$/)
        const itemLocation = locMatch ? locMatch[1] : ''

        // Strip type prefixes and trailing location
        title = title.replace(/^(OFFERED?|WANTED|TAKEN|RECEIVED|OFFER)\s*[-:]\s*/i, '')
        title = title.replace(/^\[?(offer|wanted|taken|received)\]?\s*[-:.]?\s*/i, '')
        title = title.replace(/\s*\([^)]+\)\s*$/, '')

        const area = itemLocation || msg.location?.area || groupName.replace('Freegle ', '') || ''

        return {
          id: msg.id,
          type: msg.type || 'Offer',
          title,
          description: msg.textbody || '',
          userName,
          userAvatar,
          userId: uid,
          groupName,
          area,
          date: msg.arrival || msg.date,
          timeAgo: dayjs(msg.arrival || msg.date).fromNow(true),
          imageUrls,
          isSampleImage: !!isSample,
          taken: hasTaken,
          takenBy: null,
          replies: msg.replies?.length || 0,
        }
      })
      .filter(Boolean)
      .sort((a, b) => new Date(b.date) - new Date(a.date))

    // Try to load chat conversations
    try {
      await chatStore.listChats()
      const chats = Object.values(chatStore.listByChatId || {})
      conversations.value = chats.slice(0, 20).map((chat) => {
        const otherUser = chat.otheruid
          ? { id: chat.otheruid, displayname: chat.name || 'Someone' }
          : { id: 0, displayname: chat.name || 'Chat' }

        return {
          chatId: chat.id,
          userId: otherUser.id,
          userName: otherUser.displayname,
          avatar: null,
          lastMessage: chat.snippet || '',
          timeAgo: chat.lastdate ? dayjs(chat.lastdate).fromNow(true) : '',
          unread: chat.unseen || 0,
          itemThumb: null,
        }
      })
      unreadCount.value = chats.reduce((sum, c) => sum + (c.unseen || 0), 0)
    } catch (e) {
      // Not logged in — chats won't load, that's fine
    }

    // Fetch chitchat/newsfeed items and mix into feed
    try {
      const nfItems = await newsfeedStore.fetchFeed(25)
      if (nfItems?.length) {
        for (const nfId of nfItems.slice(0, 10)) {
          const item = await newsfeedStore.fetch(nfId.id || nfId).catch(() => null)
          if (item?.message) {
            feedItems.value.push({
              id: `nf-${item.id}`,
              type: 'Discussion',
              title: '',
              description: item.message,
              userName: item.user?.displayname || 'Someone',
              userAvatar: item.user?.profile?.paththumb || null,
              userId: item.userid,
              groupName: '',
              area: '',
              date: item.timestamp,
              timeAgo: dayjs(item.timestamp).fromNow(true),
              imageUrls: item.imageid ? [`https://images.ilovefreegle.org/timg_${item.imageid}.jpg`] : [],
              isSampleImage: false,
              taken: false,
              takenBy: null,
              replies: item.replies?.length || 0,
              isChitchat: true,
            })
          }
        }
        // Re-sort after adding chitchat
        feedItems.value.sort((a, b) => new Date(b.date) - new Date(a.date))
      }
    } catch (e) {
      // Chitchat may not load if not in a group
    }
  } catch (e) {
    console.error('Failed to fetch messages', e)
  } finally {
    loading.value = false
  }
})

// Filtered items with grouping info
const displayItems = computed(() => {
  let items = feedItems.value

  // Multi-select type filter (Offer, Wanted, Discussion, Mine)
  if (typeFilter.value instanceof Set) {
    const showMine = typeFilter.value.has('Mine')
    items = items.filter((i) => {
      if (i.taken) return true
      if (showMine && i.userName === 'You') return true
      return typeFilter.value.has(i.type)
    })
  }
  items = searchFeed(items, searchQuery.value)

  // Add grouping: mark consecutive posts from the same user + count group size
  const result = items.map((item, i) => {
    const prev = i > 0 ? items[i - 1] : null
    const isGroupedWithPrev = prev && prev.userId === item.userId && !prev.taken && !item.taken
    return { ...item, isGroupedWithPrev }
  })

  // Count group sizes and mark first item in each group
  for (let i = 0; i < result.length; i++) {
    if (!result[i].isGroupedWithPrev && !result[i].taken) {
      let count = 1
      let j = i + 1
      while (j < result.length && result[j].isGroupedWithPrev) {
        count++
        j++
      }
      result[i].groupCount = count
    }
  }

  return result
})

// Compose flow
function onCompose({ text, images }) {
  const type = classifyPost(text)
  const title = extractTitle(text, type)

  pendingPost.value = {
    type,
    title,
    description: text,
    imagePreview: images?.[0] ? URL.createObjectURL(images[0]) : null,
    images,
  }
}

function confirmPost() {
  const post = pendingPost.value
  feedItems.value.unshift({
    id: Date.now(),
    type: post.type,
    title: post.title,
    description: post.description,
    userName: 'You',
    groupName: '',
    timeAgo: 'now',
    imageUrls: post.imagePreview ? [post.imagePreview] : [],
    taken: false,
    takenBy: null,
  })
  pendingPost.value = null

  if (!hasPostedBefore.value) {
    hasPostedBefore.value = true
    showNotifyChoice.value = true
  }
}

function onNotifyChoice(method) {
  showNotifyChoice.value = false
}

// Detail view
function openDetail(postId) {
  const post = feedItems.value.find((p) => p.id === postId)
  if (post) detailPost.value = post
}

// Reply flow
function openReply(postId) {
  const post = feedItems.value.find((p) => p.id === postId)
  if (!post) return

  activeChat.value = {
    userName: post.userName,
    userAvatar: null,
    quotedItem: { title: post.title, type: post.type, imageUrl: post.imageUrls?.[0] || null },
    messages: [],
  }
}

function sendChatMessage(text) {
  if (!activeChat.value) return

  activeChat.value.messages.push({
    id: Date.now(),
    text,
    outgoing: true,
    time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
    showTakenPrompt: false,
  })

  if (detectTaken(text)) {
    activeChat.value.messages.push({
      id: Date.now() + 1,
      text: '',
      outgoing: false,
      time: '',
      showTakenPrompt: true,
      relatedItemId: activeChat.value.quotedItem?.id,
    })
  }
}

function openChatWithUser(userId) {
  const conv = conversations.value.find((c) => c.userId === userId)
  if (!conv) return

  showChats.value = false
  activeChat.value = {
    userName: conv.userName,
    userAvatar: conv.avatar,
    quotedItem: null,
    messages: [
      { id: 1, text: conv.lastMessage, outgoing: false, time: conv.timeAgo },
    ],
  }
}

function markTaken(itemId) {
  showDonatePrompt.value = true
  donateMessage.value = 'You saved something from landfill! Buy Freegle a coffee?'
}

function onSwipeLeft(item) {
  if (item.taken) {
    feedItems.value = feedItems.value.filter((i) => i.id !== item.id)
  } else {
    swipeFeedbackItem.value = item
    swipeFeedbackDirection.value = 'left'
  }
}

function onSwipeRight(item) {
  swipeFeedbackItem.value = item
  swipeFeedbackDirection.value = 'right'
  // Auto-dismiss the "more like this" after 1.5s
  setTimeout(() => { swipeFeedbackItem.value = null }, 1500)
}

function handleSwipeFeedback(reason) {
  const item = swipeFeedbackItem.value
  if (item && reason === 'hide') {
    feedItems.value = feedItems.value.filter((i) => i.id !== item.id)
  }
  // Future: send preference to API
  swipeFeedbackItem.value = null
}

function onReport(postId) {}

function onHide(postId) {
  feedItems.value = feedItems.value.filter((i) => i.id !== postId)
}

function onDonate() {
  showDonatePrompt.value = false
}

function onSettingsNavigate(section) {}

function onLoggedIn() {
  // Reload to fetch chats
  window.location.reload()
}
</script>

<style scoped>
.feed-page {
  display: flex;
  flex-direction: column;
  height: 100%;
}
.feed-page__content {
  flex: 1;
  overflow-y: auto;
  padding-bottom: 8px;
}
.feed-page__loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 200px;
  color: #999;
  font-size: 0.9rem;
}
</style>
