<template>
  <div v-if="userid !== myid" class="d-inline clickme">
    <slot>
      <b-button
        :size="size"
        :variant="variant"
        :class="btnClass + ' d-none d-sm-inline'"
        @click="handleButtonClick"
      >
        <v-icon v-if="showIcon" icon="comments" />
        <span v-if="title" :class="titleClass">
          {{ title }}
        </span>
      </b-button>
      <b-button
        :size="size"
        :variant="variant"
        :class="btnClass + ' d-inline-block d-sm-none'"
        @click="handleButtonClick"
      >
        <v-icon v-if="showIcon" icon="comments" />
        <span v-if="title" :class="titleClass">
          {{ title }}
        </span>
      </b-button>
    </slot>
  </div>
</template>
<script setup>
import { useChatStore } from '~/stores/chat'
import { useMessageStore } from '~/stores/message'
import { useRouter } from '#imports'
import { useMe } from '~/composables/useMe'
import { action } from '~/composables/useClientLog'

const props = defineProps({
  size: {
    type: String,
    required: false,
    default: null,
  },
  title: {
    type: String,
    required: false,
    default: null,
  },
  variant: {
    type: String,
    required: false,
    default: 'primary',
  },
  userid: {
    type: Number,
    required: false,
    default: null,
  },
  chattype: {
    type: String,
    required: false,
    default: null,
  },
  showIcon: {
    type: Boolean,
    required: false,
    default: true,
  },
  btnClass: {
    type: String,
    required: false,
    default: null,
  },
  titleClass: {
    type: String,
    required: false,
    default: 'ms-1',
  },
})

const emit = defineEmits(['click', 'sent'])
const chatStore = useChatStore()
const messageStore = useMessageStore()
const router = useRouter()

// Use me and myid computed properties from useMe composable for consistency
const { me, myid } = useMe()

const handleButtonClick = async (event) => {
  // Support Control+click, Cmd+click, and middle-click to open in new tab
  // Right-click is handled by browser context menu automatically
  const openInNewTab =
    (event && (event.ctrlKey || event.metaKey || event.button === 1)) || false
  await openChat(null, null, null, openInNewTab)
}

const openChat = async (
  event,
  firstmessage,
  firstmsgid,
  openInNewTab,
  noNavigate = false,
  replySource = null
) => {
  emit('click')
  console.log('Open chat', firstmessage, firstmsgid, props.userid)

  if (props.userid > 0) {
    let chatid = null
    try {
      chatid = await chatStore.openChatToUser({
        userid: props.userid,
        chattype: props.chattype,
      })
    } catch (e) {
      action('chat_open_failed', {
        error: e.message,
        userid: props.userid,
        message_id: firstmsgid,
      })
      throw e
    }

    if (chatid) {
      if (firstmessage) {
        console.log('First message to send', firstmessage)
        try {
          await chatStore.send(
            chatid,
            firstmessage,
            null,
            null,
            firstmsgid,
            false,
            replySource
          )
          console.log('Sent')

          action('chat_message_sent', {
            chat_id: chatid,
            message_id: firstmsgid,
          })
        } catch (e) {
          action('chat_send_failed', {
            error: e.message,
            chat_id: chatid,
            message_id: firstmsgid,
          })
          throw e
        }

        if (firstmsgid) {
          // Update the message so that the reply count is updated. No need to wait.
          messageStore.fetch(firstmsgid, true)
        }

        // Refresh the message so that our reply will show.
        await chatStore.fetchMessages(chatid, true)

        emit('sent')
      }

      // set the flag on the store to let the chat know that a modal asking for
      // contact details should be opened as soon as the chat's loaded
      chatStore.showContactDetailsAskModal =
        me.value && !me.value.settings.mylocation

      // We may be called from within a profile modal. We want to skip the navigation guard which would otherwise
      // close the modal.
      //
      // noNavigate lets the reply flow create + send the chat without leaving
      // the current page — used when replying from the browse list so the
      // user stays put and can reply to more items.
      if (!noNavigate) {
        if (openInNewTab) {
          window.open(`/chats/${chatid}`, '_blank')
        } else {
          router.push({
            name: 'chats-id',
            query: {
              noguard: true,
            },
            params: {
              id: chatid,
            },
          })
        }
      }
    } else {
      // chatid is null/undefined - log this as it means openChatToUser failed silently
      action('chat_open_no_chatid', {
        userid: props.userid,
        message_id: firstmsgid,
      })
    }
  }
}

// Expose the openChat method so it can be called from parent components
defineExpose({
  openChat,
})
</script>
