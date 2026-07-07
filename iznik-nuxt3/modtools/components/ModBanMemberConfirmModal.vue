<template>
  <div>
    <b-modal
      id="banMemberModal"
      ref="modal"
      title="Ban Member"
      size="lg"
      no-stacking
    >
      <template #default>
        <NoticeMessage v-if="homeGroup" variant="danger" class="mb-2">
          <p>
            You are banning this member on their home group. This should be an
            absolute last resort - it's basically stopping them using Freegle at
            all.
          </p>
          <p>
            Please don't ban members on their home group because they've joined
            other groups. Let those other groups ban them if they wish.
          </p>
        </NoticeMessage>
        <NoticeMessage v-else variant="info" class="mb-2">
          Please be responsible in how you use this feature - it should be a
          last resort.
        </NoticeMessage>
        <p>
          You must enter a reason for banning a member. This will be flagged to
          any other groups that a member is on.
        </p>
        <b-form-input
          v-model="reason"
          type="text"
          placeholder="Enter a reason"
          class="mt-2 mb-2"
        />
      </template>
      <template #footer>
        <b-button variant="white" @click="hide"> Close </b-button>
        <b-button variant="primary" :disabled="!userid" @click="ban">
          Ban
        </b-button>
      </template>
    </b-modal>
  </div>
</template>
<script setup>
import { ref, computed, onMounted } from 'vue'
import Wkt from 'wicket'
import { useUserStore } from '~/stores/user'
import { useModGroupStore } from '@/stores/modgroup'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  userid: {
    type: Number,
    required: true,
  },
  groupid: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['confirm'])

const modGroupStore = useModGroupStore()
const userStore = useUserStore()
const { modal, show, hide } = useOurModal()

const homeGroup = ref(false)
const reason = ref(null)

const group = computed(() => modGroupStore.get(props.groupid))
const user = computed(() => userStore.byId(props.userid))

function ban() {
  if (reason.value) {
    emit('confirm', reason.value)
    hide()
  }
}

onMounted(() => {
  const area = group.value?.poly || group.value?.polyofficial
  if (area) {
    try {
      const wkt = new Wkt.Wkt()
      wkt.read(area)
      const obj = wkt.toObject()
      const bounds = obj.getBounds()

      const lat = user.value?.settings?.mylocation?.lat
      const lng = user.value?.settings?.mylocation?.lng

      if (
        (lat || lng) &&
        (user.value.memberships.length === 1 || bounds.contains([lat, lng]))
      ) {
        homeGroup.value = true
      }
    } catch (e) {
      // If geometry parsing fails, fall through to generic warning.
    }
  }
})

defineExpose({ show, hide })
</script>
