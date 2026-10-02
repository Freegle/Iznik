<template>
  <b-modal
    v-if="attachment"
    :id="'photoModal-' + attachmentid"
    ref="modal"
    :title="message?.subject"
    size="lg"
    no-stacking
    ok-only
  >
    <template #default>
      <PostPhoto
        v-bind="attachment"
        :thumbnail="false"
        :externalmods="externalmods"
        @remove="removePhoto"
        @updated="updatedPhoto"
      />
    </template>

    <template #footer>
      <b-button variant="white" @click="hide"> Close </b-button>
    </template>
  </b-modal>

  <AiImageRemoveModal
    ref="aiRemoveModal"
    @choose="confirmRemove"
    @cancel="pendingRemoveId = null"
  />
</template>

<script setup>
import { ref, computed } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useOurModal } from '~/composables/useOurModal'
import {
  attachmentMods,
  isAIAttachment,
  removePhotoPatch,
} from '~/composables/usePhotoRemoval'
import AiImageRemoveModal from '~/components/AiImageRemoveModal.vue'

const props = defineProps({
  messageid: {
    type: Number,
    required: true,
  },
  attachmentid: {
    type: Number,
    required: true,
  },
})

const { modal, show, hide } = useOurModal()
const messageStore = useMessageStore()
const aiRemoveModal = ref(null)
const pendingRemoveId = ref(null)

const message = computed(() => messageStore.byId(props.messageid))

const attachment = computed(() => {
  return message.value?.attachments?.find((a) => a.id === props.attachmentid)
})

const externalmods = computed(() => attachmentMods(attachment.value))

async function updatedPhoto() {
  await messageStore.patch({ id: props.messageid })
}

// An AI-generated image gets the "why are you removing it?" question first, because one
// of the answers ("bad for any post of this item") stops AI images for the item. Every
// moderator gets that question, not only Support (Discourse 9630, post 92). Anything
// else comes straight off.
async function removePhoto(id) {
  if (isAIAttachment(attachment.value)) {
    pendingRemoveId.value = id
    aiRemoveModal.value?.show()
    return
  }

  await doRemove(id, false)
}

async function confirmRemove(isBadForAnyPost) {
  const id = pendingRemoveId.value
  pendingRemoveId.value = null
  if (id) {
    await doRemove(id, isBadForAnyPost)
  }
}

async function doRemove(id, isBadForAnyPost) {
  await messageStore.patch(
    removePhotoPatch(
      { id: props.messageid, attachments: message.value?.attachments },
      id,
      isBadForAnyPost
    )
  )
}

defineExpose({
  show,
  hide,
  removePhoto,
  doRemove,
  confirmRemove,
  pendingRemoveId,
})
</script>

<style scoped>
.square {
  object-fit: cover;
  max-width: 200px;
  min-width: 200px;
  min-height: 200px;
  max-height: 200px;
  width: 200px;
  height: 200px;
}

:deep(img) {
  width: 100%;
}
</style>
