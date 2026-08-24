<template>
  <div class="app-give-photos" :class="{ 'has-sticky-ad': stickyAdRendered }">
    <!-- Main content -->
    <div class="app-content">
      <PhotoUploader
        ref="photoUploader"
        v-model="attachments"
        type="Message"
        :recognise="attachments.length === 0"
        @photo-processed="onPhotoProcessed"
        @skip="goNext"
      />
    </div>

    <!-- Footer with Next button -->
    <div
      v-if="hasPhotos"
      class="app-footer"
      :class="{ 'has-sticky-ad': stickyAdRendered }"
    >
      <b-button
        variant="primary"
        size="lg"
        class="w-100"
        :disabled="anyUploading"
        @click="goNext"
      >
        Next <v-icon icon="arrow-right" />
      </b-button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from '#imports'
import { useComposeStore } from '~/stores/compose'
import { useAuthStore } from '~/stores/auth'
import { useMiscStore } from '~/stores/misc'
import { useMobileStore } from '~/stores/mobile'

const router = useRouter()
const composeStore = useComposeStore()
const authStore = useAuthStore()
const miscStore = useMiscStore()
const mobileStore = useMobileStore()

// Ref to the PhotoUploader so a shared image (Share -> Freegle) can be fed in.
const photoUploader = ref(null)

// Check if sticky ad is rendered
const stickyAdRendered = computed(() => miscStore.stickyAdRendered)

// Initialize message ID synchronously so it's available for computed properties
function getOrCreateMessageId() {
  const myid = authStore.user?.id

  // Find a real (non-synthetic) Offer message in the store.
  // composeStore.all returns synthetic defaults for missing types; synthetic
  // messages have ids that don't exist in composeStore.messages, so using them
  // creates a sparse array ([empty, {id:1}]) that crashes on submit after
  // Pinia's JSON round-trip compacts it.
  const existingMessages = composeStore.all.filter(
    (m) =>
      m.type === 'Offer' &&
      (!m.savedBy || m.savedBy === myid) &&
      composeStore.messages[m.id]
  )

  const id =
    existingMessages.length > 0 ? existingMessages[0].id : composeStore.add()

  // Ensure the message exists in state.messages (like PostMessage.vue does)
  composeStore.setType({
    id,
    type: 'Offer',
  })

  return id
}

// Get or create message ID synchronously
const messageId = ref(getOrCreateMessageId())

// Get attachments for current message
const attachments = computed({
  get() {
    return composeStore.attachments(messageId.value) || []
  },
  set(newValue) {
    composeStore.setAttachmentsForMessage(messageId.value, newValue)
  },
})

// Track if we have photos (for showing Next button)
const hasPhotos = computed(() => attachments.value.length > 0)

// Any attachment still mid-upload (pushed into the list before the TUS
// upload / imageStore.post() round-trip completes - see PhotoUploader.vue's
// processPhoto()/uploadPhoto()). Gates Next so the user can't move on to
// /give/mobile/details with a photo that has no real id yet. A shared-in
// photo sits in this state for longer than a manually-picked one (quality
// check + upload both run before the user has done anything else on this
// page), which is why the share path hits this far more often - but the gap
// exists for the manual add-photo flow too.
const anyUploading = computed(() => attachments.value.some((a) => a.uploading))

function onPhotoProcessed() {
  // Photo was added - hasPhotos will update automatically via computed
}

function goNext() {
  router.push('/give/mobile/details')
}

// If the user reached this page by sharing an image into Freegle from another
// app, attach the shared photo(s) to this OFFER. The native layer populates
// mobileStore.pendingSharedImages and routes us here (see stores/mobile.js).
onMounted(async () => {
  const shared = mobileStore.pendingSharedImages
  if (!shared || shared.length === 0) return
  // Clear first so a later resume/navigation doesn't re-add the same photos.
  mobileStore.pendingSharedImages = []
  for (const webPath of shared) {
    try {
      await photoUploader.value?.processPhoto(webPath)
    } catch (e) {
      console.log('Failed to attach shared image', e?.message)
    }
  }
})
</script>

<style scoped lang="scss">
@import 'assets/css/sticky-banner.scss';
@import 'assets/css/_color-vars.scss';

.app-give-photos {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: $color-white;
  padding-bottom: 80px;

  &.has-sticky-ad {
    padding-bottom: calc(80px + $sticky-banner-height-mobile);

    @media (min-height: $mobile-tall) {
      padding-bottom: calc(80px + $sticky-banner-height-mobile-tall);
    }

    @media (min-height: $desktop-tall) {
      padding-bottom: calc(80px + $sticky-banner-height-desktop-tall);
    }
  }
}

.app-content {
  flex: 1;
  overflow-y: auto;
}

.app-footer {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 1rem;
  border-top: 1px solid $color-gray-3;
  background: $color-white;
  z-index: 100;

  &.has-sticky-ad {
    bottom: $sticky-banner-height-mobile;

    @media (min-height: $mobile-tall) {
      bottom: $sticky-banner-height-mobile-tall;
    }

    @media (min-height: $desktop-tall) {
      bottom: $sticky-banner-height-desktop-tall;
    }
  }
}
</style>
