<template>
  <div class="voicepost" :class="{ 'has-sticky-ad': stickyAdRendered }">
    <div class="voicepost__card">
      <!-- STEP 1 - PHOTO first (PhotoUploader has its own Skip) -->
      <div v-if="phase === 'photo'" class="voicepost__stage">
        <h1 class="voicepost__title">Add a photo</h1>
        <p class="voicepost__lead">
          Show your item - a clear photo helps it get snapped up.
        </p>
        <div class="photo-wrap">
          <PhotoUploader
            v-model="attachments"
            type="Message"
            :recognise="false"
            @skip="advanceFromPhoto"
          />
        </div>
        <b-button
          v-if="hasPhotos"
          variant="primary"
          size="lg"
          class="w-100"
          @click="advanceFromPhoto"
        >
          Next <v-icon icon="arrow-right" />
        </b-button>
      </div>

      <!-- STEP 2 - choose voice or keyboard -->
      <div v-else-if="phase === 'choose'" class="voicepost__stage">
        <img
          v-if="primaryPhoto"
          :src="primaryPhoto"
          class="idle-photo"
          alt="Your item"
        />
        <h1 class="voicepost__title">How do you want to describe it?</h1>
        <p class="voicepost__lead">
          Talk to us, or type it in - whatever's easier.
        </p>
        <div class="choice-grid">
          <button class="choice-btn choice-btn--voice" @click="chooseVoice">
            <svg
              viewBox="0 0 24 24"
              class="choice-btn__icon"
              aria-hidden="true"
            >
              <path
                fill="currentColor"
                d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"
              />
              <path
                fill="currentColor"
                d="M18 12a1 1 0 1 0-2 0 4 4 0 0 1-8 0 1 1 0 1 0-2 0 6 6 0 0 0 5 5.91V20H8a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2h-3v-2.09A6 6 0 0 0 18 12Z"
              />
            </svg>
            <span class="choice-btn__label">Say it</span>
            <span class="choice-btn__sub">Describe it out loud</span>
          </button>
          <button class="choice-btn choice-btn--type" @click="chooseType">
            <svg
              viewBox="0 0 24 24"
              class="choice-btn__icon"
              aria-hidden="true"
            >
              <path
                fill="currentColor"
                d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm1 3v2h2V8H5Zm4 0v2h2V8H9Zm4 0v2h2V8h-2Zm4 0v2h2V8h-2ZM5 11v2h2v-2H5Zm4 0v2h2v-2H9Zm4 0v2h2v-2h-2Zm4 0v2h2v-2h-2ZM7 14v2h10v-2H7Z"
              />
            </svg>
            <span class="choice-btn__label">Type it</span>
            <span class="choice-btn__sub">Fill in the form</span>
          </button>
        </div>
      </div>

      <!-- VOICE: the big mic button -->
      <div v-else-if="phase === 'idle'" class="voicepost__stage">
        <img
          v-if="primaryPhoto"
          :src="primaryPhoto"
          class="idle-photo"
          alt="Your item"
        />
        <h1 class="voicepost__title">Tell us about your item</h1>
        <p class="voicepost__lead">
          Tap the microphone and just describe what you're giving away, in your
          own words. We'll write it down for you to check.
        </p>

        <button
          class="mic-btn"
          aria-label="Start recording"
          @click="startRecording"
        >
          <svg viewBox="0 0 24 24" class="mic-btn__icon" aria-hidden="true">
            <path
              fill="currentColor"
              d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"
            />
            <path
              fill="currentColor"
              d="M18 12a1 1 0 1 0-2 0 4 4 0 0 1-8 0 1 1 0 1 0-2 0 6 6 0 0 0 5 5.91V20H8a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2h-3v-2.09A6 6 0 0 0 18 12Z"
            />
          </svg>
        </button>
        <p class="voicepost__hint">
          Press to start &middot; press again to stop
        </p>
      </div>

      <!-- RECORDING: no live transcript - we transcribe once you stop -->
      <div v-else-if="phase === 'recording'" class="voicepost__stage">
        <div class="recording-head">
          <span class="recording-dot" />
          <span class="recording-timer">{{ formattedElapsed }}</span>
        </div>
        <p class="voicepost__lead">
          Listening&hellip; tell us all about it, then tap Done.
        </p>

        <div class="equalizer" aria-hidden="true">
          <span /><span /><span /><span /><span /><span /><span />
        </div>

        <button class="stop-btn" @click="stopRecording">
          <span class="stop-btn__square" aria-hidden="true" />
          Done
        </button>
      </div>

      <!-- FINISHING: single transcription (no rewrite) -->
      <div v-else-if="phase === 'finishing'" class="voicepost__stage">
        <b-spinner class="voicepost__spinner" />
        <p class="voicepost__lead">Writing down what you said&hellip;</p>
      </div>

      <!-- REVIEW: editable result -->
      <div v-else-if="phase === 'review'" class="voicepost__review">
        <h1 class="voicepost__title voicepost__title--sm">
          Here's your post - have a read
        </h1>
        <p class="voicepost__lead voicepost__lead--tight">
          Here's what you said, word for word. Change anything you like.
        </p>

        <img
          v-if="primaryPhoto"
          :src="primaryPhoto"
          class="review-photo"
          alt="Your item"
        />

        <label class="field-label" for="vp-title">Item</label>
        <b-form-input id="vp-title" v-model="title" class="field-input" />

        <label class="field-label" for="vp-desc">Description</label>
        <b-form-textarea
          id="vp-desc"
          v-model="description"
          rows="5"
          max-rows="12"
          class="field-input"
        />

        <div v-if="audioUrl" class="playback">
          <button class="playback__btn" @click="togglePlay">
            <span aria-hidden="true">{{ playing ? '❚❚' : '▶' }}</span>
            {{ playing ? 'Pause' : 'Play your recording' }}
          </button>
          <audio ref="audioEl" :src="audioUrl" @ended="playing = false" />
        </div>

        <button class="raw-toggle" type="button" @click="showRaw = !showRaw">
          {{ showRaw ? 'Hide' : 'Show' }} exactly what you said
        </button>
        <p v-if="showRaw" class="raw-transcript">"{{ rawTranscript }}"</p>

        <p class="recording-note">
          Your recording is only used to write this down, and isn't played to
          anyone else - see our
          <nuxt-link no-prefetch to="/privacy">privacy policy</nuxt-link>.
        </p>

        <div class="review-actions">
          <b-button variant="primary" size="lg" class="w-100" @click="postIt">
            Looks good - post it
          </b-button>
          <button class="restart-link" type="button" @click="reRecord">
            Re-record
          </button>
        </div>
      </div>

      <!-- ERROR -->
      <div v-else-if="phase === 'error'" class="voicepost__stage">
        <p class="voicepost__lead voicepost__error">{{ errorMessage }}</p>
        <b-button
          variant="primary"
          size="lg"
          class="w-100"
          @click="chooseVoice"
        >
          Try again
        </b-button>
      </div>
    </div>

    <!-- Explain the mic permission BEFORE the browser's own prompt, so it isn't
         a surprise and they know the mic is only used when recording a post. -->
    <b-modal
      v-model="showMicExplainer"
      title="We'll need your microphone"
      ok-title="OK, ask me"
      ok-variant="primary"
      cancel-title="Not now"
      @ok="confirmMicAndRecord"
    >
      <p class="mb-2">
        To hear your description, your browser will now ask for permission to
        use your microphone.
      </p>
      <p class="mb-0">
        We only use it while you're recording a post like this - never in the
        background - and you can stop any time.
      </p>
    </b-modal>
  </div>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { useNuxtApp, useRouter } from '#imports'
import { useComposeStore } from '~/stores/compose'
import { useAuthStore } from '~/stores/auth'
import { useMiscStore } from '~/stores/misc'
import { useComposeChoice } from '~/composables/useComposeChoice'
import { useClientLog } from '~/composables/useClientLog'

// Standalone route. Flow: add a photo, then choose voice or keyboard. For voice,
// describe the item aloud (audio streams up while you talk) and review the tidied
// title/description. The photo lives in the compose store, so choosing "Type it"
// carries it into the existing typed form. Transcription happens once, on stop.

const { $api } = useNuxtApp()
const router = useRouter()
const composeStore = useComposeStore()
const authStore = useAuthStore()
const miscStore = useMiscStore()
const { recordMethodShown, recordMethodChosen } = useComposeChoice()
// Rich, session-correlated analytics for the whole funnel (see useClientLog).
const { action: logVpEvent } = useClientLog()

const phase = ref('photo') // photo | choose | idle | recording | finishing | review | error
const errorMessage = ref('')
const showMicExplainer = ref(false)
const stickyAdRendered = computed(() => miscStore.stickyAdRendered)

// Instrumentation state: lets us report duration, edit-or-not, re-records and
// time-on-review so we can judge whether people trust and complete the flow.
const originalTitle = ref('')
const originalDescription = ref('')
let recordDurationS = 0
let reviewShownAt = 0
let rerecordCount = 0
let posted = false
let played = false

// Photo, held in the compose store (shared with the typed flow), mirroring
// pages/give/mobile/photos.vue so either branch continues the same draft.
function getOrCreateMessageId() {
  const myid = authStore.user?.id
  const existing = composeStore.all.filter(
    (m) =>
      m.type === 'Offer' &&
      (!m.savedBy || m.savedBy === myid) &&
      composeStore.messages[m.id]
  )
  const id = existing.length > 0 ? existing[0].id : composeStore.add()
  composeStore.setType({ id, type: 'Offer' })
  return id
}
const messageId = ref(getOrCreateMessageId())
const attachments = computed({
  get() {
    return composeStore.attachments(messageId.value) || []
  },
  set(v) {
    composeStore.setAttachmentsForMessage(messageId.value, v)
  },
})
const hasPhotos = computed(() => attachments.value.length > 0)
const primaryPhoto = computed(() => {
  const a = attachments.value?.[0]
  return a ? a.preview || a.path || a.paththumb || null : null
})

// Recording state
const session = ref(null)
const elapsed = ref(0)
let mediaRecorder = null
let stream = null
let chunks = []
let mimeType = 'audio/webm'
let sendQueue = Promise.resolve()
let timer = null

// Cap recordings so a forgotten mic can't stream forever.
const MAX_SECONDS = 120

// Review state
const title = ref('')
const description = ref('')
const rawTranscript = ref('')
const showRaw = ref(false)
const audioBlob = ref(null)
const audioUrl = ref(null)
const audioEl = ref(null)
const playing = ref(false)

const formattedElapsed = computed(() => {
  const m = Math.floor(elapsed.value / 60)
  const s = elapsed.value % 60
  return `${m}:${s.toString().padStart(2, '0')}`
})

function advanceFromPhoto() {
  phase.value = 'choose'
  recordMethodShown()
  // How often the choice was shown, and whether they'd added a photo first.
  logVpEvent('voicepost_choice_shown', { has_photo: hasPhotos.value })
}

function chooseVoice() {
  recordMethodChosen('voice')
  logVpEvent('voicepost_method_chosen', {
    method: 'voice',
    has_photo: hasPhotos.value,
  })
  phase.value = 'idle'
}

function chooseType() {
  // Keyboard branch: continue in the existing typed form (photo already added).
  recordMethodChosen('keyboard')
  logVpEvent('voicepost_method_chosen', {
    method: 'keyboard',
    has_photo: hasPhotos.value,
  })
  router.push('/give/mobile/details')
}

function pickMimeType() {
  const candidates = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/mp4', // Safari
    'audio/ogg;codecs=opus',
  ]
  if (typeof MediaRecorder === 'undefined') return ''
  for (const c of candidates) {
    if (MediaRecorder.isTypeSupported(c)) return c
  }
  return ''
}

// Do we already have microphone permission? Uses the Permissions API so we can
// tell "granted" from "will be prompted" and only explain in the latter case.
async function hasMicPermission() {
  try {
    if (navigator.permissions?.query) {
      const status = await navigator.permissions.query({ name: 'microphone' })
      return status.state === 'granted'
    }
  } catch (e) {
    // Permissions API missing or doesn't recognise 'microphone' - fall through
    // and explain-then-ask, which is the safe default.
  }
  return false
}

// The mic button. If we don't already have permission, explain up front that the
// browser is about to ask and that the mic is only ever used while recording a
// post like this - then start once they're happy. If permission was granted
// before, go straight to recording.
async function startRecording() {
  if (await hasMicPermission()) {
    await beginRecording()
    return
  }
  logVpEvent('voicepost_mic_explainer_shown', {})
  showMicExplainer.value = true
}

async function confirmMicAndRecord() {
  showMicExplainer.value = false
  logVpEvent('voicepost_mic_explainer_continued', {})
  await beginRecording()
}

async function beginRecording() {
  errorMessage.value = ''
  session.value = null
  chunks = []
  elapsed.value = 0

  if (
    typeof navigator === 'undefined' ||
    !navigator.mediaDevices ||
    typeof MediaRecorder === 'undefined'
  ) {
    logVpEvent('voicepost_record_error', { reason: 'unsupported' })
    fail(
      "Your browser can't record audio. Try a recent Chrome, Edge or Safari."
    )
    return
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch (e) {
    let reason = 'other'
    if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) {
      reason = 'permission_denied'
      fail('We need permission to use your microphone. Allow it and try again.')
    } else if (e && e.name === 'NotFoundError') {
      reason = 'no_microphone'
      fail("We couldn't find a microphone on your device.")
    } else {
      fail("We couldn't start recording. Please try again.")
    }
    logVpEvent('voicepost_record_error', { reason })
    return
  }

  mimeType = pickMimeType() || 'audio/webm'
  try {
    mediaRecorder = new MediaRecorder(stream, { mimeType })
  } catch (e) {
    mediaRecorder = new MediaRecorder(stream)
    mimeType = mediaRecorder.mimeType || 'audio/webm'
  }

  mediaRecorder.ondataavailable = (e) => {
    if (e.data && e.data.size > 0) {
      chunks.push(e.data)
      enqueueChunk(e.data)
    }
  }
  mediaRecorder.onstop = finalise

  // Emit a chunk every few seconds so the audio streams up while they talk and
  // there's almost nothing left to upload when they stop.
  mediaRecorder.start(3000)
  phase.value = 'recording'
  logVpEvent('voicepost_record_start', {})

  timer = setInterval(() => {
    elapsed.value += 1
    if (elapsed.value >= MAX_SECONDS) stopRecording()
  }, 1000)
}

// Serialise chunk uploads so the server appends them to the buffer in order.
// (The response only carries the session id - transcription is deferred.)
function enqueueChunk(blob) {
  sendQueue = sendQueue.then(async () => {
    try {
      const res = await $api.voicepost.chunk(blob, session.value)
      if (res?.session) session.value = res.session
    } catch (e) {
      // Non-fatal: a dropped chunk just means slightly less audio; the final
      // pass transcribes whatever made it to the buffer.
    }
  })
  return sendQueue
}

function stopRecording() {
  recordDurationS = elapsed.value
  if (timer) {
    clearInterval(timer)
    timer = null
  }
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop() // fires ondataavailable (final chunk) then onstop
  }
  stopStream()
}

async function finalise() {
  phase.value = 'finishing'
  await sendQueue // ensure every chunk, including the last, is in the buffer

  if (!session.value) {
    logVpEvent('voicepost_finish_error', {
      reason: 'no_session',
      duration_s: recordDurationS,
    })
    fail("We didn't catch that recording - please try again.")
    return
  }

  try {
    const res = await $api.voicepost.finish(session.value)
    title.value = res.title || ''
    description.value = res.description || ''
    rawTranscript.value = res.transcript || ''
    // Remember what we produced so we can tell later if they edited it.
    originalTitle.value = title.value
    originalDescription.value = description.value

    if (chunks.length) {
      audioBlob.value = new Blob(chunks, { type: mimeType })
      audioUrl.value = URL.createObjectURL(audioBlob.value)
    }
    reviewShownAt = Date.now()
    logVpEvent('voicepost_transcribed', {
      duration_s: recordDurationS,
      transcript_chars: (res.transcript || '').length,
      title_chars: title.value.length,
      desc_chars: description.value.length,
      rerecord_count: rerecordCount,
    })
    phase.value = 'review'
  } catch (e) {
    logVpEvent('voicepost_finish_error', {
      reason: 'finish_failed',
      duration_s: recordDurationS,
    })
    fail('Something went wrong writing your post. Please try again.')
  }
}

function togglePlay() {
  const el = audioEl.value
  if (!el) return
  if (playing.value) {
    el.pause()
    playing.value = false
  } else {
    if (!played) {
      played = true
      logVpEvent('voicepost_played_recording', {})
    }
    el.play()
    playing.value = true
  }
}

function postIt() {
  const titleEdited = title.value !== originalTitle.value
  const descEdited = description.value !== originalDescription.value
  posted = true
  // The full picture of a completed voice post: did they trust the words (edit
  // or not), listen back, re-record, and how long they mulled it. No
  // consent_play_voice: nobody is asked any more and playback to others never
  // happens, so there is nothing to vary.
  logVpEvent('voicepost_posted', {
    title_edited: titleEdited,
    desc_edited: descEdited,
    title_chars: title.value.length,
    desc_chars: description.value.length,
    desc_char_delta:
      description.value.length - originalDescription.value.length,
    had_photo: hasPhotos.value,
    played_back: played,
    rerecord_count: rerecordCount,
    seconds_on_review: reviewShownAt
      ? Math.round((Date.now() - reviewShownAt) / 1000)
      : null,
  })

  // NB: the compose-experiment conversion is NOT recorded here. Finishing the voice
  // review is not the same as creating a post — the user still has to complete the
  // final "Freegle it!" step. Conversion is recorded there (give/mobile/whereami) for
  // both arms so voice and control are measured at the same funnel point.
  //
  // Hand the reviewed words to the compose draft (the photo and type=Offer are
  // already on it) and continue into the normal final step - postcode, community
  // and "Freegle it!" - which is what actually creates the post.
  composeStore.setItem({ id: messageId.value, item: title.value })
  composeStore.setDescription({
    id: messageId.value,
    description: description.value,
  })
  router.push('/give/mobile/whereami')
}

function stopStream() {
  if (stream) {
    stream.getTracks().forEach((t) => t.stop())
    stream = null
  }
}

function fail(msg) {
  errorMessage.value = msg
  phase.value = 'error'
  stopStream()
  if (timer) {
    clearInterval(timer)
    timer = null
  }
}

// Re-record the voice but keep the photo they already added.
function reRecord() {
  rerecordCount++
  logVpEvent('voicepost_rerecord', { count: rerecordCount })
  clearVoice()
  phase.value = 'idle'
}

function clearVoice() {
  if (audioUrl.value) {
    URL.revokeObjectURL(audioUrl.value)
    audioUrl.value = null
  }
  audioBlob.value = null
  playing.value = false
  played = false
  showRaw.value = false
  title.value = ''
  description.value = ''
  rawTranscript.value = ''
  originalTitle.value = ''
  originalDescription.value = ''
  reviewShownAt = 0
  session.value = null
  chunks = []
}

onBeforeUnmount(() => {
  // If they reached the review but navigated away without posting, that's a
  // drop-off worth knowing about.
  if (phase.value === 'review' && !posted) {
    logVpEvent('voicepost_review_abandoned', {
      had_photo: hasPhotos.value,
      rerecord_count: rerecordCount,
    })
  }
  stopStream()
  if (timer) clearInterval(timer)
  if (audioUrl.value) URL.revokeObjectURL(audioUrl.value)
})
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';
@import 'assets/css/sticky-banner.scss';

.voicepost {
  min-height: 100vh;
  background: $color-gray--lighter;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  /* Reserve room at the bottom so the fixed sticky-ad banner never covers the
     buttons (the banner is 73-273px depending on screen). */
  padding: 1rem 1rem 40px;

  &.has-sticky-ad {
    padding-bottom: calc(40px + $sticky-banner-height-mobile);

    @media (min-height: $mobile-tall) {
      padding-bottom: calc(40px + $sticky-banner-height-mobile-tall);
    }

    @media (min-height: $desktop-tall) {
      padding-bottom: calc(40px + $sticky-banner-height-desktop-tall);
    }
  }
}

.voicepost__card {
  width: 100%;
  max-width: 560px;
  background: $color-white;
  border-radius: 12px;
  box-shadow: var(--shadow-sm);
  padding: 1.5rem;
}

.voicepost__stage {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.75rem;
  padding: 1rem 0;
}

.voicepost__title {
  font-size: 1.6rem;
  font-weight: 700;
  color: $color-green--darker;
  margin: 0;

  &--sm {
    font-size: 1.35rem;
  }
}

.voicepost__lead {
  font-size: 1rem;
  line-height: 1.55;
  color: $color-gray--darker;
  margin: 0;

  &--tight {
    font-size: 0.95rem;
  }
}

.voicepost__hint {
  font-size: 0.85rem;
  color: $color-gray--normal;
  margin: 0;
}

.voicepost__spinner {
  color: $color-green--darker;
  width: 2.5rem;
  height: 2.5rem;
}

.voicepost__error {
  color: $color-red;
}

.photo-wrap {
  width: 100%;
  margin: 0.5rem 0;
}

/* Voice-vs-keyboard choice */
.choice-grid {
  display: flex;
  gap: 1rem;
  width: 100%;
  margin: 1.25rem 0 0.5rem;
}

.choice-btn {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 1.6rem 0.75rem;
  border-radius: 14px;
  border: 2px solid $color-green--darker;
  background: $color-white;
  color: $color-green--darker;
  cursor: pointer;
  transition: transform 0.12s ease;

  &:active {
    transform: scale(0.97);
  }

  &--voice {
    background: $color-green--darker;
    color: $color-white;
  }

  &__icon {
    width: 46px;
    height: 46px;
  }

  &__label {
    font-size: 1.15rem;
    font-weight: 700;
  }

  &__sub {
    font-size: 0.8rem;
    opacity: 0.85;
  }
}

.idle-photo,
.review-photo {
  width: 100%;
  max-height: 220px;
  object-fit: cover;
  border-radius: 10px;
}

.idle-photo {
  max-height: 160px;
}

/* Big mic button */
.mic-btn {
  margin: 0.5rem 0;
  width: 132px;
  height: 132px;
  border-radius: 50%;
  border: none;
  background: $color-green--darker;
  color: $color-white;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(13, 51, 17, 0.35);
  transition:
    transform 0.12s ease,
    box-shadow 0.12s ease;

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(13, 51, 17, 0.4);
  }

  &:active {
    transform: scale(0.96);
  }

  &__icon {
    width: 60px;
    height: 60px;
  }
}

/* Recording */
.recording-head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.recording-dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: $color-red;
  animation: pulse 1.2s infinite;
}

.recording-timer {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: $color-gray--darker;
}

@keyframes pulse {
  0% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.4;
    transform: scale(0.8);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}

/* Listening equalizer - purely decorative, shows we're recording */
.equalizer {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 6px;
  height: 72px;
  margin: 0.5rem 0 1rem;

  span {
    display: block;
    width: 8px;
    background: $color-green--darker;
    border-radius: 4px;
    animation: eq 1s ease-in-out infinite;
  }

  span:nth-child(1) {
    animation-delay: -0.9s;
  }
  span:nth-child(2) {
    animation-delay: -0.7s;
  }
  span:nth-child(3) {
    animation-delay: -0.5s;
  }
  span:nth-child(4) {
    animation-delay: -0.3s;
  }
  span:nth-child(5) {
    animation-delay: -0.5s;
  }
  span:nth-child(6) {
    animation-delay: -0.7s;
  }
  span:nth-child(7) {
    animation-delay: -0.9s;
  }
}

@keyframes eq {
  0%,
  100% {
    height: 16px;
  }
  50% {
    height: 64px;
  }
}

.stop-btn {
  margin-top: 0.5rem;
  display: inline-flex;
  align-items: center;
  gap: 0.6rem;
  background: $color-red;
  color: $color-white;
  border: none;
  border-radius: 40px;
  padding: 0.8rem 1.6rem;
  font-size: 1.05rem;
  font-weight: 600;
  cursor: pointer;

  &__square {
    width: 14px;
    height: 14px;
    background: $color-white;
    border-radius: 2px;
  }
}

/* Review */
.voicepost__review {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.field-label {
  font-weight: 600;
  color: $color-gray--darker;
  margin: 0.75rem 0 0.15rem;
}

.field-input {
  font-size: 1rem;
}

.playback {
  margin: 0.75rem 0 0.25rem;
}

.playback__btn,
.raw-toggle,
.restart-link {
  background: none;
  border: none;
  color: $color-green--darker;
  font-weight: 600;
  cursor: pointer;
  padding: 0.35rem 0;
}

.playback__btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.raw-toggle {
  align-self: flex-start;
  font-size: 0.9rem;
}

.raw-transcript {
  font-style: italic;
  color: $color-gray--normal;
  background: $color-gray--lighter;
  border-radius: 8px;
  padding: 0.75rem;
  margin: 0.25rem 0;
}

.recording-note {
  margin: 0.75rem 0 0;
  font-size: 0.85rem;
  color: $color-gray--normal;
}

.review-actions {
  margin-top: 0.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
}

.done-tick {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: $color-green--darker;
  color: $color-white;
  font-size: 2.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
