<template>
  <div class="feed-search">
    <input
      class="feed-search__input"
      type="text"
      placeholder="Search items..."
      :value="modelValue"
      @input="$emit('update:modelValue', $event.target.value)"
    />
  </div>
</template>

<script setup>
defineProps({
  modelValue: {
    type: String,
    default: '',
  },
})

defineEmits(['update:modelValue'])
</script>

<style scoped lang="scss">
.feed-search {
  position: sticky;
  top: 0;
  z-index: 10;
  padding: 8px 12px;
  background: #ffffff;

  &__input {
    width: 100%;
    padding: 6px 12px;
    border: 1px solid #e0e0e0;
    border-radius: 20px;
    background: #f9f9f9;
    font-size: 14px;
    line-height: 1.4;
    outline: none;
    box-sizing: border-box;

    &::placeholder {
      color: #999999;
    }

    &:focus {
      border-color: var(--header-bg, #1d6607);
      background: #ffffff;
    }
  }
}
</style>
