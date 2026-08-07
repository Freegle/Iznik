<template>
  <div>
    <b-row>
      <b-col>
        <div class="media">
          <b-card border-variant="info" data-testid="chat-prompt">
            <b-card-text>
              <div class="preline forcebreak">{{ emessage }}</div>

              <!-- The posts themselves, rendered properly rather than described
                   in prose. An item name reads fine as "your dining chairs" and
                   badly as whatever someone actually typed, and nothing can tell
                   the two apart - so the wording names none of them and this
                   says exactly which, however many there are. -->
              <div v-if="posts.length" class="mt-2 mb-1">
                <ChatPromptPost
                  v-for="msgid in shownPosts"
                  :key="msgid"
                  :id="msgid"
                />
                <b-button
                  v-if="hiddenCount > 0"
                  variant="link"
                  size="sm"
                  class="ps-0"
                  @click="showAll = true"
                >
                  Show {{ hiddenCount }} more
                </b-button>
              </div>

              <!-- Free-input answer: the poster knows their own date, and
                   "by this weekend" is wrong for someone moving on the 14th. -->
              <div v-if="showOptions && dateOption" class="mt-3">
                <label :for="dateId" class="form-label small mb-1">
                  Gone by
                </label>
                <div class="d-flex flex-wrap align-items-start gap-2">
                  <b-form-input
                    :id="dateId"
                    v-model="chosenDate"
                    type="date"
                    :min="today"
                    :max="maxDate"
                    class="prompt-date"
                  />
                  <b-button
                    variant="primary"
                    :disabled="answering || !chosenDate"
                    :data-label="`Prompt: ${prompt.kind}: date`"
                    @click="answer(dateOption, chosenDate)"
                  >
                    {{ dateOption.label }}
                  </b-button>
                  <b-button
                    v-for="option in plainOptions"
                    :key="option.value"
                    :variant="option.variant || 'secondary'"
                    :disabled="answering"
                    :data-label="`Prompt: ${prompt.kind}: ${option.value}`"
                    @click="answer(option)"
                  >
                    {{ option.label }}
                  </b-button>
                </div>
              </div>

              <div
                v-else-if="showOptions"
                class="d-flex flex-wrap gap-2 mt-3"
                data-testid="chat-prompt-options"
              >
                <b-button
                  v-for="option in plainOptions"
                  :key="option.value"
                  :variant="option.variant || 'primary'"
                  :disabled="answering"
                  :data-label="`Prompt: ${prompt.kind}: ${option.value}`"
                  @click="answer(option)"
                >
                  {{ option.label }}
                </b-button>
              </div>

              <p
                v-else-if="answeredLabel"
                class="text-muted small mt-2 mb-0"
                data-testid="chat-prompt-answered"
              >
                You said: {{ answeredLabel }}
              </p>
            </b-card-text>
          </b-card>
        </div>
      </b-col>
    </b-row>
  </div>
</template>
<script setup>
import { computed, ref } from 'vue'
import { useRouter } from '#imports'
import { useChatMessageBase } from '~/composables/useChat'
import { useChatStore } from '~/stores/chat'
import { uid } from '~/composables/useId'

// A question from Freegle with tappable answers - "could you deliver?", "when do
// you need this gone?".
//
// The buttons are the whole point. Both of these used to be modals fired the
// instant someone finished posting, and both are now dead code, which is a fair
// verdict on that timing: at the moment of posting you are trying to finish, and
// logistics for an item nobody has asked about yet is noise. Asked hours later,
// on a post that has gone quiet, the same question is worth answering - and the
// answer changes what everyone browsing sees.
//
// There is no "this is an automated message" line on each card. It is said once,
// in the chat header, because in this conversation EVERY message is automated
// and repeating it per message is just noise.
//
// One question usually covers SEVERAL posts - Freegle groups a member's
// outstanding posts the way a clearance groups its items, rather than starting a
// thread per item. So the card lists them, and the answer applies to all of them.

const props = defineProps({
  chatid: {
    type: Number,
    required: true,
  },
  id: {
    type: Number,
    required: true,
  },
  pov: {
    type: Number,
    required: false,
    default: null,
  },
})

const chatStore = useChatStore()
const router = useRouter()
const dateId = uid('promptdate')

const { chatmessage, emessage } = useChatMessageBase(
  props.chatid,
  props.id,
  props.pov,
)

const answering = ref(false)

const prompt = computed(() => chatmessage.value?.prompt ?? null)
const options = computed(() => prompt.value?.options ?? [])

// The posts this question covers. Usually several - Freegle groups a member's
// outstanding posts rather than starting a thread per item.
const posts = computed(() => {
  const ids = prompt.value?.msgids ?? []
  if (ids.length) {
    return ids
  }

  // Prompts written before grouping carried a single refmsgid.
  return chatmessage.value?.refmsgid ? [chatmessage.value.refmsgid] : []
})

// Someone clearing a house can have a lot outstanding, and a wall of items is
// its own kind of unreadable. The rows are compact, so this can be generous
// enough that an ordinary member sees all of theirs without tapping.
const MAX_SHOWN = 5
const showAll = ref(false)
const shownPosts = computed(() =>
  showAll.value ? posts.value : posts.value.slice(0, MAX_SHOWN),
)
const hiddenCount = computed(() => posts.value.length - shownPosts.value.length)

// An option that takes a value from the member rather than being a fixed choice.
const dateOption = computed(() => options.value.find((o) => o.input === 'date'))
const plainOptions = computed(() => options.value.filter((o) => !o.input))

const today = computed(() =>
  new Date(Date.now()).toISOString().substring(0, 10),
)
const maxDate = computed(() => {
  const d = new Date(Date.now())
  d.setFullYear(d.getFullYear() + 1)
  return d.toISOString().substring(0, 10)
})

const chosenDate = ref(null)

// Once answered or expired there is nothing to tap. Expired prompts still show
// their text: a conversation with holes in it is more confusing than a stale
// question with no buttons.
const showOptions = computed(
  () =>
    Boolean(prompt.value) &&
    !prompt.value.answered &&
    !prompt.value.expired &&
    options.value.length > 0 &&
    // Support and moderators viewing someone else's chat are looking, not
    // answering on their behalf.
    props.pov === null,
)

const answeredLabel = computed(() => {
  if (!prompt.value?.answered) {
    return null
  }

  const chosen = options.value.find((o) => o.value === prompt.value.answer)

  // A picked date is its own label - there is no option text to look up.
  return chosen?.label ?? prompt.value.answer
})

async function answer(option, value) {
  if (answering.value) {
    return
  }

  answering.value = true

  try {
    await chatStore.answerPrompt(props.chatid, props.id, value ?? option.value)

    // Some options are really a way in to somewhere else - "Add a photo" records
    // the intent and then has to actually take you to the post.
    if (option.action === 'editmessage' && chatmessage.value?.refmsgid) {
      router.push('/mypost/' + chatmessage.value.refmsgid)
    }
  } finally {
    answering.value = false
  }
}
</script>
<style scoped lang="scss">
.prompt-date {
  max-width: 12rem;
}
</style>
