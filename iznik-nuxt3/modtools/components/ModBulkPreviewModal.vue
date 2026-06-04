<template>
  <b-modal
    id="modBulkPreviewModal"
    ref="modal"
    size="lg"
    scrollable
    @hidden="onHide"
  >
    <template #title> How members will see this offer </template>
    <template #default>
      <p class="text-muted">
        This appears on the group as a <strong>single post</strong>. When a
        member opens it they see the list of items below and turn on the ones
        they'd like — choosing how many — all in one conversation with the
        giver. Here's roughly what they'll see:
      </p>

      <h4 v-if="subject" class="h5">{{ subject }}</h4>
      <p v-if="body" class="preline text-muted">{{ body }}</p>

      <ul class="list-unstyled mb-0">
        <li
          v-for="(item, idx) in items"
          :key="item.id || idx"
          class="d-flex gap-2 align-items-center border-bottom py-2"
        >
          <img
            v-if="thumb(item)"
            :src="thumb(item)"
            class="bulkprev__thumb"
            alt=""
            @error="brokenImage"
          />
          <span v-else class="bulkprev__thumb bulkprev__thumb--empty">
            <v-icon icon="image" />
          </span>
          <div class="flex-grow-1">
            <strong>#{{ idx + 1 }} {{ item.name }}</strong>
            <div class="small text-muted">
              {{ item.quantity }} available<span v-if="conditionLabel(item)">
                · {{ conditionLabel(item) }}</span
              ><span v-if="item.dimensions"> · {{ item.dimensions }}</span>
            </div>
          </div>
          <b-form-checkbox
            switch
            disabled
            aria-label="Members turn this on to request the item"
          />
        </li>
      </ul>
      <p v-if="!items.length" class="text-muted mt-2">
        No items have been listed on this offer.
      </p>

      <p v-if="slots.length" class="small text-muted mt-3 mb-0">
        Collection times offered: {{ slots.join('; ') }}
      </p>
    </template>
    <template #footer>
      <b-button variant="white" @click="hide"> Close </b-button>
    </template>
  </b-modal>
</template>

<script setup>
import { computed } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  messageid: { type: Number, required: true },
})
const emit = defineEmits(['hidden'])

const messageStore = useMessageStore()
const { modal, hide } = useOurModal()

const message = computed(() => messageStore.byId(props.messageid) || {})
const subject = computed(() => message.value.subject || '')
const body = computed(() => message.value.textbody || '')
const items = computed(() => message.value.bulkitems || [])
const slots = computed(() => message.value.bulkslots || [])

function thumb(item) {
  const a = item.attachments && item.attachments[0]
  return (a && (a.paththumb || a.path)) || item.photourl || null
}

function conditionLabel(item) {
  const c = item.condition
  if (!c || c === 'Unknown') return ''
  return c === 'LikeNew' ? 'Like new' : c
}

// Degrade a missing thumbnail to the shared placeholder rather than a broken
// image (matches BulkItemsInterest / NewsPhotoModal).
function brokenImage(event) {
  event.target.src = '/placeholder.jpg'
}

function onHide() {
  emit('hidden')
}
</script>

<style scoped>
.bulkprev__thumb {
  width: 48px;
  height: 48px;
  object-fit: cover;
  border-radius: 6px;
  background-color: #f0f0f0;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
}
</style>
