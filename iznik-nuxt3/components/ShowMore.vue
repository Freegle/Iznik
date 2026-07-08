<template>
  <component :is="inline ? 'span' : 'div'" class="show-more">
    <component
      :is="inline ? 'span' : 'div'"
      v-for="(item, index) in itemsToShow"
      :key="(item && item[keyfield]) ?? index"
      class="show-more__item"
      ><span v-if="inline && index > 0">, </span
      ><slot name="item" :item="item" :index="index"
        ><slot :item="item" :index="index"
          >Item {{ item[keyfield] }}</slot
        ></slot
      ></component
    ><b-button
      v-if="!expanded && overflow"
      variant="link"
      size="sm"
      class="show-more__toggle"
      :class="{ 'p-0 align-baseline ms-1': inline }"
      @click="expanded = true"
      >+{{ overflow }} more</b-button
    ><b-button
      v-if="expanded && overflow"
      variant="link"
      size="sm"
      class="show-more__toggle"
      :class="{ 'p-0 align-baseline ms-1': inline }"
      @click="expanded = false"
      >show less</b-button
    >
  </component>
</template>
<script setup>
// Truncates a list to `limit` items with a clickable "+N more" that expands to
// the full list (and a "show less" to collapse back).
//
// inline=false (default): one item per line (a <div> per item). Used by the GDPR
//   data-export page (pages/mydata.vue) for its many block lists.
// inline=true: a comma-separated inline run ("A, B, C +2 more") with the toggle
//   inline. Used for the "Posted on GroupA, GroupB" / "Also on: ..." group lists
//   across FD and ModTools, capped at limit=3.
//
// The slot may be the named #item slot OR the default slot (both are used in
// mydata.vue). In inline mode this component renders the commas, so call sites
// only supply each group's name/link.
const props = defineProps({
  items: {
    type: Array,
    required: false,
    default: () => [],
  },
  limit: {
    type: Number,
    required: false,
    default: 10,
  },
  keyfield: {
    type: String,
    required: false,
    default: 'id',
  },
  inline: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const expanded = ref(false)

// Tolerate a missing/non-array `items` (e.g. a caller binding data that is still loading, like
// mydata.vue's status.data.memberships before the export arrives). Without this, props.items.length
// threw "reading 'length'" and props.items.slice threw "items.slice is not a function", flooding
// Sentry and blanking the page.
const safeItems = computed(() =>
  Array.isArray(props.items) ? props.items : []
)

const overflow = computed(() =>
  safeItems.value.length > props.limit
    ? safeItems.value.length - props.limit
    : 0
)

const itemsToShow = computed(() => {
  if (expanded.value || safeItems.value.length <= props.limit) {
    return safeItems.value
  }
  return safeItems.value.slice(0, props.limit)
})
</script>
