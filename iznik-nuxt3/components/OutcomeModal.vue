<template>
  <b-modal
    ref="modal"
    scrollable
    size="lg"
    no-stacking
    dialog-class="maxWidth"
    @hidden="onHide"
  >
    <template #title>
      <h3 class="d-flex justify-content-between">
        {{ message.subject }}
        <div>
          <MessageAvailability
            :availablenow="message.availablenow"
            :availableinitially="message.availableinitially"
            :bulkcount="message.bulkcount"
            badge-class="lg ms-2"
          />
        </div>
      </h3>
    </template>
    <template #default>
      <NoticeMessage v-if="type === 'Withdrawn'" variant="info">
        <p>
          If everything worked out OK, then use
          <strong
            >Mark as <span v-if="message.type === 'Offer'">TAKEN</span
            ><span v-else>RECEIVED</span></strong
          >
          to let us know.
        </p>
        <div v-if="message.type === 'Offer'">
          <p>
            Only use <strong>Withdraw</strong> if you didn't manage to pass on
            this item on Freegle, and it's no longer available.
          </p>
        </div>
        <div v-else>
          <p>
            Only use <strong>Withdraw</strong> if you are no longer looking for
            this item.
          </p>
        </div>
      </NoticeMessage>
      <div v-if="type === 'Taken'">
        <OutcomeBy
          :availablenow="
            typeof message.availablenow === 'number' ? message.availablenow : 1
          "
          :type="type"
          :msgid="message.id"
          :left="left"
          :taken-by="takenBy"
          :choose-error="chooseError"
          :invalid="submittedWithNoSelectedUser"
          @took-users="took"
        />
        <div v-if="showSplitChoice" class="mt-3">
          <label class="strong">Is that everything?</label>
          <b-button-group class="d-block mt-1">
            <b-button
              :variant="allGone ? 'primary' : 'secondary'"
              :pressed="allGone"
              class="all-gone"
              @click="allGone = true"
            >
              That's everything gone
            </b-button>
            <b-button
              :variant="allGone ? 'secondary' : 'primary'"
              :pressed="!allGone"
              class="some-left"
              @click="allGone = false"
            >
              There's still some left
            </b-button>
          </b-button-group>
          <p v-if="!allGone" class="text-muted small mt-2">
            We'll leave your post up, and show it as part gone.
          </p>
        </div>
      </div>
      <div v-if="showCompletion">
        <div
          v-if="type === 'Taken' && tookUsers?.length && otherRepliers?.length"
        >
          <label class="strong">
            Message for other people who replied (optional):
          </label>
          <b-form-textarea
            v-model="completionMessage"
            :rows="3"
            :max-rows="6"
            class="mt-1"
            placeholder="e.g. Thanks for the interest. Sorry, this went to someone else."
          />
          <p class="mt-1 text-muted small">
            <v-icon icon="lock" /> We'll send this same message privately in
            Chat to each other freegler who replied to your post.
          </p>
        </div>
        <hr class="mb-0" />
        <div>
          <label class="mt-3 strong">
            How do you feel about freegling just now?
          </label>
          <b-button-group class="d-none d-md-block mt-1">
            <b-button
              :pressed="happiness === 'Happy'"
              :variant="happiness === 'Happy' ? 'info' : 'primary'"
              size="lg"
              class="shadow-none"
              @click="happiness = 'Happy'"
            >
              <v-icon icon="smile" scale="2" /> Happy
            </b-button>
            <b-button
              :pressed="happiness === 'Fine'"
              :variant="happiness === 'Fine' ? 'info' : 'white'"
              size="lg"
              class="shadow-none"
              @click="happiness = 'Fine'"
            >
              <v-icon icon="meh" scale="2" color="grey" /> Fine
            </b-button>
            <b-button
              :pressed="happiness === 'Unhappy'"
              :variant="happiness === 'Unhappy' ? 'info' : 'danger'"
              size="lg"
              class="shadow-none"
              @click="happiness = 'Unhappy'"
            >
              <v-icon icon="frown" scale="2" /> Sad
            </b-button>
          </b-button-group>
          <b-button-group class="d-block d-md-none">
            <b-button
              :pressed="happiness === 'Happy'"
              :variant="happiness === 'Happy' ? 'info' : 'primary'"
              size="md"
              class="shadow-none"
              @click="happiness = 'Happy'"
            >
              <v-icon icon="smile" scale="2" /> Happy
            </b-button>
            <b-button
              :pressed="happiness === 'Fine'"
              :variant="happiness === 'Fine' ? 'info' : 'white'"
              size="md"
              class="shadow-none"
              @click="happiness = 'Fine'"
            >
              <v-icon icon="meh" scale="2" color="grey" /> Fine
            </b-button>
            <b-button
              :pressed="happiness === 'Unhappy'"
              :variant="happiness === 'Unhappy' ? 'info' : 'danger'"
              size="md"
              class="shadow-none"
              @click="happiness = 'Unhappy'"
            >
              <v-icon icon="frown" scale="2" /> Sad
            </b-button>
          </b-button-group>
        </div>
        <NoticeMessage
          v-if="happiness !== null && type === 'Taken'"
          class="mt-2"
        >
          You can use the thumbs up/down buttons above to say how things went
          with other freeglers.
        </NoticeMessage>
        <div>
          <label class="mt-4 strong"> It went well/badly because: </label>
          <b-form-textarea
            v-model="comments"
            rows="3"
            max-rows="6"
            class="border-primary mt-1"
          />
          <div class="text-muted small mt-2">
            <span
              v-if="
                happiness === null ||
                happiness === 'Happy' ||
                happiness === 'Fine'
              "
            >
              <v-icon icon="globe-europe" /> Your comments may be public
            </span>
            <span v-if="happiness === 'Unhappy'">
              <v-icon icon="lock" /> Your comments will only go to our
              volunteers
            </span>
          </div>
        </div>
      </div>
      <NoticeMessage
        v-if="isBulk && message.availableinitially > 1 && left > 0"
        variant="warning"
      >
        There will still be some left. If you're giving them all away now,
        please adjust the numbers above.
      </NoticeMessage>
    </template>
    <template #footer>
      <div>
        <div class="d-flex flex-wrap justify-content-end">
          <b-button variant="secondary" @click="cancel"> Cancel </b-button>
          <SpinButton
            variant="primary"
            icon-name="save"
            :label="buttonLabel"
            class="ms-2"
            @handle="submit"
          />
        </div>
      </div>
    </template>
  </b-modal>
</template>
<script setup>
import { ref, computed } from 'vue'
import OutcomeBy from './OutcomeBy'
import MessageAvailability from './MessageAvailability'
import SpinButton from './SpinButton'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'
import NoticeMessage from '~/components/NoticeMessage'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  takenBy: {
    type: Object,
    required: false,
    default: null,
  },
  type: {
    type: String,
    required: false,
    default: null,
  },
})

const emit = defineEmits(['hidden'])

const messageStore = useMessageStore()
const authStore = useAuthStore()
const { modal, hide } = useOurModal()

const { $bus } = useNuxtApp()

const happiness = ref(null)
const comments = ref(null)
const tookUsers = ref([])
const chooseError = ref(false)
const submittedWithNoSelectedUser = ref(false)
const completionMessage = ref(null)

// Marking a post TAKEN usually means it has all gone, so that is where this
// starts; the choice below is how you say otherwise.
const allGone = ref(true)

const message = computed(() => {
  return messageStore?.byId(props.id)
})

// Bulk clearance offers still count items out one by one. Ordinary posts do
// not: we ask who took some and whether any are left, and never make the
// member reconcile numbers.
const isBulk = computed(() => (message.value?.bulkcount ?? 0) > 0)

const showSplitChoice = computed(
  () =>
    props.type === 'Taken' &&
    !isBulk.value &&
    message.value.availableinitially > 1
)

const left = computed(() => {
  let leftVal = message.value.availablenow ? message.value.availablenow : 1

  for (const u of tookUsers.value) {
    if (u.userid >= 0) {
      leftVal -= u.count || 0
    }
  }

  return leftVal
})

const showCompletion = computed(() => {
  // We ask how it went once the post is finished with: on withdrawal, on a
  // single item, when a bulk offer has counted down to nothing, and on an
  // ordinary post when the member says everything has gone.
  if (props.type === 'Withdrawn' || message.value.availableinitially === 1) {
    return true
  }

  return isBulk.value ? left.value === 0 : allGone.value
})

const otherRepliers = computed(() => {
  const ret = []

  if (message.value?.replies) {
    message.value.replies.forEach((u) => {
      if (u.userid > 0) {
        let found = false

        for (const t of tookUsers.value) {
          if (t.userid === u.userid) {
            found = true
            break
          }
        }

        if (!found) {
          ret.push({
            userid: u.userid,
            displayname: u.displayname,
          })
        }
      }
    })
  }

  return ret
})

const submitDisabled = computed(() => {
  // Only meaningful while counts exist: it catches a bulk single-item post
  // where nobody has actually been given anything. On an ordinary post the
  // empty-selection check in submit() does that job.
  const ret =
    isBulk.value &&
    props.type === 'Taken' &&
    message.value.availableinitially === 1 &&
    left.value === 1
  return ret
})

// Outcomes themselves are global (a physical item is taken everywhere), but
// the donation-ask modal that listens to the `outcome` bus event needs ONE
// group to render its fundraising context. Pick the message group the user
// is also a member of, preferring most-recent arrival; fall back to the
// message's first group when there's no overlap.
const groupid = computed(() => {
  const groups = message.value?.groups || []
  if (!groups.length) return null
  const myGroupIds = new Set(
    (authStore.groups || []).map((g) => Number.parseInt(g.groupid))
  )
  const shared = groups
    .filter((g) => myGroupIds.has(Number.parseInt(g.groupid)))
    .sort((a, b) => new Date(b.arrival || 0) - new Date(a.arrival || 0))
  return (shared[0] || groups[0]).groupid
})

const buttonLabel = computed(() => {
  if (!props.type) {
    return 'Submit'
  } else if (props.type === 'Withdrawn') {
    return 'Withdraw'
  } else {
    return 'Mark as ' + props.type.toUpperCase()
  }
})

function took(users) {
  tookUsers.value = users
}

async function submit(callback) {
  if (props.type === 'Taken' && !tookUsers.value.length) {
    chooseError.value = true
    submittedWithNoSelectedUser.value = true
    callback()
    return
  } else {
    chooseError.value = false
    submittedWithNoSelectedUser.value = false
  }

  let complete = false

  if (submitDisabled.value) {
    chooseError.value = true
    callback()
  } else {
    if (props.type === 'Withdrawn' || props.type === 'Received') {
      complete = true
    } else {
      complete = isBulk.value ? left.value === 0 : allGone.value

      for (const u of tookUsers.value) {
        const userid = u.userid > 0 ? u.userid : null

        if (!isBulk.value) {
          // No count: the server records one against the taker, which is all
          // the "part gone" badge needs to know.
          await messageStore.addBy(message.value.id, userid)
        } else if (u.count > 0) {
          await messageStore.addBy(message.value.id, userid, u.count)
        } else {
          await messageStore.removeBy(message.value.id, userid)
        }
      }
    }

    if (complete) {
      // The post is being taken/received.
      await messageStore.update({
        action: 'Outcome',
        id: props.id,
        outcome: props.type,
        happiness: happiness.value,
        comment: comments.value,
        message: completionMessage.value,
      })

      // Refetch the message to ensure the outcome is reflected in the store
      // For withdrawn messages that were pending, the fetch may fail as they get deleted
      try {
        await messageStore.fetch(props.id)
      } catch (error) {
        if (props.type === 'Withdrawn') {
          // Suppress fetch errors for withdrawn messages as they may have been deleted
          console.log(
            'Suppressed fetch error for withdrawn message:',
            error.message
          )
        } else {
          throw error
        }
      }
    }

    callback()
    hide()
  }
}

function onHide() {
  // We're having trouble capturing events from this modal, so use root as a bus.
  $bus.$emit('outcome', {
    groupid: groupid.value,
    outcome: props.type,
  })

  tookUsers.value = []
  happiness.value = null
  allGone.value = true
  emit('hidden')
}

function cancel() {
  tookUsers.value = []
  happiness.value = null
  allGone.value = true
  hide()
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

@include media-breakpoint-down(md) {
  :deep(.maxWidth) {
    max-width: calc(100vw - 16px);
  }
}

:deep(.btn-group .btn) {
  border: 1px solid black;
}
</style>
