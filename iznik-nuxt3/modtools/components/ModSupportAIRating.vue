<template>
  <div class="ai-rating" :class="{ 'ai-rating--unrated': !current }">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span v-if="!compact" class="small ai-rating__ask">
        <template v-if="!current">
          <strong>Was this answer right?</strong>
          Your thumbs up or down tells us where the helper needs improving.
        </template>
        <template v-else-if="saved">Thanks - noted.</template>
        <template v-else>Your rating:</template>
      </span>
      <b-button
        size="sm"
        :variant="current === 1 ? 'success' : 'outline-success'"
        :disabled="saving"
        :aria-pressed="current === 1"
        title="Good answer"
        data-testid="ai-rating-up"
        @click="choose(1)"
      >
        <v-icon icon="thumbs-up" />
      </b-button>
      <b-button
        size="sm"
        :variant="current === -1 ? 'danger' : 'outline-danger'"
        :disabled="saving"
        :aria-pressed="current === -1"
        title="Poor answer"
        data-testid="ai-rating-down"
        @click="choose(-1)"
      >
        <v-icon icon="thumbs-down" />
      </b-button>
    </div>
    <div v-if="showComment" class="mt-2">
      <b-form-textarea
        v-model="draft"
        rows="2"
        max-rows="6"
        size="sm"
        :placeholder="
          current === -1
            ? 'What was wrong or missing? The more specific, the easier it is to fix.'
            : 'Anything worth noting? (optional)'
        "
        data-testid="ai-rating-comment"
      />
      <div class="d-flex gap-2 mt-1">
        <b-button
          size="sm"
          variant="primary"
          :disabled="saving"
          data-testid="ai-rating-save"
          @click="saveComment"
        >
          Save comment
        </b-button>
        <b-button size="sm" variant="link" @click="showComment = false">
          Skip
        </b-button>
      </div>
    </div>
    <div v-if="error" class="small text-danger mt-1">{{ error }}</div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import api from '~/api'

const props = defineProps({
  runId: {
    type: Number,
    required: true,
  },
  rating: {
    type: Number,
    default: null,
  },
  comment: {
    type: String,
    default: '',
  },
  // Buttons only, for the SysAdmin list.
  compact: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['rated'])

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const current = ref(props.rating)
const draft = ref(props.comment || '')
const showComment = ref(false)
const saving = ref(false)
const saved = ref(false)
const error = ref('')

async function save(rating, comment) {
  saving.value = true
  error.value = ''
  try {
    await apiInstance.supportai.rate(props.runId, rating, comment)
    current.value = rating || null
    emit('rated', { rating: current.value, comment })
    return true
  } catch (e) {
    error.value = 'Could not save your rating - please try again.'
    return false
  } finally {
    saving.value = false
  }
}

// Clicking the chosen thumb again takes the rating back off.
async function choose(rating) {
  const next = current.value === rating ? 0 : rating
  saved.value = false
  if ((await save(next)) && !props.compact) {
    showComment.value = next !== 0
  }
}

async function saveComment() {
  if (await save(current.value || 0, draft.value)) {
    showComment.value = false
    saved.value = true
  }
}
</script>

<style scoped lang="scss">
.ai-rating {
  margin-top: 0.5rem;
}

.ai-rating--unrated .ai-rating__ask {
  color: #495057;
}
</style>
