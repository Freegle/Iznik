<template>
  <b-modal
    ref="modal"
    scrollable
    :title="thankyou ? 'Thank you!' : 'Your Story'"
    size="lg"
    no-stacking
    @shown="onShow"
  >
    <template #default>
      <div v-if="thankyou">
        <p>We're grateful that you've taken the time. Happy freegling!</p>
      </div>
      <div v-else>
        <b-row>
          <b-col>
            <p>First, please could we have a quick summary?</p>
            <b-form-input
              v-model="story.headline"
              spellcheck="true"
              maxlength="140"
              placeholder="Give us a summary!"
              class="mb-1"
              :class="{
                'is-invalid': noHeadline,
              }"
            />
            <h3>Your story</h3>
            <p>
              Ok, now tell us why you freegle - and what difference it's made.
            </p>
            <b-form-textarea
              v-model="story.story"
              spellcheck="true"
              rows="5"
              class="mb-1"
              :class="{
                'is-invalid': noStory,
              }"
              placeholder="What have you given or received? What did it save - money, a trip to the tip, something from landfill? Who have you met? How does it feel?"
            />
          </b-col>
        </b-row>
        <b-row>
          <b-col>
            <div class="photo-ask" :class="{ 'photo-ask--nudge': photoNudge }">
              <h3>Please add a photo</h3>
              <p class="mb-1">
                A photo is what makes a story real. Stories with a photo are the
                ones people stop and read, and the ones we can share to get more
                people freegling.
              </p>
              <p class="mb-2 text-muted small">
                The item, where it ended up, the person who took it, or just a
                selfie - any of these is perfect.
              </p>
              <b-button
                variant="primary"
                size="lg"
                class="mt-1 mb-1"
                @click="photoAdd"
              >
                <v-icon icon="camera" /> Add a photo
              </b-button>
            </div>
          </b-col>
        </b-row>
        <b-row v-if="uploading">
          <b-col>
            <OurUploader v-model="currentAtts" class="bg-white" type="Story" />
          </b-col>
        </b-row>
        <b-row>
          <b-col cols="12">
            <div class="mt-2">
              <div v-if="story.image" class="container p-0">
                <div
                  class="clickme rotateleft stacked"
                  label="Rotate left"
                  title="Rotate left"
                  @click="rotateLeft"
                >
                  <v-icon icon="circle" size="2x" />
                  <v-icon icon="reply" class="ms-2" />
                </div>
                <div
                  label="Rotate right"
                  class="rotateright clickme stacked"
                  title="Rotate right"
                  @click="rotateRight"
                >
                  <v-icon icon="circle" size="2x" />
                  <v-icon icon="reply" flip="horizontal" />
                </div>
                <div class="image">
                  <OurUploadedImage
                    v-if="story.image?.imageuid"
                    :src="story.image.imageuid"
                    :modifiers="story.image.imagemods"
                    alt="Store Photo"
                    width="250"
                  />
                  <b-img v-else thumbnail width="250" src="/placeholder.jpg" />
                </div>
              </div>
            </div>
          </b-col>
        </b-row>
        <b-row v-if="photoNudge">
          <b-col>
            <NoticeMessage ref="nudgeEl" variant="warning" class="photo-nudge">
              <p class="fw-bold mb-1">No photo yet?</p>
              <p class="mb-2">
                A story with a photo is far easier to share, and it only takes a
                moment. You can still post without one.
              </p>
              <b-button variant="primary" class="me-2 mb-1" @click="photoAdd">
                <v-icon icon="camera" /> Add a photo
              </b-button>
              <b-button
                variant="white"
                class="mb-1"
                :disabled="posting"
                @click="post"
              >
                Post without a photo
              </b-button>
            </NoticeMessage>
          </b-col>
        </b-row>
        <b-row>
          <b-col>
            <NoticeMessage variant="info">
              We may share your story with your Freegle name{{ myNameSuffix }} -
              on here, on social media and so on. This helps get more people
              freegling. We won't use your email address.
            </NoticeMessage>
          </b-col>
        </b-row>
      </div>
    </template>
    <template #footer>
      <div v-if="thankyou">
        <b-button variant="white" :disabled="uploadingPhoto" @click="hide">
          Close
        </b-button>
      </div>
      <div v-else>
        <b-button
          variant="white"
          :disabled="uploadingPhoto"
          class="me-2"
          @click="cancel"
        >
          Cancel
        </b-button>
        <b-button
          variant="primary"
          :disabled="uploadingPhoto || posting"
          @click="submit"
        >
          Add Your Story
        </b-button>
      </div>
    </template>
  </b-modal>
</template>
<script setup>
import { ref, computed, watch, nextTick, defineAsyncComponent } from 'vue'
import NoticeMessage from './NoticeMessage'
import { useStoryStore } from '~/stores/stories'
import { useComposeStore } from '~/stores/compose'
import { useOurModal } from '~/composables/useOurModal'
import { useImageStore } from '~/stores/image'
import { useAuthStore } from '~/stores/auth'

const OurUploader = defineAsyncComponent(
  () => import('~/components/OurUploader')
)

const emit = defineEmits(['login-required'])

// Store instances
const storyStore = useStoryStore()
const composeStore = useComposeStore()
const imageStore = useImageStore()
const authStore = useAuthStore()

// Modal control
const { modal, hide } = useOurModal()

// Reactive state
const uploading = ref(false)
const story = ref({
  headline: null,
  story: null,
  photo: null,
  image: null,
})
const thankyou = ref(false)
const currentAtts = ref([])
const noHeadline = ref(false)
const noStory = ref(false)
// Shown once when they try to post without a photo. Only about one story in six
// arrives with one, and the photo is what makes a story shareable, so the first
// attempt asks; a second press of either button posts anyway.
const photoNudge = ref(false)
// Set once the nudge has actually been rendered, so a second press of the main
// button only posts when they have had the chance to see it. A double-click lands
// its second press before the first has painted anything.
const nudgeShown = ref(false)
const nudgeEl = ref(null)
// True while storyStore.add is in flight, so a double-tap on either posting
// button cannot create the story twice.
const posting = ref(false)

// Computed properties
const uploadingPhoto = computed(() => {
  return composeStore?.uploading
})

const loggedIn = computed(() => Boolean(authStore.user))

// Shown in the sharing notice so they know which name goes out with the story.
const myNameSuffix = computed(() => {
  const name = authStore.user?.displayname
  return name ? ` (${name})` : ''
})

// Watchers
watch(
  currentAtts,
  (newVal) => {
    if (newVal?.length) {
      uploading.value = false
      photoNudge.value = false
      nudgeShown.value = false

      story.value.image = {
        id: newVal[0].id,
        imageuid: newVal[0].ouruid,
        imagemods: newVal[0].externalmods,
      }
    }
  },
  { deep: true }
)

// Methods
function photoAdd() {
  // Flag that we're uploading. This will trigger the render of the filepond instance and subsequently the
  // processed callback below.
  uploading.value = true
  photoNudge.value = false
  nudgeShown.value = false
}

async function rotate(deg) {
  const curr = story.value?.image?.imagemods?.rotate || 0
  story.value.image.imagemods.rotate = curr + deg

  // Ensure between 0 and 360
  story.value.image.imagemods.rotate =
    (story.value.image.imagemods.rotate + 360) % 360

  await imageStore.post({
    id: story.value.image.id,
    rotate: story.value.image.imagemods.rotate,
    bust: Date.now(),
    story: true,
  })
}

function rotateLeft() {
  rotate(-90)
}

function rotateRight() {
  rotate(90)
}

function onShow() {
  thankyou.value = false
  photoNudge.value = false
  nudgeShown.value = false
  posting.value = false

  // If we had to interrupt them to log in, pick up where they left off rather
  // than making them type their story out again.
  const draft = composeStore.storyDraft

  story.value.headline = draft?.headline ?? null
  story.value.story = draft?.story ?? null
  story.value.image = draft?.image ?? null
}

async function post() {
  if (posting.value) {
    return
  }
  posting.value = true

  try {
    await storyStore.add(
      story.value.headline,
      story.value.story,
      story.value.image ? story.value.image.id : null,
      true
    )
  } finally {
    posting.value = false
  }

  composeStore.clearStoryDraft()
  photoNudge.value = false
  nudgeShown.value = false
  thankyou.value = true
}

function cancel() {
  // They've decided against it, so don't hang on to what they typed.
  composeStore.clearStoryDraft()
  hide()
}

async function submit() {
  noHeadline.value = false
  noStory.value = false

  if (story.value.headline && story.value.story) {
    if (!loggedIn.value) {
      // Adding a story needs a login. Ask for one, keeping what they've typed -
      // logging in rebuilds the app and takes this modal with it, so the draft
      // has to live outside it.
      composeStore.saveStoryDraft({
        headline: story.value.headline,
        story: story.value.story,
        image: story.value.image,
      })

      emit('login-required')
      authStore.forceLogin = true
      return
    }

    if (!story.value.image && !nudgeShown.value) {
      // First attempt without a photo: ask once. Pressing again once the nudge
      // is on screen, or "Post without a photo", goes ahead. The nudge renders
      // below the photo box, which on a phone is under the fold of the modal,
      // so bring it into view.
      if (photoNudge.value) {
        // Rendering it; a press in the same tick is a double-click, not an answer.
        return
      }
      photoNudge.value = true
      await nextTick()
      nudgeShown.value = true
      const el = nudgeEl.value?.$el || nudgeEl.value
      el?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
      return
    }

    await post()
  } else {
    if (!story.value.headline) {
      noHeadline.value = true
    }

    if (!story.value.story) {
      noStory.value = true
    }

    // The question about a photo only makes sense next to a story that can be
    // posted; ask again once they have fixed the text.
    photoNudge.value = false
    nudgeShown.value = false
  }
}
</script>
<style scoped lang="scss">
.photo-ask {
  border: 2px dashed $color-green--darker;
  border-radius: 0.5rem;
  padding: 1rem;
  background-color: $color-green--light;

  h3 {
    margin-top: 0;
  }
}

/* The nudge has just pointed at this box, so make it the obvious next thing. */
.photo-ask--nudge {
  border-style: solid;
}

.field {
  font-weight: bold;
  color: $color-green--darker;
}

label {
  font-weight: bold;
  color: $color-green--darker;
  margin-top: 10px;
}

.topleft {
  top: 12px;
  left: 10px;
  position: absolute;
}

.topright {
  top: 12px;
  right: 10px;
  position: absolute;
}

.container {
  display: grid;
  grid-template-columns: 32px 250px 32px;
  justify-content: start;
}

.rotateleft {
  grid-row: 1 / 2;
  grid-column: 1 / 2;
  z-index: 10000;
}

.rotateright {
  grid-row: 1 / 2;
  grid-column: 3 / 4;
  z-index: 10000;
}

.image {
  grid-row: 1 / 2;
  grid-column: 2 / 3;
}

.image__icon {
  color: $color-white;

  &.fa-flip-horizontal {
    transform: translate(-1.5em, -0.5em) scaleX(-1);
  }
}

.stacked {
  display: grid;
  grid-template-columns: 1fr;
  grid-template-rows: 1fr;

  svg {
    grid-row: 1 / 2;
    grid-column: 1 / 2;
  }

  svg:nth-child(2) {
    z-index: 10000;
    color: white;
    padding-top: 7px;
    padding-right: 7px;
  }
}
</style>
