<template>
  <b-modal
    ref="modal"
    title="Why automated review decided this"
    size="md"
    hide-footer
  >
    <div v-if="automod">
      <p class="small text-muted mb-3">
        Chart version {{ automod.version
        }}<span v-if="ranAt"> &middot; ran {{ ranAt }}</span>
      </p>
      <div
        v-for="(node, index) in automod.path"
        :key="index"
        class="automod-node mb-3 pb-2"
        :class="{
          'automod-node--deciding border-start border-3 ps-2':
            isDeciding(index),
        }"
      >
        <p class="mb-1">
          <strong>{{ node.question }}</strong>
        </p>
        <p class="mb-1">
          Answer: <strong>{{ node.answer === 'yes' ? 'Yes' : 'No' }}</strong>
        </p>
        <p
          v-if="node.kind === 'text' && node.p !== null && node.p !== undefined"
          class="mb-1"
        >
          {{ Math.round(node.p * 100) }}% sure, holds above
          {{ Math.round(node.threshold * 100) }}%
        </p>
        <p v-if="node.evidence" class="mb-1 fst-italic">
          "{{ node.evidence }}"
        </p>
        <p v-if="node.model" class="small text-muted mb-1">
          Model: {{ node.model }}
        </p>
        <p v-if="isDeciding(index)" class="small text-muted mb-1">
          This step decided the outcome.
        </p>
        <div v-if="feedbackSent[index]" class="small text-muted">
          Thanks, noted
        </div>
        <b-button
          v-else
          variant="link"
          size="sm"
          class="p-0"
          @click="sendFeedback(node, index)"
        >
          This step is wrong
        </b-button>
      </div>
    </div>
  </b-modal>
</template>
<script setup>
// Full detail behind an automated review decision: the questions the flowchart
// asked, in order, what it decided at each one, and how sure it was. Always
// mounted (behind the line that owns it) and shown only when triggered via
// its exposed show().
import { computed, reactive } from 'vue'
import { useMessageStore } from '@/stores/message'
import { useOurModal } from '@/composables/useOurModal'

const props = defineProps({
  msgid: {
    type: Number,
    default: null,
  },
  groupid: {
    type: Number,
    default: null,
  },
  automod: {
    type: Object,
    default: null,
  },
})

const messageStore = useMessageStore()

const { modal, show, hide } = useOurModal({ autoShow: false })

const ranAt = computed(() => {
  if (!props.automod?.created) return null
  return new Date(props.automod.created).toLocaleString()
})

// A hold stops at the first question answered yes, so the last node on a held
// post's path is the one that held it. An approved post passed every question and
// has no single deciding node.
const decidingIndex = computed(() => {
  const path = props.automod?.path
  if (props.automod?.verdict !== 'hold' || !path || !path.length) return -1
  return path.length - 1
})

function isDeciding(index) {
  return index === decidingIndex.value
}

const feedbackSent = reactive({})

async function sendFeedback(node, index) {
  await messageStore.postAutomodFeedback({
    msgid: props.msgid,
    groupid: props.groupid,
    node: node.node,
  })
  feedbackSent[index] = true
}

defineExpose({
  modal,
  show,
  hide,
  ranAt,
  decidingIndex,
  isDeciding,
  feedbackSent,
  sendFeedback,
})
</script>
