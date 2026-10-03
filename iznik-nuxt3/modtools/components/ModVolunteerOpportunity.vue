<template>
  <div v-if="volunteering && volunteering.pending">
    <b-card no-body>
      <b-card-header>
        <b-row>
          <b-col cols="6" md="4">
            Opportunity
            <v-icon icon="hashtag" scale="0.75" class="text-muted" />{{
              volunteering.id
            }}
          </b-col>
          <b-col cols="6" md="4">
            <span v-if="volunteering.userid">
              {{ volUser?.displayname }}
              <span class="text-muted">
                <v-icon icon="hashtag" scale="0.75" class="text-muted" />{{
                  volunteering.userid
                }}
              </span>
            </span>
            <span v-else> System added </span>
          </b-col>
        </b-row>
      </b-card-header>
      <b-card-body>
        <NoticeMessage v-if="heldError" variant="warning" class="mb-2">
          {{ heldError }}
        </NoticeMessage>
        <NoticeMessage
          v-if="volUser?.postingstatus === 'PROHIBITED'"
          variant="danger"
          class="mb-2"
        >
          This member is set not to be able to post OFFERs/WANTEDs.
        </NoticeMessage>
        <VolunteerOpportunity
          :id="volunteering.id"
          :item="volunteering"
          :summary="false"
        />
      </b-card-body>
      <b-card-footer>
        <b-button variant="primary" class="me-1" @click="approve">
          <v-icon icon="check" /> Approve
        </b-button>
        <b-button variant="white" class="me-1" @click="edit">
          <v-icon icon="pen" /> Edit
        </b-button>
        <b-button variant="danger" class="me-1" @click="confirmDelete">
          <v-icon icon="trash-alt" /> Delete
        </b-button>
        <ChatButton
          v-if="volunteering.userid"
          :userid="volunteering.userid"
          title="Chat"
          variant="white"
          class="me-1"
        />
      </b-card-footer>
    </b-card>
    <VolunteerOpportunityModal
      v-if="modalShown"
      :id="volunteering.id"
      :volunteering="volunteering"
      :start-edit="true"
      @hidden="modalShown = false"
    />
    <ConfirmModal
      v-if="showDeleteConfirm"
      ref="confirmDeleteModal"
      title="Delete Volunteer Opportunity"
      message="Are you sure you want to delete this volunteer opportunity?"
      @confirm="deleteme"
      @hidden="showDeleteConfirm = false"
    />
  </div>
</template>
<script setup>
import { ref, computed, watch } from 'vue'
import { useVolunteeringStore } from '@/stores/volunteering'
import { useHeldNotice } from '~/composables/useHeldNotice'
import { useUserStore } from '~/stores/user'

const props = defineProps({
  volunteeringid: {
    type: Number,
    required: true,
  },
})

const volunteeringStore = useVolunteeringStore()
const { heldError, guardHold } = useHeldNotice()
const userStore = useUserStore()

const volunteering = computed(() =>
  volunteeringStore.byId(props.volunteeringid)
)

const modalShown = ref(false)
const showDeleteConfirm = ref(false)

// Fetch user details for display
watch(
  () => volunteering.value?.userid,
  (userid) => {
    if (userid) {
      userStore.fetch(userid)
    }
  },
  { immediate: true }
)

const volUser = computed(() => {
  return volunteering.value?.userid
    ? userStore.byId(volunteering.value.userid)
    : null
})

function edit() {
  modalShown.value = true
}

function confirmDelete() {
  showDeleteConfirm.value = true
}

async function deleteme() {
  await volunteeringStore.delete(volunteering.value.id)
  showDeleteConfirm.value = false
}

async function approve() {
  await guardHold(() =>
    volunteeringStore.save({
      id: volunteering.value.id,
      pending: false,
    })
  )

  // Only drop it from the list if the save actually happened. A refused approve
  // must leave it on screen with the reason showing.
  if (!heldError.value) {
    volunteeringStore.remove(volunteering.value.id)
  }
}
</script>
