<template>
  <b-modal
    ref="modal"
    title="PAF address data"
    size="lg"
    scrollable
    no-stacking
    @hidden="emit('hidden')"
  >
    <template #default>
      <ModSysAdminPaf />
    </template>
    <template #footer>
      <b-button variant="secondary" @click="hide">Close</b-button>
    </template>
  </b-modal>
</template>

<script setup>
// The PAF update, opened from its row on the SysAdmin Housekeeping tab. It mounts only while
// open (v-if in the parent), so the list and the latest release are read fresh each time.
import ModSysAdminPaf from '~/modtools/components/ModSysAdminPaf.vue'
import { useOurModal } from '~/composables/useOurModal'

const emit = defineEmits(['hidden'])
const { modal, hide } = useOurModal()
</script>
