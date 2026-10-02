<template>
  <span class="renewal" :title="info.text">
    <span class="dot" :class="info.colour || 'none'" />
    <span v-if="showText">{{ info.text }}</span>
  </span>
</template>
<script setup>
import { computed } from 'vue'
import { renewalInfo } from '~/modtools/composables/usePartnershipFormat'

// How likely a council is to renew, as a traffic light.
const props = defineProps({
  renewal: {
    type: String,
    required: false,
    default: null,
  },
  showText: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const info = computed(() => renewalInfo(props.renewal))
</script>
<style scoped lang="scss">
.renewal {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.dot {
  display: inline-block;
  width: 0.8rem;
  height: 0.8rem;
  border-radius: 50%;
  border: 1px solid $color-gray--dark;

  &.green {
    background-color: $color-green--dark;
  }

  &.amber {
    background-color: $color-orange--dark;
  }

  &.red {
    background-color: $color-red;
  }

  &.none {
    background-color: $color-white;
  }
}
</style>
