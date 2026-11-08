<template>
  <NoticeMessage v-if="warning" variant="warning" class="mt-1">
    <h1 class="header--size3">
      <v-icon icon="info-circle" scale="1-75x" />
      {{ warning.type }}
    </h1>
    <p>
      {{ message }}
    </p>
  </NoticeMessage>
</template>
<script setup>
import { computed } from 'vue'
import NoticeMessage from './NoticeMessage'
import {
  findItemWarning,
  itemWarningMessage,
} from '~/composables/useItemWarnings'

// The note for what is being posted, shown under the item box on every posting form.
const props = defineProps({
  item: {
    type: String,
    required: false,
    default: null,
  },
  type: {
    type: String,
    required: true,
  },
})

const warning = computed(() => findItemWarning(props.item))
const message = computed(() => itemWarningMessage(warning.value, props.type))
</script>
