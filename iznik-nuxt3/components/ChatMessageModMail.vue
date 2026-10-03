<template>
  <div>
    <b-row>
      <b-col>
        <div class="media">
          <b-card border-variant="success" :class="{ 'ms-auto': !amUser }">
            <b-card-title>
              <h4>Message from Freegle</h4>
              <div v-if="realMod" class="text-muted small">
                <div class="small">
                  (Sent by
                  <v-icon icon="hashtag" class="text-muted" scale="0.5" />{{
                    chatmessage.userid
                  }})
                </div>
              </div>
            </b-card-title>
            <b-card-text>
              <div :class="emessage ? 'media-body chatMessage' : 'media-body'">
                <span>
                  <span
                    v-if="
                      chatmessage.secondsago < 60 ||
                      chatmessage.id > chat.lastmsgseen
                    "
                    class="prewrap fw-bold"
                    >{{ emessage }}</span
                  >
                  <span v-else class="preline forcebreak">{{ emessage }}</span>
                  <b-img
                    v-if="chatmessage.image"
                    fluid
                    :src="chatmessage.image.path"
                    lazy
                    rounded
                  />
                </span>
              </div>
              <div v-if="chatmessage.refmsgid && refmsg && !miscStore.modtools">
                <hr />
                <p>
                  If you have been asked to edit and resend this message, you
                  can do so here:
                </p>
                <b-button variant="warning" @click="repost">
                  <v-icon icon="pen" /> Edit and Resend
                </b-button>
              </div>
              <NoticeMessage
                v-else-if="chat.chattype === 'User2User'"
                variant="warning"
                class="mt-2"
              >
                <p>
                  Volunteers won't see any replies you make in here about this
                  message - they'll go to the other freegler. If you want to
                  contact the volunteers, please use the button below.
                </p>
                <b-button
                  variant="primary"
                  class="mb-2"
                  :disabled="contacting"
                  @click="contactMods"
                >
                  Contact Freegle volunteers
                </b-button>
              </NoticeMessage>
            </b-card-text>
          </b-card>
        </div>
      </b-col>
    </b-row>
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import NoticeMessage from './NoticeMessage'
import { useComposeStore } from '~/stores/compose'
import { useChatStore } from '~/stores/chat'
import { useMiscStore } from '~/stores/misc'
import {
  fetchReferencedMessage,
  useChatMessageBase,
} from '~/composables/useChat'
import { useRouter } from '#imports'

const props = defineProps({
  chatid: {
    type: Number,
    required: true,
  },
  id: {
    type: Number,
    required: true,
  },
  last: {
    type: Boolean,
    required: false,
    default: false,
  },
  pov: {
    type: Number,
    required: false,
    default: null,
  },
  highlightEmails: {
    type: Boolean,
    required: false,
    default: false,
  },
})

// Use the chat base composable
const { chat, chatmessage, emessage, refmsg, me, myid, realMe } =
  useChatMessageBase(props.chatid, props.id, props.pov)

const composeStore = useComposeStore()
const chatStore = useChatStore()
const miscStore = useMiscStore()
const contacting = ref(false)

// Setup
await fetchReferencedMessage(props.chatid, props.id)

const amUser = computed(() => {
  return chat.value && chat.value.user && chat.value.user.id === myid
})

const realMod = computed(() => {
  // Show "(Sent by #...)" to moderators, support, and admins
  return (
    realMe.value &&
    (realMe.value.systemrole === 'Moderator' ||
      realMe.value.systemrole === 'Support' ||
      realMe.value.systemrole === 'Admin')
  )
})

async function repost() {
  const message = Object.assign({}, refmsg.value)

  if (message) {
    // Remove any partially composed messages we currently have, because they'll be confusing.
    await composeStore.clearMessages()

    // Add this message to the compose store so that it will show up on the compose page.
    await composeStore.setMessage(
      0,
      {
        type: message.type,
        item: message.item?.name?.trim(),
        description: message.textbody.trim(),
        availablenow: message.availablenow,
        repostof: chatmessage.value.refmsgid,
      },
      me.value
    )

    composeStore.setAttachmentsForMessage(0, message.attachments)

    const router = useRouter()
    router.push(message.type === 'Offer' ? '/give' : '/ask')
  }
}

async function contactMods() {
  contacting.value = true

  try {
    const chatid = await chatStore.openChatToMods()
    const router = useRouter()
    router.push('/chats/' + chatid)
  } finally {
    contacting.value = false
  }
}
</script>
<style scoped lang="scss">
.chatMessage {
  border: 1px solid $color-gray--light;
  border-radius: var(--radius-lg, 0.75rem);
  padding-top: 2px;
  padding-bottom: 2px;
  padding-left: 4px;
  padding-right: 2px;
  word-wrap: break-word;
  line-height: 1.5;
}
</style>
