<template>
  <div>
    <b-modal
      id="ripplingExplanationModal"
      ref="modal"
      title="What rippling out means"
      size="lg"
      no-stacking
    >
      <template #default>
        <RipplingExplanationGeneral />
      </template>
      <template #footer>
        <b-button variant="white" @click="hide"> Close </b-button>
      </template>
    </b-modal>
  </div>
</template>

<script setup>
import { useOurModal } from '~/composables/useOurModal'
import RipplingExplanationGeneral from '~/components/RipplingExplanationGeneral.vue'

const { modal, show, hide } = useOurModal({ autoShow: false })

defineExpose({ show, hide })
</script>
