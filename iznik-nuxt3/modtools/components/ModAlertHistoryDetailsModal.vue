<template>
  <div>
    <b-modal :id="'alertModal-' + alert.id" ref="modal" size="lg" no-stacking>
      <template #title>
        {{ alert.subject }}
      </template>
      <template #default>
        <label :for="`${formId}-text-version`">Text version</label>
        <b-form-textarea
          :id="`${formId}-text-version`"
          v-model="alert.text"
          rows="10"
          readonly
        />
        <div v-if="alert.html" class="bg-light mt-2">
          <div class="field-caption">HTML version (optional)</div>
          <!-- eslint-disable-next-line -->
          <div v-html="alert.html" class="bg-info" />
        </div>
      </template>
      <template #footer>
        <b-button variant="white" @click="hide"> Close </b-button>
      </template>
    </b-modal>
  </div>
</template>
<script setup>
import { computed, useId } from 'vue'
import { useAlertStore } from '~/stores/alert'
import { useOurModal } from '~/composables/useOurModal'

// Unique per instance so label/for pairs never clash when the component renders twice.
const formId = useId()

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const alertStore = useAlertStore()
const { modal, hide, show } = useOurModal()

const alert = computed(() => alertStore.get(props.id))

defineExpose({ show, hide })
</script>
<style scoped>
label,
.field-caption {
  font-weight: bold;
}
</style>
