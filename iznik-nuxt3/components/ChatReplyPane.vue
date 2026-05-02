<template>
  <div class="chat-reply-pane">
    <!-- Header showing what we're replying to -->
    <div class="reply-header">
      <button class="back-btn" @click="goBack">
        <v-icon icon="arrow-left" />
      </button>
      <div v-if="message" class="reply-header-info">
        <ProfileImage
          v-if="poster"
          :image="poster.profile?.paththumb"
          :externaluid="poster.profile?.externaluid"
          :ouruid="poster.profile?.ouruid"
          :externalmods="poster.profile?.externalmods"
          :name="poster.displayname"
          class="reply-header-avatar"
          is-thumbnail
          size="md"
        />
        <div class="reply-header-text">
          <span class="reply-header-name">
            {{ poster?.displayname || 'Freegler' }}
          </span>
          <span class="reply-header-item">
            {{ message.type === 'Offer' ? 'OFFER' : 'WANTED' }}:
            {{ subjectItemName }}
          </span>
        </div>
      </div>
    </div>

    <!-- Message context - shows the item as a chat-style message bubble -->
    <div class="reply-body">
      <div v-if="message" class="message-context">
        <div class="context-bubble">
          <div v-if="message.attachments?.length" class="context-photo">
            <img
              :src="message.attachments[0].paththumb"
              :alt="subjectItemName"
              class="context-photo-img"
            />
          </div>
          <div class="context-text">
            <strong>{{ subjectItemName }}</strong>
            <p v-if="message.textbody" class="context-description">
              {{ truncatedDescription }}
            </p>
          </div>
        </div>
      </div>

      <!-- Delivery notice -->
      <div v-if="message?.deliverypossible" class="delivery-notice">
        <v-icon icon="info-circle" />
        Delivery may be possible
      </div>

      <!-- Distance warning -->
      <NoticeMessage
        v-if="milesaway > faraway && message?.type === 'Offer'"
        variant="danger"
        class="mx-3 mb-2"
      >
        This item is {{ milesaway }} miles away. Before replying, are you sure
        you can collect from there?
      </NoticeMessage>

      <!-- Promised warning -->
      <NoticeMessage
        v-if="message?.promised && !message?.promisedtome"
        variant="warning"
        class="mx-3 mb-2"
      >
        Already promised - you might not get it.
      </NoticeMessage>

      <!-- Account deleted notice -->
      <NoticeMessage v-if="me?.deleted" variant="danger" class="mx-3 mb-2">
        You can't reply until you've decided whether to restore your account.
      </NoticeMessage>
    </div>

    <!-- Reply form area - styled like chat footer -->
    <div v-if="!me?.deleted" class="reply-form-area">
      <!-- Email for logged-out users -->
      <div v-if="!me" class="reply-form-field">
        <EmailValidator
          ref="emailValidatorRef"
          v-model:email="stateMachine.email.value"
          v-model:valid="stateMachine.emailValid.value"
          size="lg"
          label="Your email address:"
          class="test-email-reply-validator"
        />
      </div>

      <VeeForm ref="form">
        <!-- Reply text -->
        <div class="reply-form-field">
          <label :for="'replytomessage-' + messageId" class="reply-form-label">
            Your reply:
          </label>
          <Field
            :id="'replytomessage-' + messageId"
            v-model="stateMachine.replyText.value"
            name="reply"
            :rules="validateReply"
            :validate-on-mount="false"
            :validate-on-model-update="false"
            as="textarea"
            rows="3"
            max-rows="8"
            class="reply-textarea"
            :placeholder="
              message?.type === 'Offer'
                ? 'Explain why you\'d like it...'
                : 'Can you help? Let them know...'
            "
            @input="stateMachine.startTyping"
          />
          <p class="reply-form-hint">
            {{
              message?.type === 'Offer'
                ? "Explain why you'd like it. It's not always first come first served. If appropriate, ask if it's working. Always be polite."
                : "Can you help? If you have what they're looking for, let them know."
            }}
          </p>
          <ErrorMessage name="reply" class="text-danger fw-bold" />
        </div>

        <!-- Collection time (Offers only) -->
        <div v-if="message?.type === 'Offer'" class="reply-form-field">
          <label :for="'replytomessage2-' + messageId" class="reply-form-label">
            When could you collect?
          </label>
          <Field
            :id="'replytomessage2-' + messageId"
            v-model="stateMachine.collectText.value"
            name="collect"
            :rules="validateCollect"
            :validate-on-mount="false"
            :validate-on-model-update="false"
            class="reply-textarea"
            as="textarea"
            rows="2"
            max-rows="2"
            placeholder="Suggest days and times..."
          />
          <p class="reply-form-hint">
            Suggest days and times you could collect if you're chosen. Your
            plans might change but this speeds up making arrangements.
          </p>
          <ErrorMessage name="collect" class="text-danger fw-bold" />
        </div>
      </VeeForm>

      <p v-if="me && !alreadyAMember" class="reply-form-hint mx-3">
        You're not yet a member of this community; we'll join you. Change emails
        or leave communities from <em>Settings</em>.
      </p>

      <div v-if="!me">
        <NewFreegler class="mt-2 mx-3" />
      </div>

      <!-- Error message -->
      <NoticeMessage
        v-if="stateMachine.error.value"
        variant="danger"
        class="mx-3 mt-2"
      >
        {{ stateMachine.error.value }}
        <b-button
          variant="link"
          size="sm"
          class="p-0 ms-2"
          @click="stateMachine.retry"
        >
          Try again
        </b-button>
      </NoticeMessage>

      <!-- Send button -->
      <div class="reply-send-area">
        <SpinButton
          variant="primary"
          size="lg"
          done-icon=""
          icon-name="angle-double-right"
          :disabled="
            !stateMachine.canSend.value || stateMachine.isProcessing.value
          "
          iconlast
          class="reply-send-btn"
          @handle="handleSend"
        >
          Send <span class="d-none d-md-inline">your</span> reply
        </SpinButton>
      </div>
    </div>

    <!-- Welcome modal for new users -->
    <b-modal
      v-if="stateMachine.showWelcomeModal.value"
      id="newUserModal"
      ref="newUserModal"
      scrollable
      ok-only
      ok-title="Close and Continue"
      @ok="handleNewUserModalOk"
    >
      <template #title>
        <h2>Welcome to Freegle!</h2>
      </template>
      <NewUserInfo :password="stateMachine.newUserPassword.value" />
    </b-modal>

    <!-- Hidden ChatButton for state machine -->
    <div class="d-none">
      <ChatButton ref="replyToPostChatButton" :userid="replyToUser" />
    </div>
  </div>
</template>

<script setup>
import { Form as VeeForm, Field, ErrorMessage } from 'vee-validate'
import {
  defineAsyncComponent,
  ref,
  computed,
  watch,
  nextTick,
  onMounted,
} from 'vue'
import { useRouter } from '#imports'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import { milesAway } from '~/composables/useDistance'
import { useMe } from '~/composables/useMe'
import {
  useReplyStateMachine,
  ReplyState,
} from '~/composables/useReplyStateMachine'
import { action } from '~/composables/useClientLog'
import EmailValidator from '~/components/EmailValidator'
import NewUserInfo from '~/components/NewUserInfo'
import ChatButton from '~/components/ChatButton'
import SpinButton from '~/components/SpinButton.vue'
import NoticeMessage from '~/components/NoticeMessage'
import ProfileImage from '~/components/ProfileImage'
import { FAR_AWAY } from '~/constants'

const NewFreegler = defineAsyncComponent(() =>
  import('~/components/NewFreegler')
)

const props = defineProps({
  messageId: {
    type: Number,
    required: true,
  },
})

const router = useRouter()

const faraway = FAR_AWAY

const messageStore = useMessageStore()
const userStore = useUserStore()
const { me, myGroups } = useMe()

// Initialize state machine
const stateMachine = useReplyStateMachine(props.messageId)

// References
const form = ref(null)
const newUserModal = ref(null)
const replyToPostChatButton = ref(null)
const emailValidatorRef = ref(null)

// Fetch the message data
await messageStore.fetch(props.messageId)

const message = computed(() => {
  return messageStore?.byId(props.messageId)
})

// Fetch poster info
watch(
  () => message.value?.fromuser,
  (userId) => {
    if (userId) {
      userStore.fetch(userId)
    }
  },
  { immediate: true }
)

const poster = computed(() => {
  return message.value?.fromuser
    ? userStore?.byId(message.value?.fromuser)
    : null
})

const subjectItemName = computed(() => {
  if (!message.value?.subject) return ''
  // Strip OFFER/WANTED prefix and location suffix
  let subject = message.value.subject
  subject = subject.replace(/^(OFFER|WANTED):\s*/i, '')
  // Remove location in parentheses at end
  subject = subject.replace(/\s*\([^)]*\)\s*$/, '')
  return subject
})

const truncatedDescription = computed(() => {
  if (!message.value?.textbody) return ''
  const text = message.value.textbody
  return text.length > 150 ? text.substring(0, 150) + '...' : text
})

const milesaway = computed(() => {
  return milesAway(
    me.value?.lat,
    me.value?.lng,
    message.value?.lat,
    message.value?.lng
  )
})

const alreadyAMember = computed(() => {
  let found = false

  if (message.value?.groups) {
    for (const messageGroup of message.value.groups) {
      Object.keys(myGroups.value).forEach((key) => {
        const group = myGroups.value[key]

        if (messageGroup.groupid === group.id) {
          found = true
        }
      })
    }
  }

  return found
})

const replyToUser = computed(() => {
  return message.value?.fromuser
})

// Watch for login state changes to resume authentication flow
watch(me, async (newVal, oldVal) => {
  if (
    !oldVal &&
    newVal &&
    stateMachine.state.value === ReplyState.AUTHENTICATING
  ) {
    try {
      await stateMachine.onLoginSuccess()
    } catch (e) {
      console.error(
        '[ChatReplyPane] onLoginSuccess failed, falling back to COMPOSING:',
        e
      )
    }
  }
})

// Watch for chat button ref becoming available
watch(replyToPostChatButton, (newVal) => {
  if (newVal) {
    stateMachine.setRefs({ chatButton: newVal })
  }
})

// Watch for form ref
watch(form, (newVal) => {
  if (newVal) {
    stateMachine.setRefs({ form: newVal })
  }
})

// Set refs on mount
onMounted(() => {
  stateMachine.setRefs({
    form: form.value,
    chatButton: replyToPostChatButton.value,
    emailValidator: emailValidatorRef.value,
  })

  action('chat_reply_pane_viewed', {
    message_id: props.messageId,
    reply_source: 'chat_reply_pane',
    message_type: message.value?.type,
    is_logged_in: !!me.value,
  })
})

// Watch for state machine completion - navigate to the chat
watch(
  () => stateMachine.isComplete.value,
  (isComplete) => {
    if (isComplete) {
      // The ChatButton's openChat already navigates to /chats/:id
      // so we don't need to do anything here
    }
  }
)

// Watch for welcome modal state
watch(
  () => stateMachine.showWelcomeModal.value,
  async (showModal) => {
    if (showModal) {
      await nextTick()
      newUserModal.value?.show()
    }
  }
)

function validateCollect(value) {
  if (value && value.trim()) {
    return true
  }
  return 'Please suggest some days and times when you could collect.'
}

function validateReply(value) {
  if (!value?.trim()) {
    return 'Please fill out your reply.'
  }

  if (
    message.value?.type === 'Offer' &&
    value &&
    value.length <= 35 &&
    value.toLowerCase().includes('still available')
  ) {
    return (
      "You don't need to ask if things are still available. Just write whatever you " +
      "would have said next - explain why you'd like it and when you could collect."
    )
  }

  return true
}

async function handleSend(callback) {
  // Ensure refs are set before submitting
  stateMachine.setRefs({
    form: form.value,
    chatButton: replyToPostChatButton.value,
    emailValidator: emailValidatorRef.value,
  })

  stateMachine.setReplySource('chat_reply_pane')
  await stateMachine.submit(callback)
}

function handleNewUserModalOk() {
  stateMachine.closeWelcomeModal()
}

function goBack() {
  // Navigate back to the message page
  if (props.messageId) {
    router.push(`/message/${props.messageId}`)
  } else {
    router.back()
  }
}
</script>

<style scoped lang="scss">
.chat-reply-pane {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: $color-white;
}

.reply-header {
  display: flex;
  align-items: center;
  padding: 12px 16px;
  background: $color-green--darker;
  color: white;
  gap: 12px;
  min-height: 56px;
}

.back-btn {
  background: none;
  border: none;
  color: white;
  padding: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  width: 36px;
  height: 36px;

  &:hover {
    background: rgba(255, 255, 255, 0.15);
  }
}

.reply-header-info {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
  min-width: 0;
}

.reply-header-avatar {
  flex-shrink: 0;
}

.reply-header-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.reply-header-name {
  font-weight: 600;
  font-size: 1rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.reply-header-item {
  font-size: 0.85rem;
  opacity: 0.9;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.reply-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  background: #e5ddd5; /* WhatsApp-style chat background */
}

.message-context {
  display: flex;
  justify-content: flex-start;
  margin-bottom: 12px;
}

.context-bubble {
  background: white;
  border-radius: 8px;
  padding: 8px;
  max-width: 85%;
  box-shadow: 0 1px 1px rgba(0, 0, 0, 0.13);
}

.context-photo {
  margin-bottom: 8px;
}

.context-photo-img {
  width: 100%;
  max-width: 250px;
  border-radius: 4px;
  display: block;
}

.context-text {
  strong {
    display: block;
    margin-bottom: 4px;
  }
}

.context-description {
  margin: 0;
  font-size: 0.9rem;
  color: $color-gray--dark;
}

.delivery-notice {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: $color-info-bg;
  color: $color-blue--bright;
  padding: 6px 12px;
  border-radius: 16px;
  font-size: 0.85rem;
  margin-bottom: 12px;
}

.reply-form-area {
  border-top: 1px solid $color-gray--light;
  background: white;
  padding: 12px 16px 16px;
}

.reply-form-field {
  margin-bottom: 12px;
}

.reply-form-label {
  display: block;
  font-weight: 600;
  font-size: 0.9rem;
  margin-bottom: 4px;
  color: $color-gray--dark;
}

.reply-textarea {
  width: 100%;
  border: 1px solid $color-gray--normal;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 1rem;
  resize: vertical;
  transition: border-color 0.15s;

  &:focus {
    outline: none;
    border-color: $color-green--darker;
    box-shadow: 0 0 0 2px rgba($color-green--darker, 0.15);
  }
}

.reply-form-hint {
  font-size: 0.8rem;
  color: $color-gray--dark;
  margin-top: 4px;
  margin-bottom: 0;
}

.reply-send-area {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.reply-send-btn {
  min-width: 160px;
}
</style>
