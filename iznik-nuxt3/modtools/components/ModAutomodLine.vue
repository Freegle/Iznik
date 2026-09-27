<template>
  <div
    v-if="lineText"
    class="automod-line small text-muted d-flex align-items-center"
  >
    <v-icon icon="robot" class="me-1" />
    <span>{{ lineText }}</span>
    <b-button
      variant="link"
      size="sm"
      class="p-0 ms-1 align-baseline automod-line__why"
      @click="modal?.show()"
    >
      Why?
    </b-button>
    <ModAutomodModal
      ref="modal"
      :msgid="message?.id"
      :groupid="groupid"
      :automod="automod"
    />
  </div>
</template>
<script setup>
// One muted line under a post showing what the automated review flowchart
// decided (or would have decided, in shadow mode) about it, with a link to
// the full path behind that decision. Renders nothing at all for
// communities not in the automod trial, or when this group has no automod
// data on it yet.
import { computed, ref } from 'vue'
import { useAutomod } from '@/modtools/composables/useAutoapproveTrial'
import ModAutomodModal from '@/modtools/components/ModAutomodModal.vue'

const props = defineProps({
  message: {
    type: Object,
    default: null,
  },
  groupid: {
    type: Number,
    default: null,
  },
  pending: {
    type: Boolean,
    default: false,
  },
})

const { isAutomodGroup } = useAutomod()

const group = computed(() => {
  const groups = props.message?.groups
  if (!groups || !groups.length) return null

  return groups.find((g) => parseInt(g.groupid) === props.groupid) || null
})

const automod = computed(() => group.value?.automod || null)

const eligible = computed(
  () => isAutomodGroup(props.groupid) && !!automod.value
)

const lineText = computed(() => {
  if (!eligible.value) return ''

  const a = automod.value

  if (props.pending) {
    if (a.mode === 'shadow') {
      return a.verdict === 'approve'
        ? 'Automated review: would approve'
        : `Automated review: would hold - ${a.reason}`
    }

    return a.verdict === 'approve'
      ? 'Automated review: will publish after the wait'
      : `Automated review: holding - ${a.reason}`
  }

  const approvedby = group.value?.approvedby

  if (a.verdict === 'approve' && a.mode !== 'shadow' && !approvedby) {
    return 'Auto-approved by automated review'
  }

  if (a.verdict === 'approve' && approvedby) {
    return 'Approved by a moderator - automated review agreed'
  }

  if (a.verdict === 'hold' && approvedby) {
    return `Approved by a moderator - automated review would have held: ${a.reason}`
  }

  if (a.mode === 'shadow' && a.verdict === 'approve' && !approvedby) {
    return 'Automated review would have approved'
  }

  return ''
})

const modal = ref(null)

defineExpose({
  group,
  automod,
  eligible,
  lineText,
  modal,
})
</script>
