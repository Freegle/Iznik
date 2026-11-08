<template>
  <div v-if="split" class="mymessage-items" data-testid="autosplit-section">
    <NoticeMessage
      v-if="open"
      variant="info"
      class="mb-2"
      data-testid="autosplit-notice"
    >
      We've listed this as {{ count }} items, so people can say which they want.
      Mark each one TAKEN when it goes.
      <div class="mymessage-items__actions">
        <b-button
          size="sm"
          variant="white"
          data-testid="autosplit-edit"
          @click.stop="showEdit = true"
        >
          Edit items
        </b-button>
        <b-button
          size="sm"
          variant="white"
          :disabled="undoing"
          data-testid="autosplit-undo"
          @click.stop="undo"
        >
          Undo
        </b-button>
      </div>
      <div v-if="undoError" class="text-danger mt-1" role="alert">
        {{ undoError }}
      </div>
    </NoticeMessage>
    <MessageItemList :id="id" :editable="open" @all-taken="emit('all-taken')" />
    <AutoSplitEditModal v-if="showEdit" :id="id" @hidden="showEdit = false" />
  </div>
</template>
<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { useMessageStore } from '~/stores/message'
import { isAutoSplit } from '~/composables/useAutoSplit'
import MessageItemList from '~/components/MessageItemList'
import NoticeMessage from '~/components/NoticeMessage'

const AutoSplitEditModal = defineAsyncComponent(
  () => import('~/components/AutoSplitEditModal')
)

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

// all-taken: every item is now marked TAKEN, so the post can be marked TAKEN.
const emit = defineEmits(['all-taken'])

const messageStore = useMessageStore()
const message = computed(() => messageStore.byId(props.id))
const split = computed(() => isAutoSplit(message.value))
const count = computed(() => message.value?.bulkitems?.length || 0)
// Undo, edit and marking items are for a post that is still open.
const open = computed(() => split.value && !message.value?.outcomes?.length)

const showEdit = ref(false)
const undoing = ref(false)
const undoError = ref(null)
async function undo() {
  undoing.value = true
  undoError.value = null
  try {
    await messageStore.autoSplitUndo(props.id)
  } catch (e) {
    undoError.value = "Sorry, that didn't work. Please try again."
  } finally {
    undoing.value = false
  }
}
</script>
<style scoped lang="scss">
.mymessage-items {
  padding: 0 0.75rem 0.75rem;
}

.mymessage-items__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
</style>
