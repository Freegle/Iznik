<template>
  <b-modal
    :id="'newsConvertModal-' + newsfeed.id"
    ref="modal"
    scrollable
    title="Post this for them"
    size="lg"
    no-stacking
  >
    <template #default>
      <p>
        This posts an OFFER or WANTED <strong>as {{ posterName }}</strong
        >, not as you, so replies reach them and it shows up in their My Posts.
        We'll add a note on this chat telling them what you've done.
      </p>

      <b-form-group label="What kind of post?" class="mt-3">
        <!-- Plain radios, not a button group: with `buttons` the selected
             option renders identically to the unselected one here, so there is
             no way to tell what is about to be posted. -->
        <b-form-radio-group v-model="type" :options="typeOptions" stacked />
      </b-form-group>

      <b-form-group label="Item" class="mt-3">
        <b-form-input
          v-model="item"
          placeholder="e.g. dining chairs"
          maxlength="80"
        />
        <div class="text-muted small mt-1">
          Just the item, no location - we add their area automatically.
        </div>
      </b-form-group>

      <b-form-group label="Description" class="mt-3">
        <b-form-textarea v-model="body" rows="5" max-rows="12" />
      </b-form-group>

      <NoticeMessage v-if="!canSubmit" variant="warning" class="mt-3">
        Add an item name and a description before posting.
      </NoticeMessage>

      <div class="mt-3">
        <h5>Preview</h5>
        <b-card class="preview">
          <div class="fw-bold">{{ previewSubject }}</div>
          <div class="preline mt-2">{{ body }}</div>
        </b-card>
        <div class="text-muted small mt-1">
          Their area and postcode get added to the subject when it posts, the
          same as any other post.
        </div>
      </div>

      <!-- The note is left on a public thread in the member's name, so show the
           moderator what it says before they commit to it. Same component the
           thread renders, so the two cannot drift apart. -->
      <div class="mt-3">
        <h5>What goes on this chat</h5>
        <NewsConvertedNotice preview />
      </div>

      <NoticeMessage v-if="error" variant="danger" class="mt-3">
        {{ error }}
      </NoticeMessage>
    </template>

    <template #footer>
      <b-button variant="white" @click="hide"> Cancel </b-button>
      <SpinButton
        variant="primary"
        :disabled="!canSubmit"
        icon-name="share"
        label="Post it for them"
        @handle="submit"
      />
    </template>
  </b-modal>
</template>

<script setup>
import { computed, ref } from 'vue'
import NoticeMessage from '~/components/NoticeMessage'
import SpinButton from '~/components/SpinButton'
import { useOurModal } from '~/composables/useOurModal'
import { useNewsfeedStore } from '~/stores/newsfeed'

const props = defineProps({
  newsfeed: {
    type: Object,
    required: true,
  },
  posterName: {
    type: String,
    default: 'them',
  },
})

const emit = defineEmits(['posted'])

const { modal, hide } = useOurModal()
const newsfeedStore = useNewsfeedStore()

const typeOptions = [
  { text: 'OFFER - they are giving something away', value: 'Offer' },
  { text: 'WANTED - they are looking for something', value: 'Wanted' },
]

// Guess the type from how the post reads. "Looking for"/"need"/"wanted" means
// they want something; anything else is far more often a giveaway, which is
// also the safer default because an OFFER with no taker is harmless.
const guessedType = () => {
  const text = (props.newsfeed?.message || '').toLowerCase()
  return /\b(wanted|looking for|does anyone have|in need of|after a)\b/.test(
    text
  )
    ? 'Wanted'
    : 'Offer'
}

// Best guess at the item from the first line: ChitChat posts usually lead with
// what the thing is. The moderator corrects it before posting - this only has
// to save typing, not be right.
//
// The subject reads "OFFER: <item> (Area PC)", so the item has to be the thing
// itself. Left as the raw first sentence it produces subjects like
// "OFFER: Set of four dining chairs going spare if anyone wants them (Hove BN3)",
// which is why the trailing offer-speak and the length cap are here.
const guessedItem = () => {
  const first = (props.newsfeed?.message || '')
    .split('\n')
    .map((l) => l.trim())
    .find((l) => l.length > 0)

  if (!first) return ''

  let s = first
    .replace(/^(hi|hello|hiya|morning|afternoon|evening)\b[!,. ]*/i, '')
    .replace(
      /^(does anyone have|has anyone got|is anyone|looking for|i have|i've got|i'm after|free to a good home|wanted|offer|offering)\b[:, ]*/i,
      ''
    )
    // First sentence only.
    .replace(/[.!?].*$/s, '')
    .trim()

  // Drop the "...going spare if anyone wants them" tail: that describes the
  // offer, not the item.
  s = s
    .replace(
      /\s+\b(going spare|going free|free to collect|free to a good home|up for grabs|available|needs? collecting|for collection|to collect)\b.*$/i,
      ''
    )
    .replace(/\s+\b(if|which|that|as|because)\b.*$/i, '')
    .replace(/[,;:]\s*$/, '')
    .trim()

  // Long enough for "set of four dining chairs", short enough for a subject.
  const words = s.split(/\s+/).filter(Boolean)
  if (words.length > 6) {
    s = words.slice(0, 6).join(' ')
  }

  return s.slice(0, 60).trim()
}

const type = ref(guessedType())
const item = ref(guessedItem())
const body = ref(props.newsfeed?.message || '')
const error = ref(null)

const canSubmit = computed(
  () => item.value.trim().length > 0 && body.value.trim().length > 0
)

const previewSubject = computed(
  () => `${type.value.toUpperCase()}: ${item.value || '...'} (their area)`
)

async function submit(callback) {
  error.value = null

  try {
    await newsfeedStore.convertToPost(props.newsfeed.id, {
      type: type.value,
      item: item.value.trim(),
      body: body.value.trim(),
      userid: props.newsfeed.userid,
    })
    emit('posted')
    hide()
  } catch (e) {
    error.value =
      e?.message || "Sorry, that didn't post. Please try again in a moment."
  }

  callback?.()
}
</script>

<style scoped lang="scss">
.preline {
  white-space: pre-line;
}

.preview {
  background-color: $color-gray--lighter;
}
</style>
