<template>
  <div>
    <client-only>
      <ModHelpChatReview />
      <div>
        <div
          v-for="message in visibleMessages"
          :key="'messagelist-' + message.id"
          class="p-0 mt-2"
        >
          <ModChatReview
            :id="message.chatid"
            :messageid="message.id"
            @reload="reload"
          />
        </div>

        <infinite-loading
          direction="top"
          :distance="distance"
          :identifier="bump"
          @infinite="loadMore"
        >
          <template #spinner>
            <Spinner :size="50" />
          </template>
          <template #complete>
            <div v-if="loading" class="d-flex justify-content-center">
              <Spinner :size="50" />
            </div>
            <notice-message v-else-if="!visibleMessages?.length">
              There are no chat messages to review at the moment.
            </notice-message>
          </template>
        </infinite-loading>
      </div>
      <SpinButton
        v-if="visibleMessages && visibleMessages.length > 1"
        class="mt-2"
        icon-name="trash-alt"
        label="Delete All"
        variant="white"
        @handle="deleteAll"
      />
      <ConfirmModal
        v-if="showDeleteModal"
        ref="deleteConfirm"
        title="Delete all chat messages?"
        @confirm="deleteConfirmed"
      />
    </client-only>
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useChatStore } from '~/stores/chat'

// We need an id for the store.  The null value is a special case used just for retrieving chat review messages.
const REVIEWCHAT = null

const authStore = useAuthStore()
const chatStore = useChatStore()

// Reactive state (was data())
// eslint-disable-next-line no-unused-vars
const context = ref(null)
const distance = ref(1000)
const show = ref(0)
const bump = ref(0)
const showDeleteModal = ref(false)
const loading = ref(true)

// Computed properties
const messages = computed(() => {
  return chatStore.messagesById(REVIEWCHAT)
})

const visibleMessages = computed(() => {
  return messages.value.slice(0, show.value).filter((message) => {
    return message !== null
  })
})

const work = computed(() => {
  const workData = authStore.work
  return workData?.chatreview
})

const modalOpen = computed(() => {
  const bodyoverflow = document.body.style.overflow
  return bodyoverflow === 'hidden'
})

// Watchers
watch(work, (newVal, oldVal) => {
  // TODO: The page is always going to be visible so why might we not be?
  console.log('TODO chats review work watch', newVal, oldVal)
  if (!modalOpen.value) {
    if (newVal > oldVal) {
      clearAndLoad()
    } else {
      const visible = true
      // TODO const visible = this.$store.getters['misc/get']('visible')

      if (!visible) {
        clearAndLoad()
      }
    }
  }
})

// Methods
function loadMore($state) {
  if (messages.value.length === 0) {
    // Data hasn't loaded yet — don't complete or we'll never load more.
    $state.loaded()
    return
  }

  if (show.value < messages.value.length) {
    show.value++
    $state.loaded()
  } else {
    $state.complete()
  }
}

async function reload() {
  await clearAndLoad()
}

async function clearAndLoad() {
  console.log('review clearAndLoad')
  loading.value = true
  // There's new stuff to do.  Reload.
  // We don't want to pick up any real chat messages.
  await chatStore.clear()

  await chatStore.fetchReviewChatsMT(REVIEWCHAT, {})

  loading.value = false
  bump.value++
}

function deleteAll(callback) {
  showDeleteModal.value = true
  callback()
}

function deleteConfirmed() {
  visibleMessages.value.forEach((m) => {
    if (!m.widerchatreview) {
      chatStore.rejectChat(m.id)
    }
  })
}

// Lifecycle - mounted
onMounted(async () => {
  await clearAndLoad()
})
</script>
