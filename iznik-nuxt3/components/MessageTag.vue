<template>
  <div
    :class="{
      inline: inline,
      tagbadge: true,
      tagdef: def,
      'tagbadge--wanted': isWanted,
      'fw-bold': true,
      forcebreak: true,
      'text-wrap': true,
      'text-start': true,
    }"
  >
    {{ tag }}
  </div>
</template>
<script setup>
import { computed, onMounted } from 'vue'
import { useMessageStore } from '~/stores/message'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  def: {
    type: Boolean,
    required: false,
    default: false,
  },
  inline: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const messageStore = useMessageStore()

// Fetch data on mount
onMounted(async () => {
  await messageStore.fetch(props.id)
})

const message = computed(() => {
  return messageStore?.byId(props.id)
})

const isWanted = computed(() => {
  return message.value?.type === 'Wanted'
})

const tag = computed(() => {
  if (message.value?.type === 'Offer') return 'OFFER'
  if (message.value?.type === 'Wanted') return 'WANTED'
  return null
})
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.tagbadge {
  left: 10px;
  top: 10px;
  background-color: $color-success-fg;
  font-size: 1.25rem;
  color: white;
  border-radius: var(--radius-sm, 0.375rem);
  text-transform: uppercase;
  max-width: calc(100% - 20px);
  z-index: 5;
  padding: 2px 8px;

  &--wanted {
    background-color: $color-blue--light;
  }

  &.tagdef {
    left: 0px;

    @include media-breakpoint-up(sm) {
      left: 10px;
    }
  }

  &:not(.inline) {
    position: absolute;
  }
}
</style>
