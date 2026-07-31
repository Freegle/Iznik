<template>
  <b-row v-if="alert">
    <b-col cols="6" lg="2">
      {{ datetimeshort(alert.created) }}
    </b-col>
    <b-col cols="6" lg="2">
      <span v-if="completed">{{ completed }}</span>
      <span v-else class="text-muted fst-italic">In progress</span>
    </b-col>
    <b-col cols="6" lg="2">
      <div v-if="alert.group">
        {{ alert.group.nameshort }}
      </div>
    </b-col>
    <b-col cols="6" lg="4">
      {{ alert.subject }}
    </b-col>
    <b-col cols="6" lg="2">
      <b-button variant="white" class="me-1" @click="stats">
        Show Stats
      </b-button>
      <b-button variant="white" @click="details"> Show Details </b-button>
    </b-col>
    <ModAlertHistoryDetailsModal
      v-if="showDetails"
      :id="alertid"
      ref="detailsModal"
      @hidden="showDetails = false"
    />
    <ModAlertHistoryStatsModal
      v-if="showStats"
      :id="alertid"
      ref="statsModal"
      @hidden="showStats = false"
    />
  </b-row>
</template>
<script setup>
import { ref, computed } from 'vue'
import { datetimeshort } from '~/composables/useTimeFormat'
import { useAlertStore } from '~/stores/alert'

const alertStore = useAlertStore()

const props = defineProps({
  alertid: {
    type: Number,
    required: true,
  },
})

const alert = computed(() => alertStore.get(props.alertid))

// An alert stays incomplete until the batch job has fanned it out to every group, and the API
// serialises that NULL `complete` timestamp as an empty string. Feeding that to datetimeshort()
// renders the literal "Invalid Date" in the history table, which reads like a bug rather than
// "this mailing is still going out" - so only format a value we actually have.
const completed = computed(() => {
  const val = alert.value?.complete

  if (!val) {
    return null
  }

  const formatted = datetimeshort(val)

  return formatted === 'Invalid Date' ? null : formatted
})

const showDetails = ref(false)
const showStats = ref(false)
const detailsModal = ref(null)
const statsModal = ref(null)

function details() {
  showDetails.value = true
  detailsModal.value?.show()
}

function stats() {
  showStats.value = true
  statsModal.value?.show()
}
</script>
