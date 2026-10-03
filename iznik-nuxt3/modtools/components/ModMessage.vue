<template>
  <div v-if="message" class="mod-message">
    <div class="d-flex justify-content-between align-items-start flex-wrap">
      <div>
        <span class="badge bg-secondary me-2">{{ message.type }}</span>
        <strong>{{ message.subject }}</strong>
      </div>
      <div class="text-muted small">{{ datetimeshort(message.arrival) }}</div>
    </div>

    <NoticeMessage v-if="message.deleted" variant="danger" class="mt-2">
      Taken down<span v-if="message.spamreason">: {{ message.spamreason }}</span>
    </NoticeMessage>
    <NoticeMessage v-else variant="info" class="mt-2"> Published </NoticeMessage>

    <NoticeMessage
      v-for="(reason, index) in contentCheckReasons"
      :key="'ccr-' + index"
      variant="warning"
      class="mt-2"
    >
      {{ reason }}
    </NoticeMessage>

    <p v-if="fromUser" class="mt-2 mb-1">
      Posted by
      <nuxt-link :to="'/support/' + fromUser.id">
        {{ fromUser.displayname }}
      </nuxt-link>
    </p>

    <div v-if="editing">
      <label class="visually-hidden" :for="'subject-' + messageid">
        Subject
      </label>
      <b-form-input
        :id="'subject-' + messageid"
        v-model="editSubject"
        class="mb-2"
      />
      <label class="visually-hidden" :for="'body-' + messageid">
        Description
      </label>
      <!-- eslint-disable-next-line -->
      <b-form-textarea
        :id="'body-' + messageid"
        v-model="editBody"
        rows="6"
        class="mb-2"
      />
      <b-button variant="primary" size="sm" @click="saveEdit">
        Save
      </b-button>
      <b-button variant="white" size="sm" class="ms-1" @click="cancelEdit">
        Cancel
      </b-button>
    </div>
    <p v-else class="mt-2">{{ message.textbody }}</p>

    <div v-if="attachments.length" class="d-flex flex-wrap gap-2 mt-2">
      <ModPhoto
        v-for="attachment in attachments"
        :key="attachment.id"
        :messageid="message.id"
        :attachmentid="attachment.id"
      />
    </div>

    <ModSpammer
      v-if="fromUser?.spammer"
      :userid="fromUser.id"
      class="mt-2"
    />

    <div v-if="!noactions" class="mt-3 d-flex flex-wrap gap-2">
      <template v-if="message.deleted">
        <b-button variant="primary" size="sm" @click="restore">
          Restore
        </b-button>
      </template>
      <template v-else>
        <b-button variant="secondary" size="sm" @click="startEdit">
          Edit
        </b-button>
        <b-button variant="danger" size="sm" @click="showTakeDown = true">
          Take down
        </b-button>
      </template>
    </div>

    <div v-if="showTakeDown" class="mt-2">
      <label class="visually-hidden" :for="'reason-' + messageid">
        Reason
      </label>
      <b-form-input
        :id="'reason-' + messageid"
        v-model="takeDownReason"
        placeholder="Reason the poster will be told"
        class="mb-2"
      />
      <b-button
        variant="danger"
        size="sm"
        :disabled="!takeDownReason"
        @click="takeDown"
      >
        Confirm take down
      </b-button>
      <b-button
        variant="white"
        size="sm"
        class="ms-1"
        @click="showTakeDown = false"
      >
        Cancel
      </b-button>
    </div>
  </div>
  <div v-else class="text-muted small">Loading...</div>
</template>
<script setup>
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import { datetimeshort } from '~/composables/useTimeFormat'
import NoticeMessage from '~/components/NoticeMessage'

const ModPhoto = defineAsyncComponent(() =>
  import('~/modtools/components/ModPhoto.vue')
)
const ModSpammer = defineAsyncComponent(() =>
  import('~/modtools/components/ModSpammer.vue')
)

const props = defineProps({
  messageid: {
    type: Number,
    required: true,
  },
  noactions: {
    type: Boolean,
    default: false,
  },
})

const messageStore = useMessageStore()
const userStore = useUserStore()

const message = computed(() => messageStore.byId(props.messageid))

watch(
  () => props.messageid,
  async (id) => {
    if (id && !messageStore.byId(id)) {
      await messageStore.fetch(id)
    }
  },
  { immediate: true }
)

// V2 API returns fromuser as a numeric ID. Resolve from user store reactively.
const fromUserId = computed(() => {
  if (!message.value) return null
  const fu = message.value.fromuser
  if (!fu) return null
  return typeof fu === 'number' ? fu : fu.id
})

watch(
  fromUserId,
  (uid) => {
    if (uid && !userStore.byId(uid)) {
      userStore.fetch(uid)
    }
  },
  { immediate: true }
)

const fromUser = computed(() => {
  if (!fromUserId.value) return null
  return userStore.byId(fromUserId.value) || null
})

const attachments = computed(() => message.value?.attachments || [])

// contentcheck_reasons can arrive as an array of strings/objects, a JSON
// string, or (rarely) a malformed string. Tolerate all three shapes.
const contentCheckReasons = computed(() => {
  const raw = message.value?.contentcheck_reasons
  if (!raw) return []

  let parsed = raw

  if (typeof raw === 'string') {
    try {
      parsed = JSON.parse(raw)
    } catch (e) {
      // Not JSON - treat the whole string as a single reason.
      return [raw]
    }
  }

  if (!Array.isArray(parsed)) return []

  return parsed.map((entry) => {
    if (typeof entry === 'string') return entry
    return [entry.category, entry.detail || entry.check]
      .filter(Boolean)
      .join(': ')
  })
})

const editing = ref(false)
const editSubject = ref(null)
const editBody = ref(null)

function startEdit() {
  editSubject.value = message.value.subject
  editBody.value = message.value.textbody
  editing.value = true
}

function cancelEdit() {
  editing.value = false
}

async function saveEdit() {
  await messageStore.patch({
    id: message.value.id,
    action: 'Edit',
    subject: editSubject.value,
    textbody: editBody.value,
  })
  editing.value = false
}

const showTakeDown = ref(false)
const takeDownReason = ref(null)

async function takeDown() {
  await messageStore.patch({
    id: message.value.id,
    action: 'TakeDown',
    reason: takeDownReason.value,
  })
  showTakeDown.value = false
  takeDownReason.value = null
}

async function restore() {
  await messageStore.patch({
    id: message.value.id,
    action: 'Restore',
  })
}
</script>
