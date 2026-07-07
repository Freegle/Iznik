<template>
  <div v-if="shortlink.type === 'Group'">
    <b-row class="m-0">
      <b-col cols="4">
        <ExternalLink :href="shortlink.url" class="forcebreak">
          {{ shortlink.nameshort }}
        </ExternalLink>
      </b-col>
      <b-col cols="6">
        <ExternalLink
          :href="'https://freegle.in/' + shortlink.name"
          class="forcebreak"
        >
          {{ 'https://freegle.in/' + shortlink.name }}
        </ExternalLink>
      </b-col>
      <b-col cols="2">
        <b-button
          v-if="!nostats"
          variant="secondary"
          class="mb-1 w-100"
          :to="'/shortlinks/' + shortlink.id"
        >
          View Stats
        </b-button>
        <b-button
          variant="primary"
          class="mb-1 w-100"
          :disabled="downloadingQr"
          @click="downloadQr"
        >
          Download QR
        </b-button>
      </b-col>
    </b-row>
  </div>
</template>
<script setup>
import { computed, ref } from 'vue'
import ExternalLink from './ExternalLink'
import { useShortlinkStore } from '~/stores/shortlinks'
import { downloadFreegleQr } from '~/composables/useFreegleQr'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  nostats: {
    type: Boolean,
    default: false,
  },
})

const shortlinkStore = useShortlinkStore()

const shortlink = computed(() => {
  return shortlinkStore.byId(props.id)
})

const downloadingQr = ref(false)

async function downloadQr() {
  downloadingQr.value = true
  try {
    await downloadFreegleQr(
      'https://freegle.in/' + shortlink.value.name,
      'freegle-' + shortlink.value.name
    )
  } finally {
    downloadingQr.value = false
  }
}
</script>
