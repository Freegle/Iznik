<template>
  <b-form-select v-model="order" class="ms-2" aria-label="Order">
    <option value="newest">Newest first</option>
    <option value="oldest">Oldest first</option>
  </b-form-select>
</template>
<script setup>
import { computed } from 'vue'

// Newest or oldest membership first. Works with any choice in ModMemberTypeSelect.
const props = defineProps({
  modelValue: {
    type: String,
    required: false,
    default: 'newest',
  },
})

const emit = defineEmits(['update:modelValue'])

const order = computed({
  get() {
    return props.modelValue
  },
  set(val) {
    emit('update:modelValue', val)
  },
})
</script>
<style scoped>
select {
  max-width: 150px;
}
</style>
