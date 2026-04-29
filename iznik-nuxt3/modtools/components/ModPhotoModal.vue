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

  <b-modal
    ref="aiDeleteModal"
    title="Remove AI Image"
    no-stacking
  >
    <template #default>
      <p>This is an AI-generated image. Why are you removing it?</p>
      <b-button
        variant="outline-secondary"
        class="d-block w-100 mb-2"
        @click="confirmRemove(false)"
      >
        Not relevant to this post
      </b-button>
      <b-button
        variant="outline-danger"
        class="d-block w-100"
        @click="confirmRemove(true)"
      >
        Bad AI image for any post of this item
      </b-button>
    </template>
    <template #footer>
      <b-button variant="white" @click="hideAiDeleteModal">Cancel</b-button>
    </template>
  </b-modal>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useOurModal } from '~/composables/useOurModal'

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
const aiDeleteModal = ref(null)
const pendingRemoveId = ref(null)

const message = computed(() => messageStore.byId(props.messageid))

const attachment = computed(() => {
  return message.value?.attachments?.find((a) => a.id === props.attachmentid)
})

const externalmods = computed(() => {
  const raw = attachment.value?.externalmods || attachment.value?.mods
  if (raw) {
    try {
      const jsonmods = typeof raw === 'string' ? JSON.parse(raw) : raw
      if (!jsonmods) return {}
      return jsonmods
    } catch (e) {
      return {}
    }
  }
  return {}
})

async function updatedPhoto() {
  await messageStore.patch({ id: props.messageid })
}

function hideAiDeleteModal() {
  aiDeleteModal.value?.hide()
  pendingRemoveId.value = null
}

async function removePhoto(id) {
  if (externalmods.value?.ai) {
    pendingRemoveId.value = id
    aiDeleteModal.value?.show()
    return
  }

  await doRemove(id, false)
}

async function confirmRemove(isBadForAnyPost) {
  const id = pendingRemoveId.value
  aiDeleteModal.value?.hide()
  pendingRemoveId.value = null
  await doRemove(id, isBadForAnyPost)
}

async function doRemove(id, isBadForAnyPost) {
  const attachments = []

  message.value?.attachments?.forEach((a) => {
    if (a.id !== id) {
      attachments.push(a.id)
    }
  })

  const patch = { id: props.messageid, attachments }
  if (isBadForAnyPost) {
    patch.badAIImages = [id]
  }

  await messageStore.patch(patch)
}

defineExpose({ show, hide, doRemove, confirmRemove, pendingRemoveId })
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
