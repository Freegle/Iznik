<template>
  <b-modal
    ref="modal"
    scrollable
    title="How did it go?"
    no-close-on-backdrop
    @hidden="onHidden"
  >
    <template #default>
      <p>
        Please rate the freeglers you've dealt with. It helps everyone know
        who's reliable.
      </p>
      <div
        v-for="p in people"
        :key="p.userid"
        class="person"
        :data-test-userid="p.userid"
      >
        <ProfileImage
          :image="userById(p.userid)?.profile?.path"
          class="me-2"
          is-thumbnail
          size="lg"
        />
        <div class="who">
          <span class="fw-bold">{{
            userById(p.userid)?.displayname || 'A freegler'
          }}</span>
          <div class="small text-muted">
            {{ p.gave ? 'You gave them' : 'They gave you' }}
            {{ p.item || p.subject }}
          </div>
        </div>
        <div class="buttons">
          <b-button variant="primary" class="rate-up" @click="up(p)">
            <v-icon icon="thumbs-up" /> Good
          </b-button>
          <b-button variant="warning" class="rate-down" @click="down(p)">
            <v-icon icon="thumbs-down" /> Not good
          </b-button>
          <b-button variant="link" size="sm" class="rate-skip" @click="skip(p)">
            Skip
          </b-button>
        </div>
      </div>
      <p v-if="!people.length" class="mb-0">Thanks!</p>
      <UserRatingsDownModal
        v-if="downFor"
        :id="downFor"
        :key="downKey"
        @rated="rated(downFor)"
      />
    </template>
    <template #footer>
      <b-button variant="link" class="not-now" @click="notNow">
        Not now
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import {
  computed,
  defineAsyncComponent,
  onBeforeUnmount,
  onMounted,
  ref,
} from 'vue'
import { useUserStore } from '~/stores/user'
import { useOurModal } from '~/composables/useOurModal'
import { action } from '~/composables/useClientLog'
import { experimentVariant } from '~/composables/useExperiment'
import {
  ratingNudgePeople as people,
  recordSkipped,
  removeFromNudge,
  closeRatingNudge,
} from '~/composables/useRatingNudge'
import ProfileImage from '~/components/ProfileImage'

const UserRatingsDownModal = defineAsyncComponent(
  () => import('~/components/UserRatingsDownModal')
)

const SOURCE = 'rating_nudge'

// How long "Thanks!" stays up once everyone is dealt with. Closing at once hid it behind
// the closing animation, so the member never saw it.
const THANKS_MS = 1500

const userStore = useUserStore()
const { modal, hide } = useOurModal()

const downFor = ref(null)
const downKey = ref(0)
const shownWith = ref(0)
const ratedCount = ref(0)
const closing = ref(false)
let thanksTimer = null

const userById = computed(() => (id) => userStore.byId(id))

function details(extra = {}) {
  return { ...extra, variant: experimentVariant() }
}

onMounted(() => {
  shownWith.value = people.value.length
  for (const p of people.value) {
    userStore.fetch(p.userid)
  }
  action('rating_nudge_shown', details({ count: shownWith.value }))
})

function finishedWith(userid) {
  removeFromNudge(userid)
  if (!people.value.length && !thanksTimer) {
    thanksTimer = setTimeout(() => closeWith('done'), THANKS_MS)
  }
}

onBeforeUnmount(() => {
  clearTimeout(thanksTimer)
})

async function up(p) {
  await userStore.rate(p.userid, 'Up', null, null, SOURCE)
  ratedCount.value++
  finishedWith(p.userid)
}

function down(p) {
  downKey.value++
  downFor.value = p.userid
}

// The thumbs down modal has already saved the rating (UserRatingsDownModal rates on Submit).
function rated(userid) {
  downFor.value = null
  ratedCount.value++
  finishedWith(userid)
}

function skip(p) {
  recordSkipped(p.userid)
  action('rating_nudge_skipped', details())
  finishedWith(p.userid)
}

function closeWith(how) {
  if (closing.value) return
  closing.value = true
  action(
    'rating_nudge_dismissed',
    details({ how, shown: shownWith.value, rated: ratedCount.value })
  )
  hide()
}

function notNow() {
  closeWith('not_now')
}

function onHidden() {
  // Escape, or the modal hidden by a navigation: still a dismissal.
  closeWith('hidden')
  closeRatingNudge()
}
</script>
<style scoped lang="scss">
.person {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 0;
  border-top: 1px solid $color-gray--light;

  .who {
    flex: 1 1 auto;
    min-width: 0;
  }

  .buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    justify-content: flex-end;
  }
}
</style>
