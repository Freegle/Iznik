<template>
  <b-modal
    ref="modal"
    scrollable
    title="What's your message about?"
    size="md"
    class="post-selection-modal"
    @hidden="$emit('hidden')"
  >
    <template #default>
      <p>
        If it's about one of {{ name }}'s posts, pick it so they know which you
        mean.
      </p>
      <div class="post-choices">
        <div
          v-for="post in posts"
          :key="'post-choice-' + post.id"
          class="form-check post-choice"
        >
          <input
            :id="'post-choice-' + post.id"
            v-model="choice"
            class="form-check-input"
            type="radio"
            name="post-choice"
            :value="post.id"
          />
          <label :for="'post-choice-' + post.id" class="form-check-label">
            <span
              class="post-choice__type"
              :class="'post-choice__type--' + post.type.toLowerCase()"
            >
              {{ post.type === 'Offer' ? 'OFFER' : 'WANTED' }}
            </span>
            {{ itemOf(post) }}
          </label>
        </div>
        <div class="form-check post-choice">
          <input
            id="post-choice-other"
            v-model="choice"
            class="form-check-input"
            type="radio"
            name="post-choice"
            value="other"
          />
          <label for="post-choice-other" class="form-check-label">
            Something else
          </label>
        </div>
      </div>
      <div v-if="choice && choice !== 'other'" class="mt-3">
        <label for="post-choice-text" class="fw-bold">Your message</label>
        <b-form-textarea
          id="post-choice-text"
          v-model="text"
          rows="4"
          :placeholder="placeholder"
          :class="{ 'is-invalid': noText }"
          class="mt-1"
        />
        <p v-if="noText" class="text-danger small mb-0 mt-1">
          Please say something about the post.
        </p>
      </div>
      <NoticeMessage v-if="error" variant="danger" class="mt-3">
        {{ error }}
      </NoticeMessage>
    </template>
    <template #footer>
      <b-button
        variant="white"
        class="me-2"
        :disabled="sending"
        @click="cancel"
      >
        Cancel
      </b-button>
      <b-button variant="primary" :disabled="!choice || sending" @click="go">
        {{ sending ? 'Sending...' : 'Continue' }}
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import { ref, computed } from 'vue'
import NoticeMessage from '~/components/NoticeMessage'
import { useOurModal } from '~/composables/useOurModal'

// Shown when somebody presses Message on a profile whose owner has posts up.
// Most such messages are about one of the posts, and a message sent with no
// post attached leaves the poster guessing which one (#11). Choosing a post
// sends the message as a reply to it, the same as replying from the post.

const props = defineProps({
  // The person's open posts: { id, type, subject }.
  posts: {
    type: Array,
    required: true,
  },
  name: {
    type: String,
    required: true,
  },
  // The parent does the sending and reports back: the question stays open
  // until the chat is on screen, so a failed send never loses the message.
  sending: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: null,
  },
})

const emit = defineEmits(['select', 'other', 'hidden'])

const { modal, hide } = useOurModal()

const choice = ref(null)
const text = ref('')
const noText = ref(false)

const chosen = computed(() =>
  props.posts.find((post) => post.id === choice.value)
)

const placeholder = computed(() =>
  chosen.value?.type === 'Offer'
    ? 'e.g. Is this still available? I could collect on Saturday.'
    : 'e.g. I have one of these you could have.'
)

// The subject carries its own "OFFER:" prefix and a location suffix; the type
// is shown separately, so leave just the item.
function itemOf(post) {
  return (post.subject || '').replace(/^\s*(OFFER|WANTED)\s*:\s*/i, '').trim()
}

function go() {
  if (!choice.value || props.sending) {
    return
  }

  if (choice.value === 'other') {
    emit('other')
    return
  }

  const trimmed = text.value.trim()

  if (!trimmed) {
    noText.value = true
    return
  }

  noText.value = false
  emit('select', { id: choice.value, text: trimmed })
}

function cancel() {
  hide()
}
</script>
<style scoped lang="scss">
.post-choice {
  padding: 0.5rem 0.75rem 0.5rem 2rem;
  border: 1px solid $color-gray--light;
  border-radius: 0.5rem;
  margin-bottom: 0.5rem;

  .form-check-input {
    margin-left: -1.25rem;
  }

  .form-check-label {
    cursor: pointer;
  }
}

.post-choice__type {
  display: inline-block;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 0.1rem 0.4rem;
  border-radius: 0.25rem;
  color: $color-white;
  background-color: $color-green--darker;
  margin-right: 0.25rem;
}

.post-choice__type--wanted {
  background-color: $color-blue--bright;
}
</style>
