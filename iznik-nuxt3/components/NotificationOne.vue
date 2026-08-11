<template>
  <div
    v-if="notification"
    class="notification__wrapper"
    :class="notification?.seen ? 'border-bottom' : 'bg-info border-bottom'"
    @click="click"
    @mouseover="markSeen"
  >
    <NotificationLovedPost v-if="notification.type === 'LovedPost'" :id="id" />
    <NotificationLovedComment
      v-else-if="notification.type === 'LovedComment'"
      :id="id"
    />
    <NotificationCommentOnPost
      v-else-if="notification.type === 'CommentOnYourPost'"
      :id="id"
    />
    <NotificationCommentOnComment
      v-else-if="notification.type === 'CommentOnCommented'"
      :id="id"
    />
    <NotificationExhort v-else-if="notification.type === 'Exhort'" :id="id" />
    <NotificationAboutMe
      v-else-if="notification.type === 'AboutMe'"
      :id="id"
      @show-modal="showModal"
    />
    <NotificationGiftAid v-else-if="notification.type === 'GiftAid'" :id="id" />
    <NotificationOpenPosts
      v-else-if="notification.type === 'OpenPosts'"
      :id="id"
    />
    <NotificationMatchedPost
      v-else-if="notification.type === 'MatchedPost'"
      :id="id"
    />
    <span v-else-if="notification.type === 'TryFeed'" />
    <span v-else> Unknown notification {{ notification.type }} </span>
  </div>
</template>
<script setup>
import { defineAsyncComponent } from 'vue'
import { useRouter, useRuntimeConfig } from '#imports'
import { setupNotification } from '~/composables/useNotification'

const NotificationGiftAid = defineAsyncComponent(() =>
  import('~/components/NotificationGiftAid')
)
const NotificationLovedPost = defineAsyncComponent(() =>
  import('~/components/NotificationLovedPost')
)
const NotificationLovedComment = defineAsyncComponent(() =>
  import('~/components/NotificationLovedComment')
)
const NotificationCommentOnPost = defineAsyncComponent(() =>
  import('~/components/NotificationCommentOnPost')
)
const NotificationCommentOnComment = defineAsyncComponent(() =>
  import('~/components/NotificationCommentOnComment')
)
const NotificationExhort = defineAsyncComponent(() =>
  import('~/components/NotificationExhort')
)
const NotificationAboutMe = defineAsyncComponent(() =>
  import('~/components/NotificationAboutMe')
)
const NotificationOpenPosts = defineAsyncComponent(() =>
  import('~/components/NotificationOpenPosts')
)
const NotificationMatchedPost = defineAsyncComponent(() =>
  import('~/components/NotificationMatchedPost')
)

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['showModal'])
const router = useRouter()
const runtimeConfig = useRuntimeConfig()

// Setup notification
const { notification, notificationStore, newsfeed } = await setupNotification(
  props.id
)

function markSeen() {
  if (!notification.value?.seen) {
    notificationStore.seen(props.id)
  }
}

function originOf(url) {
  try {
    return url ? new URL(url).origin : null
  } catch (e) {
    return null
  }
}

// Some notifications store a full URL rather than a router path - the stories
// exhort is scheduled with https://www.ilovefreegle.org/stories.  Opening one of
// those with window.open() launches the system browser, which in the app throws
// the freegler out of the app into a session where they're not logged in, so
// whatever the notification asked them to do then fails.  The push side already
// strips our own site off the front (PushNotificationService::notificationRoute)
// and this is the equivalent for the in-app notification list.  Returns the path
// to route to, or null if the URL really is somewhere else.
function internalPath(url) {
  const trimmed = (url || '').trim()

  if (!trimmed) {
    return null
  }

  if (trimmed.startsWith('/')) {
    // Already a router path.
    return trimmed
  }

  let parsed = null

  try {
    parsed = new URL(trimmed)
  } catch (e) {
    // Not something we can pick apart - leave it to window.open().
    return null
  }

  const ours = [
    originOf(runtimeConfig.public.USER_SITE),
    typeof window === 'undefined' ? null : window.location.origin,
  ].filter(Boolean)

  return ours.includes(parsed.origin)
    ? parsed.pathname + parsed.search + parsed.hash
    : null
}

function click() {
  markSeen()

  const url = notification.value?.url

  if (url) {
    const path = internalPath(url)

    if (path) {
      router.push(path)
    } else {
      window.open(url)
    }
  } else if (newsfeed?.value) {
    router.push('/chitchat/' + newsfeed.value.id)
  }
}

function showModal() {
  emit('showModal')
}
</script>
<style scoped>
.notification__wrapper {
  white-space: normal;
}
</style>
