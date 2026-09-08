<template>
  <div class="inline-input" data-testid="postcode-input">
    <PostCode
      :find="true"
      :pconly="true"
      :no-store="false"
      labeltext="Postcode"
      @selected="$emit('selected', $event)"
    />
  </div>
</template>
<script setup>
import PostCode from '~/components/PostCode.vue'
// The same postcode picker the give pages use, inside a bubble.
defineEmits(['selected'])
</script>
<style scoped lang="scss">
.inline-input {
  background: #fff;
  border-radius: 12px;
  margin: 0.3rem 0.6rem;
  padding: 0.5rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}
</style>
