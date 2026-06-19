<template>
  <div>
    <b-modal
      id="ripplingExplanationModal"
      ref="modal"
      title="How does this work?"
      size="lg"
      no-stacking
    >
      <template #default>
        <RipplingExplanation />
      </template>
      <template #footer>
        <b-button variant="white" @click="hide"> Close </b-button>
      </template>
    </b-modal>
  </div>
</template>

<script setup>
import { useOurModal } from '~/composables/useOurModal'

// RipplingExplanation is auto-imported from components/ (Nuxt), matching RipplingHelpModal.

const { modal, show, hide } = useOurModal({ autoShow: false })

defineExpose({ show, hide })
</script>
