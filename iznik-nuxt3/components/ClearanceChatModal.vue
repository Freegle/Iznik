<template>
  <b-modal
    v-model="show"
    size="lg"
    :title="title"
    hide-footer
    scrollable
    body-class="clearance-chatmodal__body"
    data-testid="clearance-chat-modal"
  >
    <!-- ChatPane is the real conversation UI; load it only when the modal opens so
         the offerer never leaves the management page to read/reply. -->
    <ChatPane v-if="show && chatid" :id="chatid" />
    <p v-else class="text-muted">No chat with this person yet.</p>
  </b-modal>
</template>

<script setup>
import { computed, defineAsyncComponent } from 'vue'

const ChatPane = defineAsyncComponent(() => import('~/components/ChatPane'))

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  chatid: { type: Number, default: null },
  title: { type: String, default: 'Chat' },
})
const emit = defineEmits(['update:modelValue'])

const show = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

defineExpose({ show })
</script>

<style scoped lang="scss">
:deep(.clearance-chatmodal__body) {
  /* Give the conversation room without taking over the whole screen. */
  height: 70vh;
  padding: 0;
}
</style>
