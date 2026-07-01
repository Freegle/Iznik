<template>
  <div>
    <b-modal
      id="whichPostsModal"
      ref="modal"
      title="Which posts do I see?"
      size="lg"
      no-stacking
    >
      <template #default>
        <WhichPostsExplanation />
      </template>
      <template #footer>
        <b-button variant="white" @click="hide"> Close </b-button>
      </template>
    </b-modal>
  </div>
</template>

<script setup>
import { useOurModal } from '~/composables/useOurModal'
import WhichPostsExplanation from '~/components/WhichPostsExplanation.vue'

const { modal, show, hide } = useOurModal({ autoShow: false })

defineExpose({ show, hide })
</script>
