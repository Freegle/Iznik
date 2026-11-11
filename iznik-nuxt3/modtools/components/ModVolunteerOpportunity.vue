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
          <b-col cols="12" md="4">
            <span v-if="groups.length"> on {{ groups[0].nameshort }} </span>
          </b-col>
        </b-row>
      </b-card-header>
      <b-card-body>
        <NoticeMessage v-if="heldError" variant="warning" class="mb-2">
          {{ heldError }}
        </NoticeMessage>
        <NoticeMessage
          v-if="groups.length && groups[0].ourPostingStatus === 'PROHIBITED'"
          variant="danger"
          class="mb-2"
        >
          This member is set not to be able to post OFFERs/WANTEDs.
        </NoticeMessage>
        <NoticeMessage
          v-if="volunteering.heldby"
          variant="warning"
          class="mb-2"
        >
          <p v-if="heldByMe">
            You held this. Other people will see a warning to check with you
            before releasing it. If you release it, it will stay in Pending.
          </p>
          <p v-else>
            Held by <strong>{{ heldbyName }}</strong
            >. Please check with them before releasing it.
          </p>
          <b-button variant="warning" @click="release">
            <v-icon icon="play" /> Release
          </b-button>
        </NoticeMessage>
        <VolunteerOpportunity
          :id="volunteering.id"
          :item="volunteering"
          :summary="false"
        />
      </b-card-body>
      <b-card-footer>
        <div v-if="heldByOther">
          This is held by someone else. The buttons are hidden so you don't
          click them by accident. Please check with them before releasing it.
        </div>
        <template v-else>
          <b-button variant="primary" class="me-1" @click="approve">
            <v-icon icon="check" /> Approve
          </b-button>
          <b-button variant="white" class="me-1" @click="edit">
            <v-icon icon="pen" /> Edit
          </b-button>
          <b-button variant="danger" class="me-1" @click="confirmDelete">
            <v-icon icon="trash-alt" /> Delete
          </b-button>
          <b-button
            v-if="!volunteering.heldby"
            variant="white"
            class="me-1"
            @click="hold"
          >
            <v-icon icon="pause" /> Hold
          </b-button>
        </template>
        <ChatButton
          v-if="
            volunteering.groups &&
            volunteering.groups.length &&
            volunteering.userid
          "
          :userid="volunteering.userid"
          :groupid="volunteering.groups[0]"
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
import { useGroupStore } from '~/stores/group'
import { useMe } from '~/composables/useMe'
import { useModMe } from '~/composables/useModMe'
import { useUserStore } from '~/stores/user'

const props = defineProps({
  volunteeringid: {
    type: Number,
    required: true,
  },
})

const volunteeringStore = useVolunteeringStore()
const { heldError, guardHold } = useHeldNotice()
const groupStore = useGroupStore()
const { myid } = useMe()
const { checkWork } = useModMe()
const userStore = useUserStore()

const volunteering = computed(() =>
  volunteeringStore.byId(props.volunteeringid)
)

const modalShown = ref(false)
const showDeleteConfirm = ref(false)

// Fetch user details for display, and whoever is holding it so we can name them.
watch(
  () => [volunteering.value?.userid, volunteering.value?.heldby],
  ([userid, heldby]) => {
    if (userid) {
      userStore.fetch(userid)
    }

    if (heldby) {
      userStore.fetch(heldby)
    }
  },
  { immediate: true }
)

const heldByMe = computed(
  () => !!volunteering.value?.heldby && volunteering.value.heldby === myid.value
)

const heldByOther = computed(
  () => !!volunteering.value?.heldby && !heldByMe.value
)

const heldbyName = computed(() => {
  const heldby = volunteering.value?.heldby
  return heldby ? userStore.byId(heldby)?.displayname || '' : ''
})

const volUser = computed(() => {
  return volunteering.value?.userid
    ? userStore.byId(volunteering.value.userid)
    : null
})

const groups = computed(() => {
  const ret = []
  volunteering.value?.groups?.forEach((id) => {
    const group = groupStore?.get(id)
    if (group) {
      ret.push(group)
    }
  })
  return ret
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

async function hold() {
  await guardHold(() => volunteeringStore.hold(volunteering.value.id))
  checkWork(true)
}

async function release() {
  await volunteeringStore.release(volunteering.value.id)
  checkWork(true)
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
