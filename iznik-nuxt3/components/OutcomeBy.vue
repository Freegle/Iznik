<template>
  <div>
    <div v-if="!several">
      <label :class="'strong ' + (chooseError ? 'text-danger' : '')">
        Please tell us who took this item:
      </label>
    </div>
    <div
      v-for="user in currentlySelectedUsers"
      :key="'selected-' + user.userid"
      class="layout mb-2 mt-1"
    >
      <span
        v-if="user.userid > 0"
        class="text--large fw-bold mt-1 text-start select"
      >
        {{ user.displayname }}
      </span>
      <span v-else class="text--large fw-bold mt-1 text-start select">
        <span v-if="!several">Someone else</span>
        <span v-else>Other people</span>
      </span>
      <div class="ratings">
        <div class="d-none d-md-block ms-1">
          <UserRatings v-if="user.userid > 0" :id="user.userid" size="lg" />
        </div>
        <div class="d-block d-md-none ms-1">
          <UserRatings v-if="user.userid > 0" :id="user.userid" size="md" />
        </div>
      </div>
      <div
        v-if="isBulk"
        :class="'ms-1 took ' + (availablenow <= 1 ? 'd-none' : '')"
      >
        <NumberIncrementDecrement
          v-model="user.count"
          label="Number taken"
          label-s-r-only
          append-text=" taken"
          :min="0"
          :max="left + user.count"
        />
      </div>
      <button
        v-else
        type="button"
        class="remove-taker ms-1"
        :aria-label="'Remove ' + (user.displayname || 'this person')"
        @click="removeTaker(user)"
      >
        <v-icon icon="times" />
      </button>
    </div>
    <div v-if="several">
      <p>
        If you split these between several people, you can add more people here:
      </p>
    </div>
    <div class="d-none d-md-block mt-1">
      <b-form-select
        v-if="moreUsersToSelect"
        v-model="selectUser"
        :options="userOptions(false)"
        size="lg"
        :class="'fw-bold ' + (chooseError ? 'text-danger' : '')"
        :state="chooseError || invalid ? false : null"
      />
      <p v-if="chooseError || invalid" class="invalid-feedback">
        Please select someone from the list above.
      </p>
    </div>
    <div class="d-block d-md-none">
      <b-form-select
        v-if="moreUsersToSelect"
        v-model="selectUser"
        :options="userOptions(true)"
        size="lg"
        :class="'fw-bold ' + (chooseError ? 'text-danger' : '')"
        :state="chooseError || invalid ? false : null"
      />
      <p v-if="chooseError || invalid" class="invalid-feedback">
        Please select someone from the list above.
      </p>
    </div>
    <p class="mt-2 text-muted small">
      This helps us identify reliable freeglers.
      <span v-if="several"
        >You can save and come back later if you like.</span
      >
    </p>
  </div>
</template>
<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import UserRatings from './UserRatings'
import NumberIncrementDecrement from './NumberIncrementDecrement'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'

const props = defineProps({
  type: {
    type: String,
    required: true,
  },
  availablenow: {
    type: Number,
    required: true,
  },
  msgid: {
    type: Number,
    required: true,
  },
  left: {
    type: Number,
    required: true,
  },
  takenBy: {
    type: Object,
    required: false,
    default: null,
  },
  chooseError: {
    type: Boolean,
    required: false,
    default: false,
  },
  invalid: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const emit = defineEmits(['tookUsers'])

const messageStore = useMessageStore()
const userStore = useUserStore()

if (props.msgid) {
  await messageStore.fetch(props.msgid)
}

const message = computed(() =>
  props.msgid ? messageStore.byId(props.msgid) : null
)

// Bulk clearance offers keep the per-person counts: those posters are working
// through a catalogue and the arithmetic is the point. On an ordinary post we
// only ask who took some, never how many each of them took.
const isBulk = computed(() => (message.value?.bulkcount ?? 0) > 0)

// Was this post offering more than one thing? For a bulk offer that is a live
// count, so availablenow is right. For an ordinary post the pool size is what
// matters and it does not change as items go: keying the copy off availablenow
// would switch a half-given-away post back to singular wording and hide the
// invitation to add more people, in the middle of splitting it.
const several = computed(() =>
  isBulk.value
    ? props.availablenow > 1
    : (message.value?.availableinitially || props.availablenow) > 1
)

const selectUser = ref(-1)

const initiallySelectedUsers = computed(() => {
  let ret = []

  if (props.msgid) {
    if (message?.value?.by) {
      ret = [message.value.by]
    }

    if (props.takenBy) {
      ret.push(props.takenBy)
    }
  }

  if (!isBulk.value) {
    // Drop any count that came with them - nothing downstream should be able to
    // read a per-person number on an ordinary post.
    ret = ret.map(({ count, ...user }) => user)
  }

  return ret
})

const currentlySelectedUsers = ref(initiallySelectedUsers.value)

const repliers = computed(() => {
  let ret = []

  if (message.value?.replies) {
    message.value.replies.forEach((u) => {
      if (u.userid >= 0) {
        ret.push({
          userid: u.userid,
          displayname: u.displayname,
        })
      }
    })
  }

  // Might be promised to someone who didn't reply - for example if they replied about something else and
  // then this was added in.
  if (message.value?.promises) {
    message.value.promises.forEach((u) => {
      if (u.userid > 0) {
        const user = userStore.byId(u.userid)

        ret.push({
          userid: u.userid,
          displayname: user?.displayname,
        })
      }
    })
  }

  // Make ret unique by userid
  ret = ret.filter((v, i, a) => a.findIndex((t) => t.userid === v.userid) === i)

  return ret
})

const availableUsers = computed(() => {
  // The users available to select are the ones which are not currently selected (unless that's the user for this
  // one.
  const ret = repliers.value?.filter(
    (u) => !currentlySelectedUsers.value.find((u2) => u2.userid === u.userid)
  )

  return ret
})

const moreUsersToSelect = computed(() => {
  // We show the choose if there are some left and we have not got all users plus someone else.
  return (
    currentlySelectedUsers.value?.length <= repliers.value?.length ||
    !currentlySelectedUsers.value.find((u) => !u.userid)
  )
})

function removeTaker(user) {
  // Reassign rather than splice so the watcher below sees the change and
  // re-emits: without a count there is no other way to undo a mis-pick.
  currentlySelectedUsers.value = currentlySelectedUsers.value.filter(
    (u) => u !== user
  )
}

function userOptionsChoose(small) {
  return small
    ? '<em>-- Please choose --</em>'
    : "<em>-- Please choose (this isn't public) --</em>"
}

function userOptions(small) {
  const options = []

  options.push({
    value: -1,
    html:
      currentlySelectedUsers.value.length >= 1
        ? '<em>-- Add another person --</em>'
        : userOptionsChoose(small),
  })

  for (const user of availableUsers.value) {
    options.push({
      value: user.userid,
      text: user.displayname,
    })
  }

  if (!currentlySelectedUsers.value.find((u) => u.userid === null)) {
    options.push({
      value: 0,
      html:
        several.value ? '<em>Other people</em>' : '<em>Someone else</em>',
    })
  }

  return options
}

// Watchers
watch(
  repliers,
  (newVal) => {
    newVal.forEach((u) => {
      if (!u.displayname) {
        userStore.fetch(u.userid)
      }
    })
  },
  { immediate: true }
)

watch(
  currentlySelectedUsers,
  (newVal) => {
    emit('tookUsers', newVal)
  },
  { immediate: true }
)

watch(selectUser, (userid) => {
  let user = null

  if (userid === 0) {
    user = { userid: null }
  } else if (userid > 0) {
    user = availableUsers.value.find((u) => u.userid === userid)
  }

  if (user) {
    if (isBulk.value) {
      // Default to assuming they took all the remaining ones. This particularly helps when there were
      // multiple items which all went to a single person.
      user.count = props.left

      if (user.count === 0) {
        // None left. But they wouldn't have added them unless they wanted to give them at least one. So
        // steal one from the last person who had a count > 1.
        const last = currentlySelectedUsers.value
          .slice()
          .reverse()
          .findIndex((u) => u.count > 1)

        if (last >= 0) {
          currentlySelectedUsers.value[last].count--
          user.count++
        }
      }
    }

    // Reassign so the watcher above re-emits; pushing in place does not.
    currentlySelectedUsers.value = [...currentlySelectedUsers.value, user]
  }

  nextTick(() => {
    selectUser.value = -1
  })
})
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

option {
  color: $color-black !important;
}

.btn[aria-pressed='true'] {
  box-shadow: 0px 0px 5px 2px var(--color-link) !important;
}

select {
  width: auto;
}

.layout {
  display: grid;
  border: 1px solid $color-gray--faded;
  border-radius: var(--radius-sm, 0.375rem);
  padding: 10px;

  grid-template-rows: auto auto auto;
  grid-template-columns: 2fr 2fr;
  grid-column-gap: 5px;

  @include media-breakpoint-up(md) {
    padding: 10px;

    grid-template-rows: auto;
    grid-template-columns: 1fr 165px 160px;
  }

  .select {
    grid-column: 1 / 3;
    grid-row: 1 / 2;

    @include media-breakpoint-up(md) {
      grid-column: 1 / 2;
      grid-row: 1;
    }
  }

  .ratings {
    justify-self: start;
    margin-top: 1rem;

    grid-column: 1 / 2;
    grid-row: 2 / 3;

    @include media-breakpoint-up(md) {
      margin-top: 0;
      grid-column: 2 / 3;
      grid-row: 1;
    }
  }

  .took {
    justify-self: end;
    margin-top: 1rem;

    grid-column: 3 / 4;
    grid-row: 2 / 3;

    @include media-breakpoint-up(md) {
      margin-top: 0;
      grid-column: 4 / 5;
      grid-row: 1;
    }
  }

  .remove-taker {
    justify-self: end;
    align-self: center;
    margin-top: 1rem;
    padding: 0 0.5rem;
    background: none;
    border: none;
    color: $color-gray--dark;

    grid-column: 3 / 4;
    grid-row: 2 / 3;

    &:hover,
    &:focus {
      color: $color-red;
    }

    @include media-breakpoint-up(md) {
      margin-top: 0;
      grid-column: 4 / 5;
      grid-row: 1;
    }
  }
}
</style>
