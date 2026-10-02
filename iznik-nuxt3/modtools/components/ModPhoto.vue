<template>
  <span v-if="attachment" class="clickme">
    <PostPhoto
      v-bind="attachment"
      :externalmods="mods"
      :show-ai-badge="true"
      @remove="removePhoto"
      @updated="updatedPhoto"
      @click="showModal"
    />
    <ModPhotoModal
      v-if="zoom"
      ref="modphotomodal"
      :messageid="messageid"
      :attachmentid="attachmentid"
    />
    <AiImageRemoveModal
      ref="aiRemoveModal"
      @choose="confirmRemove"
      @cancel="pendingRemoveId = null"
    />
  </span>
</template>

<script setup>
import { ref, computed, defineAsyncComponent } from 'vue'
import { useMessageStore } from '~/stores/message'
import {
  attachmentMods,
  isAIAttachment,
  removePhotoPatch,
} from '~/composables/usePhotoRemoval'
import AiImageRemoveModal from '~/components/AiImageRemoveModal.vue'

const PostPhoto = defineAsyncComponent(
  () => import('../../components/PostPhoto')
)

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

const messageStore = useMessageStore()

const zoom = ref(false)
const modphotomodal = ref(null)
const aiRemoveModal = ref(null)
const pendingRemoveId = ref(null)

const message = computed(() => messageStore.byId(props.messageid))

const attachment = computed(() => {
  return message.value?.attachments?.find((a) => a.id === props.attachmentid)
})

const mods = computed(() => attachmentMods(attachment.value))

function showModal() {
  zoom.value = true
  modphotomodal.value?.show()
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

async function updatedPhoto() {
  await messageStore.patch({ id: props.messageid })
}

defineExpose({ removePhoto, doRemove, confirmRemove, pendingRemoveId })
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
  width: 200px;
  height: 200px;
  object-fit: cover;
}
</style>
