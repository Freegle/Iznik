<template>
  <div class="voicepost">
    <div class="voicepost__card">
      <!-- IDLE: the big mic button -->
      <div v-if="phase === 'idle'" class="voicepost__stage">
        <h1 class="voicepost__title">Tell us about your item</h1>
        <p class="voicepost__lead">
          Tap the microphone and just describe what you're giving away, in your
          own words. We'll turn it into a post for you - you can tidy it up
          before anything goes live.
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
        <p class="voicepost__hint">Press to start &middot; press again to stop</p>
      </div>

      <!-- RECORDING: live transcript builds up as they talk -->
      <div v-else-if="phase === 'recording'" class="voicepost__stage">
        <div class="recording-head">
          <span class="recording-dot" />
          <span class="recording-timer">{{ formattedElapsed }}</span>
        </div>
        <p class="voicepost__lead voicepost__lead--tight">
          Listening&hellip; describe your item and we'll write it down.
        </p>

        <div class="live-transcript" aria-live="polite">
          <p v-if="liveTranscript" class="live-transcript__text">
            {{ liveTranscript }}
          </p>
          <p v-else class="live-transcript__placeholder">
            Your words will appear here&hellip;
          </p>
        </div>

        <button class="stop-btn" @click="stopRecording">
          <span class="stop-btn__square" aria-hidden="true" />
          Stop &amp; write my post
        </button>
      </div>

      <!-- FINISHING: quick tidy-up pass -->
      <div v-else-if="phase === 'finishing'" class="voicepost__stage">
        <b-spinner class="voicepost__spinner" />
        <p class="voicepost__lead">Tidying up your words&hellip;</p>
      </div>

      <!-- REVIEW: editable result + consent -->
      <div v-else-if="phase === 'review'" class="voicepost__review">
        <h1 class="voicepost__title voicepost__title--sm">
          Here's your post - have a read
        </h1>
        <p class="voicepost__lead voicepost__lead--tight">
          We wrote this from what you said. Change anything you like.
        </p>

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
          <!-- eslint-disable-next-line vue/no-lone-template -->
          <audio ref="audioEl" :src="audioUrl" @ended="playing = false" />
        </div>

        <button
          class="raw-toggle"
          type="button"
          @click="showRaw = !showRaw"
        >
          {{ showRaw ? 'Hide' : 'Show' }} exactly what you said
        </button>
        <p v-if="showRaw" class="raw-transcript">"{{ rawTranscript }}"</p>

        <div class="consent">
          <b-form-checkbox v-model="letThemHear">
            Let the person collecting hear my voice description
          </b-form-checkbox>
          <p class="consent__help">
            Optional. Your recording is kept for up to 90 days and then deleted -
            see our
            <nuxt-link no-prefetch to="/privacy">privacy policy</nuxt-link>. We'll
            only ever play it to someone if you tick this.
          </p>
        </div>

        <div class="review-actions">
          <b-button variant="primary" size="lg" class="w-100" @click="postIt">
            Looks good - post it
          </b-button>
          <button class="restart-link" type="button" @click="reset">
            Start again
          </button>
        </div>
      </div>

      <!-- DONE: demo confirmation -->
      <div v-else-if="phase === 'done'" class="voicepost__stage">
        <div class="done-tick" aria-hidden="true">✓</div>
        <h1 class="voicepost__title voicepost__title--sm">That's the words done!</h1>
        <p class="voicepost__lead">
          <strong>{{ title }}</strong> is ready. In the full flow you'd add a
          photo and choose your community next - this demo stops here.
        </p>
        <b-button variant="primary" size="lg" class="w-100" @click="reset">
          Do another
        </b-button>
      </div>

      <!-- ERROR -->
      <div v-else-if="phase === 'error'" class="voicepost__stage">
        <p class="voicepost__lead voicepost__error">{{ errorMessage }}</p>
        <b-button variant="primary" size="lg" class="w-100" @click="reset">
          Try again
        </b-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { useNuxtApp } from '#imports'

// Standalone demo route: record your item description, stream it to the server
// for live transcription (Groq), then review the tidied title/description.

const { $api } = useNuxtApp()

const phase = ref('idle') // idle | recording | finishing | review | done | error
const errorMessage = ref('')

// Recording state
const session = ref(null)
const liveTranscript = ref('')
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
const letThemHear = ref(false)
const audioBlob = ref(null)
const audioUrl = ref(null)
const audioEl = ref(null)
const playing = ref(false)

const formattedElapsed = computed(() => {
  const m = Math.floor(elapsed.value / 60)
  const s = elapsed.value % 60
  return `${m}:${s.toString().padStart(2, '0')}`
})

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

async function startRecording() {
  errorMessage.value = ''
  liveTranscript.value = ''
  session.value = null
  chunks = []
  elapsed.value = 0

  if (
    typeof navigator === 'undefined' ||
    !navigator.mediaDevices ||
    typeof MediaRecorder === 'undefined'
  ) {
    fail("Your browser can't record audio. Try a recent Chrome, Edge or Safari.")
    return
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch (e) {
    if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) {
      fail(
        'We need permission to use your microphone. Allow it and try again.'
      )
    } else if (e && e.name === 'NotFoundError') {
      fail("We couldn't find a microphone on your device.")
    } else {
      fail("We couldn't start recording. Please try again.")
    }
    return
  }

  mimeType = pickMimeType() || 'audio/webm'
  try {
    mediaRecorder = new MediaRecorder(stream, { mimeType })
  } catch (e) {
    // Some browsers reject an explicit mimeType — fall back to the default.
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

  // Emit a chunk every few seconds so transcription keeps pace with speech.
  mediaRecorder.start(4000)
  phase.value = 'recording'

  timer = setInterval(() => {
    elapsed.value += 1
    if (elapsed.value >= MAX_SECONDS) stopRecording()
  }, 1000)
}

// Serialise chunk uploads so the server appends them in order.
function enqueueChunk(blob) {
  sendQueue = sendQueue.then(async () => {
    try {
      const res = await $api.voicepost.chunk(blob, session.value)
      if (res?.session) session.value = res.session
      if (res?.transcript) liveTranscript.value = res.transcript
    } catch (e) {
      // Non-fatal: a partial pass may fail; the final pass will catch up.
    }
  })
  return sendQueue
}

function stopRecording() {
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
  await sendQueue // ensure every chunk, including the last, has been sent

  if (!session.value) {
    fail("We didn't catch that recording - please try again.")
    return
  }

  try {
    const res = await $api.voicepost.finish(session.value)
    title.value = res.title || ''
    description.value = res.description || ''
    rawTranscript.value = res.transcript || ''

    if (chunks.length) {
      audioBlob.value = new Blob(chunks, { type: mimeType })
      audioUrl.value = URL.createObjectURL(audioBlob.value)
    }
    phase.value = 'review'
  } catch (e) {
    fail("Something went wrong writing your post. Please try again.")
  }
}

function togglePlay() {
  const el = audioEl.value
  if (!el) return
  if (playing.value) {
    el.pause()
    playing.value = false
  } else {
    el.play()
    playing.value = true
  }
}

function postIt() {
  // Demo stops here — the real flow would hand title/description/consent to the
  // normal compose step (photo + community). letThemHear.value carries consent.
  phase.value = 'done'
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

function reset() {
  if (audioUrl.value) {
    URL.revokeObjectURL(audioUrl.value)
    audioUrl.value = null
  }
  audioBlob.value = null
  playing.value = false
  showRaw.value = false
  letThemHear.value = false
  title.value = ''
  description.value = ''
  rawTranscript.value = ''
  liveTranscript.value = ''
  session.value = null
  chunks = []
  phase.value = 'idle'
}

onBeforeUnmount(() => {
  stopStream()
  if (timer) clearInterval(timer)
  if (audioUrl.value) URL.revokeObjectURL(audioUrl.value)
})
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.voicepost {
  min-height: 100vh;
  background: $color-gray--lighter;
  display: flex;
  justify-content: center;
  // Extra bottom room so a fixed sticky-ad banner never covers the review
  // buttons or consent toggle on short screens.
  padding: 1rem 1rem 96px;
}

.voicepost__card {
  width: 100%;
  max-width: 560px;
  background: $color-white;
  border-radius: 12px;
  box-shadow: var(--shadow-sm);
  padding: 1.5rem;
  margin: auto 0;
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
  transition: transform 0.12s ease, box-shadow 0.12s ease;

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

.live-transcript {
  width: 100%;
  min-height: 120px;
  background: $color-gray--lighter;
  border-radius: 10px;
  padding: 1rem;
  text-align: left;

  &__text {
    margin: 0;
    font-size: 1.05rem;
    line-height: 1.6;
    color: $color-black;
  }

  &__placeholder {
    margin: 0;
    color: $color-gray--normal;
    font-style: italic;
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
  padding: 0.8rem 1.5rem;
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

.consent {
  margin: 0.75rem 0;
  background: $color-gray--lighter;
  border-radius: 10px;
  padding: 0.9rem;

  &__help {
    font-size: 0.85rem;
    color: $color-gray--normal;
    margin: 0.4rem 0 0;
  }
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
