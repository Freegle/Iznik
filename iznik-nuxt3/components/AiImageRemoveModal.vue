<template>
  <!-- confirm-modal puts us above everything else on the page (assets/css/global.scss),
  which we need because the member site opens us from the photo viewer at z-index 10000. -->
  <b-modal
    ref="modal"
    title="Remove AI Image"
    no-stacking
    modal-class="confirm-modal"
  >
    <template #default>
      <p>This is an AI-generated image. Why are you removing it?</p>
      <b-button
        variant="outline-secondary"
        class="d-block w-100 mb-2"
        @click="choose(false)"
      >
        Not relevant to this post
      </b-button>
      <b-button
        variant="outline-danger"
        class="d-block w-100"
        @click="choose(true)"
      >
        Bad AI image for any post of this item
      </b-button>
    </template>
    <template #footer>
      <b-button variant="white" @click="cancel">Cancel</b-button>
    </template>
  </b-modal>
</template>

<script setup>
// The "why are you removing it?" question asked before an AI-generated image comes off a
// post. Shared by ModTools (ModPhoto, ModPhotoModal) and the member site's photo viewer,
// so the wording and the two answers stay identical. "Bad for any post of this item"
// stops AI images for the item; "not relevant" only takes this one off this post.
import { useOurModal } from '~/composables/useOurModal'

const emit = defineEmits(['choose', 'cancel'])

// Always mounted, opened on demand by the owner calling show().
const { modal, show, hide } = useOurModal({ autoShow: false })

function choose(badForAnyPost) {
  hide()
  emit('choose', badForAnyPost)
}

function cancel() {
  hide()
  emit('cancel')
}

defineExpose({ show, hide })
</script>
