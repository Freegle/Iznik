<template>
  <div aria-live="polite">
    <!-- aria-live: a screen reader hears the extra previews arrive when the
         button that revealed them goes. -->
    <NewsPreview
      v-for="preview in shown"
      :key="preview.id"
      :preview="preview"
      :size="size"
    />
    <!-- A post with several links shows one preview, so it does not push the
         rest of the feed off the screen. The others are one tap away. -->
    <b-button
      v-if="hiddenCount"
      variant="link"
      size="sm"
      class="show-more-previews p-0 mt-1"
      @click.stop="showAll = true"
    >
      Show {{ hiddenCount }} more {{ hiddenCount === 1 ? 'link' : 'links' }}
    </b-button>
  </div>
</template>
<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  previews: {
    type: Array,
    required: true,
  },
  size: {
    type: String,
    required: false,
    default: 'md',
  },
})

const showAll = ref(false)

const shown = computed(() =>
  showAll.value ? props.previews : props.previews.slice(0, 1)
)

const hiddenCount = computed(() => props.previews.length - shown.value.length)
</script>
