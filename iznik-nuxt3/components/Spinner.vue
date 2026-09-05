<template>
  <div class="spinner-border text-freegle" :style="spinnerStyle" role="status">
    <span class="visually-hidden">Loading...</span>
  </div>
</template>

<script setup>
// The file keeps its single-word name (it's referenced as <Spinner> in ~90
// templates); give the component a multi-word registered name for the linter
// and devtools.
defineOptions({ name: 'OurSpinner' })

const props = defineProps({
  size: {
    type: Number,
    default: 50,
  },
})

const spinnerStyle = computed(() => ({
  width: `${props.size}px`,
  height: `${props.size}px`,
}))
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.text-freegle {
  color: $color-green-background;
}
</style>
