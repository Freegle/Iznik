<template>
  <div
    class="ux-prompt"
    data-testid="ux-card"
    :data-prompt-id="prompt.id"
    :data-kind="prompt.kind"
    :data-step="revealing ? 'reveal' : step"
  >
    <h2
      :id="headingId"
      ref="heading"
      class="ux-question header--size5"
      tabindex="-1"
    >
      {{ question }}
    </h2>

    <div v-if="revealing" class="ux-reveal" data-testid="reveal">
      <!-- Plain text from the prompt's settings: interpolated, so escaped. -->
      <p class="ux-reveal-text" data-testid="reveal-text">{{ revealText }}</p>
      <nuxt-link
        v-if="revealLink"
        :to="revealLink"
        class="ux-reveal-link"
        data-testid="reveal-link"
      >
        Show me
      </nuxt-link>
      <div class="ux-actions">
        <span />
        <b-button
          variant="success"
          data-testid="reveal-next"
          @click="emit('next')"
        >
          Next
        </b-button>
      </div>
    </div>

    <template v-else>
      <div
        v-if="prompt.kind === 'survey'"
        class="ux-options"
        role="group"
        :aria-labelledby="headingId"
        :data-testid="onStepTwo ? 'group-worst' : 'group-best'"
      >
        <b-button
          v-for="opt in stepOptions"
          :key="(onStepTwo ? 'worst-' : 'best-') + opt.key"
          class="ux-option"
          :data-testid="`${onStepTwo ? 'worst' : 'best'}-${opt.key}`"
          :variant="picked(opt.key) ? 'primary' : 'outline-primary'"
          :aria-pressed="picked(opt.key) ? 'true' : 'false'"
          @click="onStepTwo ? pickWorst(opt.key) : pickBest(opt.key)"
        >
          {{ opt.label }}
        </b-button>
      </div>

      <template v-else-if="prompt.kind === 'open'">
        <b-form-textarea
          :id="textId"
          v-model="text"
          class="ux-text"
          data-testid="open-text"
          rows="3"
          no-resize
          :maxlength="MAX_TEXT"
          :placeholder="settings.placeholder"
          :aria-labelledby="headingId"
          :aria-describedby="privacyId"
          @update:model-value="typed"
        />
        <p :id="privacyId" class="ux-privacy">
          {{ PRIVACY }}
          <span v-if="tooShort" class="ux-more" data-testid="open-more">
            Please write a little more.
          </span>
        </p>
      </template>

      <!-- Awareness is Yes, No and Not sure in one row; any other kind this card does not know
           is a plain list of choices, never a blank. -->
      <div
        v-else
        :class="prompt.kind === 'awareness' ? 'ux-row' : 'ux-options'"
        role="group"
        :aria-labelledby="headingId"
      >
        <b-button
          v-for="opt in choiceOptions"
          :key="opt.key"
          :class="prompt.kind === 'awareness' ? '' : 'ux-option'"
          :data-testid="`choice-${opt.key}`"
          :variant="choice === opt.key ? 'primary' : 'outline-primary'"
          :aria-pressed="choice === opt.key ? 'true' : 'false'"
          @click="choice = opt.key"
        >
          {{ opt.label }}
        </b-button>
      </div>

      <!-- On a write card Send sits under the box, so the on-screen keyboard cannot hide it. -->
      <div v-if="prompt.kind === 'open'" class="ux-actions">
        <b-button
          variant="link"
          data-testid="skip-prompt"
          :disabled="skipping"
          @click="skip"
        >
          Skip
        </b-button>
        <span />
        <SpinButton
          data-testid="submit-prompt"
          variant="success"
          icon-name="check"
          label="Send"
          :disabled="!complete"
          @handle="submit"
        />
      </div>

      <div
        v-if="waysOut.length"
        class="ux-row"
        role="group"
        :aria-label="waysLabel"
      >
        <b-button
          v-for="opt in waysOut"
          :key="opt.key"
          :data-testid="`escape-${opt.key}`"
          :variant="escape === opt.key ? 'primary' : 'outline-secondary'"
          :aria-pressed="escape === opt.key ? 'true' : 'false'"
          @click="pickEscape(opt.key)"
        >
          {{ opt.label }}
        </b-button>
      </div>

      <template v-if="prompt.kind !== 'open'">
        <template v-if="commentOpen">
          <b-form-textarea
            :id="commentId"
            v-model="comment"
            class="ux-comment"
            data-testid="comment-text"
            rows="2"
            no-resize
            maxlength="1000"
            placeholder="One sentence is enough."
            :aria-label="`A comment about: ${question}`"
            :aria-describedby="privacyId"
          />
          <p :id="privacyId" class="ux-privacy">{{ PRIVACY }}</p>
        </template>

        <div class="ux-actions">
          <b-button
            v-if="onStepTwo"
            variant="link"
            data-testid="step-back"
            @click="back"
          >
            Back
          </b-button>
          <b-button
            v-else
            variant="link"
            data-testid="skip-prompt"
            :disabled="skipping"
            @click="skip"
          >
            Skip
          </b-button>
          <b-button
            v-if="!commentOpen"
            variant="link"
            class="ux-middle"
            data-testid="comment-toggle"
            @click="openComment"
          >
            Add a comment
          </b-button>
          <span v-else />
          <b-button
            v-if="primaryIsNext"
            variant="success"
            data-testid="step-next"
            :disabled="!complete"
            @click="goNext"
          >
            Next
          </b-button>
          <SpinButton
            v-else
            data-testid="submit-prompt"
            variant="success"
            icon-name="check"
            label="Send"
            :disabled="!complete"
            @handle="submit"
          />
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import SpinButton from './SpinButton'
import { useMicroVolunteeringStore } from '~/stores/microvolunteering'

// One question for microvolunteers, on one phone screen: a survey of which part of Freegle works best
// (with an optional second question), a follow-up that asks which of three problems has been the
// biggest, an awareness check, or an open question answered in the member's own words. A kind this
// card does not know is drawn as a plain list of choices. There is no introduction, no paragraph under
// the question and no picture. docs/developers/reference/ux-member-surveys.md.
//
// Answers, as the API takes them:
//   awareness     {choice: 'yes' | 'no' | 'not_sure', shown}
//   survey        {best, worst?, shown} or {choice: 'none', shown} or {choice: 'dont_understand'}
//   open          {text} (3 to 1000 characters) or {choice: 'nothing' | 'dont_understand'}
//   any kind, skipped  {skipped: true}
// plus `comments` on every kind but open. "shown" is the option keys of the first screen in the order
// this member saw them, so the tab can say how often each was on screen. The server decides which few
// options a survey shows (prompt.display); this component shows exactly those, in that order. Nothing
// is sent between the two steps of the area survey: one POST goes out at the end.
const props = defineProps({
  prompt: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['next', 'skipped'])

const store = useMicroVolunteeringStore()

const MIN_TEXT = 3
const MAX_TEXT = 1000
const PRIVACY = 'No names, addresses or phone numbers.'

// What an open question's ways out are called when the prompt lists none.
const OPEN_ESCAPES = [
  { key: 'nothing', label: "I can't think of one" },
  { key: 'dont_understand', label: "I don't understand" },
]

const idBase = `ux-prompt-${props.prompt.id ?? 'x'}`
const headingId = `${idBase}-question`
const textId = `${idBase}-text`
const commentId = `${idBase}-comment`
const privacyId = `${idBase}-privacy`

const heading = ref(null)
const choice = ref(null)
const best = ref(null)
const worst = ref(null)
const escape = ref(null)
const comment = ref('')
const commentOpen = ref(false)
const text = ref('')
const step = ref(1)
const skipping = ref(false)
const revealing = ref(false)

const settings = computed(() => props.prompt.settings || {})

// A survey is tallied, so the order the options come in would add a position bias: a member reading
// top to bottom favours the first. The server sends the few to show (display) already in a random
// order. An older prompt without display falls back to every option, shuffled once when it asks for
// that.
function shuffled(list) {
  const out = [...list]
  for (let i = out.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[out[i], out[j]] = [out[j], out[i]]
  }
  return out
}

function surveyOptions() {
  const all = props.prompt.options || []
  if (props.prompt.kind !== 'survey') return all
  const display = props.prompt.display
  if (Array.isArray(display) && display.length) {
    const byKey = new Map(all.map((o) => [o.key, o]))
    return display.map((k) => byKey.get(k)).filter(Boolean)
  }
  return props.prompt.settings?.randomise ? shuffled(all) : all
}

const options = ref(surveyOptions())

// Only the area survey has a second question: it asks it with the label the prompt gives.
const hasSecondStep = computed(
  () => props.prompt.kind === 'survey' && Boolean(settings.value.worst_label)
)
const onStepTwo = computed(() => hasSecondStep.value && step.value === 2)
const primaryIsNext = computed(
  () => hasSecondStep.value && step.value === 1 && !escape.value
)

const question = computed(() =>
  onStepTwo.value ? settings.value.worst_label : props.prompt.question
)

// The second question offers the other options: the first pick is left out.
const stepOptions = computed(() =>
  onStepTwo.value
    ? options.value.filter((o) => o.key !== best.value)
    : options.value
)

const choiceOptions = computed(() => props.prompt.options || [])

// The ways out sit in one row of equal width, and a survey has them on its first question only.
const waysOut = computed(() => {
  if (props.prompt.kind === 'survey') {
    return onStepTwo.value
      ? []
      : [
          { key: 'none', label: settings.value.none_label || 'None of these' },
          { key: 'dont_understand', label: "I don't understand" },
        ]
  }
  if (props.prompt.kind === 'open') {
    const given = (props.prompt.options || []).filter((o) =>
      ['nothing', 'dont_understand'].includes(o.key)
    )
    return given.length ? given : OPEN_ESCAPES
  }
  return []
})
const waysLabel = 'Other ways to answer'

const trimmed = computed(() => text.value.trim())
const enough = computed(() => trimmed.value.length >= MIN_TEXT)
const tooShort = computed(() => trimmed.value.length > 0 && !enough.value)

// Whether the main button is ready. A second pick is optional, so the second question can always be
// sent once a first pick was made.
const complete = computed(() => {
  if (props.prompt.kind === 'survey') {
    return Boolean(escape.value || best.value)
  }
  if (props.prompt.kind === 'open') {
    return Boolean(escape.value || enough.value)
  }
  return Boolean(choice.value)
})

const canComment = computed(() => props.prompt.kind !== 'open')

// The key list sent as "shown": what was on screen in the first screen, in screen order.
function shownKeys() {
  const list =
    props.prompt.kind === 'survey' ? options.value : choiceOptions.value
  return list.map((o) => o.key)
}

// A path on this site: one slash, then nothing a browser reads as another site. A browser reads a
// backslash in a link as a slash, and drops a tab or a new line inside it, so "/\evil.example" and
// "/<tab>/evil.example" both go to another site. A control character anywhere is refused.
function isSitePath(link) {
  return (
    typeof link === 'string' &&
    link.startsWith('/') &&
    link[1] !== '/' &&
    link[1] !== '\\' &&
    ![...link].some((c) => c.charCodeAt(0) < 32)
  )
}

// After answering an awareness question the member is told the answer. Only a site path is followed
// (settings come from an operator, but a link off the site has no business here).
const revealText = computed(() => String(settings.value.reveal || ''))
const revealLink = computed(() => {
  const l = settings.value.link
  return isSitePath(l) ? l : ''
})

function picked(key) {
  return onStepTwo.value ? worst.value === key : best.value === key
}

function focusHeading() {
  nextTick(() => heading.value?.focus())
}

// Picking a first pick is picking an answer, so it takes back a way out chosen earlier, and a second
// pick that is the same option.
function pickBest(key) {
  best.value = key
  escape.value = null
  if (worst.value === key) worst.value = null
}

// The second pick can be taken back, because it is optional.
function pickWorst(key) {
  worst.value = worst.value === key ? null : key
}

// A way out is an answer in its own right: it takes back the picks, or the typed words.
function pickEscape(key) {
  escape.value = escape.value === key ? null : key
  if (!escape.value) return
  best.value = null
  worst.value = null
  if (props.prompt.kind === 'open') text.value = ''
}

// Typing is an answer, so it takes back a way out chosen earlier.
function typed(value) {
  if (value) escape.value = null
}

function goNext() {
  if (!best.value) return
  step.value = 2
  focusHeading()
}

function back() {
  step.value = 1
  focusHeading()
}

function openComment() {
  commentOpen.value = true
}

function buildAnswer() {
  if (props.prompt.kind === 'open') {
    return escape.value
      ? { choice: escape.value }
      : { text: trimmed.value.slice(0, MAX_TEXT) }
  }
  if (props.prompt.kind !== 'survey') {
    return { choice: choice.value, shown: shownKeys() }
  }
  if (escape.value === 'dont_understand') return { choice: 'dont_understand' }
  if (escape.value) return { choice: escape.value, shown: shownKeys() }
  const answer = { best: best.value, shown: shownKeys() }
  if (worst.value && worst.value !== best.value) answer.worst = worst.value
  return answer
}

async function submit(callback) {
  if (!complete.value) {
    callback?.()
    return
  }
  const answer = buildAnswer()
  try {
    await store.respond({
      ux_prompt_id: props.prompt.id,
      ux_answer: answer,
      comments: canComment.value
        ? comment.value.trim() || undefined
        : undefined,
    })
  } catch (e) {
    // The answer is a gauge, not a vote: losing one is fine, and the API layer has already
    // reported the failure. Do not leave the member stuck on a question that cannot be sent.
  } finally {
    callback?.()
  }
  if (props.prompt.kind === 'awareness' && revealText.value) {
    // Tell them the answer; Next moves on.
    revealing.value = true
    focusHeading()
    return
  }
  emit('next')
}

// Skipping is recorded, so the same question is not offered straight back. If it were not, the
// picker would hand out the identical prompt again, every time, ahead of the real review work.
async function skip() {
  if (skipping.value) return
  skipping.value = true
  try {
    await store.respond({
      ux_prompt_id: props.prompt.id,
      ux_answer: { skipped: true },
    })
  } catch (e) {
    // Moving on matters more than the record.
  }
  emit('skipped')
  emit('next')
}
</script>

<style scoped lang="scss">
// One phone screen: the gaps are 4px and the buttons 44px, which is what makes the longest question
// with its longest answers fit 390 by 660 in a font about 10 percent wider than a phone's. Only gaps and
// padding give way when a state is too tall (the Playwright test measures every state); a button is
// never under 44px and the words and their limits do not move.
.ux-prompt {
  max-width: 34rem;
  margin: 0 auto;
}

.ux-question {
  font-size: 1.25rem;
  line-height: 1.2;
  margin: 0 0 4px;
  font-weight: 700;
  outline: none;
}

.ux-options {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

// The ways out, and Yes, No and Not sure: one row of equal width.
.ux-row {
  display: flex;
  gap: 4px;
  margin-top: 4px;

  :deep(.btn) {
    flex: 1 1 0;
    min-width: 0;
  }
}

// assets/css/buttons.scss keeps every button on one line with !important, and a problem's title is most
// of a sentence, so the text wraps inside the button here and needs !important too.
.ux-prompt :deep(.btn) {
  min-height: 44px;
  font-size: 15px;
  line-height: 1.25;
  padding: 6px 10px;
  white-space: normal !important;
  text-align: start;
}

.ux-option {
  width: 100%;
}

.ux-text,
.ux-comment {
  margin-top: 4px;
  display: block;
  width: 100%;
  font-size: 15px;
  line-height: 1.4;
  padding: 6px 10px;
}

.ux-privacy {
  font-size: 13px;
  line-height: 1.3;
  margin: 4px 0 0;
  color: #6c757d;
}

.ux-more {
  display: block;
  color: #b02a37;
}

// Send and Skip stay in view on a short screen: the body scrolls under this row.
.ux-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  margin-top: 4px;
  position: sticky;
  bottom: 0;
  background: #fff;
  z-index: 1;

  :deep(.btn-link) {
    flex: 0 0 auto;
  }

  // Add a comment fills the space between the two ends.
  :deep(.ux-middle) {
    flex: 1 1 auto;
    text-align: center;
  }
}

.ux-reveal-text {
  font-size: 15px;
  line-height: 1.5;
  margin: 0 0 4px;
}

.ux-reveal-link {
  display: inline-block;
  padding: 10px 0;
  font-size: 15px;
}
</style>
