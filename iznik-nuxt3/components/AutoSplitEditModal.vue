<template>
  <b-modal
    ref="modal"
    scrollable
    title="Edit items"
    no-trap
    @hidden="emit('hidden')"
  >
    <p class="text-muted small">
      Change how many there are, or take an item off the list. To change the
      names, use Undo and edit your post.
    </p>
    <ul class="autosplit-edit__list">
      <li
        v-for="(item, idx) in items"
        :key="item.id"
        class="autosplit-edit__item"
        :data-testid="'edit-item-' + item.id"
      >
        <label :for="formId + '-qty-' + idx" class="autosplit-edit__name">{{
          item.name
        }}</label>
        <b-form-input
          :id="formId + '-qty-' + idx"
          v-model.number="item.quantity"
          type="number"
          min="1"
          max="99"
          class="autosplit-edit__qty"
        />
        <b-button
          variant="link"
          class="autosplit-edit__remove"
          :aria-label="'Take ' + item.name + ' off the list'"
          :data-testid="'edit-remove-' + item.id"
          @click="remove(idx)"
        >
          <v-icon icon="trash-alt" />
        </b-button>
      </li>
    </ul>
    <p v-if="items.length < 2" class="text-danger small" role="alert">
      Keep at least two items. To make it one post again, use Undo.
    </p>
    <template #footer>
      <b-button variant="white" @click="hide">Cancel</b-button>
      <b-button
        variant="primary"
        :disabled="!canSave"
        data-testid="edit-items-save"
        @click="save"
      >
        Save
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import { computed, ref, useId } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['hidden'])

const formId = useId()
const messageStore = useMessageStore()
const { modal, hide } = useOurModal()

const message = computed(() => messageStore.byId(props.id))
const items = ref(
  (message.value?.bulkitems || []).map((i) => ({
    id: i.id,
    name: i.name,
    quantity: i.quantity || 1,
  }))
)

const canSave = computed(() => items.value.length >= 2)

function remove(idx) {
  items.value.splice(idx, 1)
}

async function save() {
  if (!canSave.value) return
  // The server keeps each item's name and photos; only which items stay, and how
  // many of each, are sent.
  const bulkitems = items.value.map((i) => ({
    id: i.id,
    quantity: Math.min(99, Math.max(1, parseInt(i.quantity, 10) || 1)),
  }))
  // The patch refetches the post, which can remount the parent; close first.
  emit('hidden')
  await messageStore.patch({ id: props.id, bulkitems })
}
</script>
<style scoped lang="scss">
.autosplit-edit__list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.autosplit-edit__item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.autosplit-edit__name {
  flex: 1 1 auto;
  min-width: 0;
}

.autosplit-edit__qty {
  flex: 0 0 5rem;
}

.autosplit-edit__remove {
  flex: 0 0 auto;
}
</style>
