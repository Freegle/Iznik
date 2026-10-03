<template>
  <div v-if="me">
    <div class="d-flex justify-content-around w-100">
      <b-img
        v-if="showVolunteersWeek"
        ref="volunteersWeek"
        fluid
        src="/VolunteersWeek.gif"
      />
    </div>
    <h2>Hello, {{ me.displayname }}</h2>
    <p>
      Here's what the system has already done. Look in if you want to add
      something on top &mdash; nothing here is waiting for you.
    </p>
    <div v-if="appVersions">
      <strong>Current app releases:</strong>
      <ul>
        <li>
          Freegle iOS <strong>v{{ appVersions.fd.ios.version }}</strong> ({{
            appVersions.fd.ios.date
          }})
        </li>
        <li>
          Freegle Android
          <strong>v{{ appVersions.fd.android.version }}</strong> ({{
            appVersions.fd.android.date
          }})
        </li>
        <li>
          ModTools iOS <strong>v{{ appVersions.mt.ios.version }}</strong> ({{
            appVersions.mt.ios.date
          }})
        </li>
        <li>
          ModTools Android
          <strong>v{{ appVersions.mt.android.version }}</strong> ({{
            appVersions.mt.android.date
          }})
        </li>
      </ul>
    </div>
    <ModYesterday
      v-if="me.systemrole === 'Admin' || me.systemrole === 'Support'"
    />
    <!-- eslint-disable-next-line -->
    <p>Need any help moderating? Mail <ExternalLink href="mailto:mentors@ilovefreegle.org">mentors@ilovefreegle.org</ExternalLink>
    </p>
    <div class="d-flex align-items-end gap-2 flex-wrap mb-3 mt-2">
      <div class="d-flex flex-column">
        <label for="dashboardSince">Show what happened in the:</label>
        <b-form-select id="dashboardSince" v-model.number="sinceHours">
          <option :value="1">Last hour</option>
          <option :value="4">Last 4 hours</option>
          <option :value="24">Last day</option>
          <option :value="168">Last week</option>
        </b-form-select>
      </div>
      <b-button variant="white" :disabled="loading" @click="refreshAll">
        <v-icon icon="sync" /> Refresh
      </b-button>
    </div>

    <!-- 1. Just published -->
    <section class="mb-4">
      <h2>Just published</h2>
      <p>The system checked these posts and published them.</p>
      <Spinner v-if="loadingPublished" :size="40" />
      <notice-message v-else-if="!publishedMessages.length">
        Nothing published in this period.
      </notice-message>
      <div
        v-for="message in publishedMessages"
        v-else
        :key="'published-' + message.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <NuxtLink :to="'/modtools/message/' + message.id">
            {{ message.subject }}
          </NuxtLink>
          <SpinButton
            variant="white"
            icon-name="trash-alt"
            label="Take down"
            confirm
            :handle-param="message.id"
            @handle="takeDownHandler"
          />
        </div>
      </div>
    </section>

    <!-- 2. Taken down -->
    <section class="mb-4">
      <h2>Taken down</h2>
      <p>
        The system took these down after reports or a content check, and told
        the poster.
      </p>
      <Spinner v-if="loadingTakendown" :size="40" />
      <notice-message v-else-if="!takendownMessages.length">
        Nothing taken down in this period.
      </notice-message>
      <div
        v-for="message in takendownMessages"
        v-else
        :key="'takendown-' + message.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <NuxtLink :to="'/modtools/message/' + message.id">
            {{ message.subject }}
          </NuxtLink>
          <SpinButton
            variant="white"
            icon-name="undo"
            label="Restore"
            :handle-param="message.id"
            @handle="restoreHandler"
          />
        </div>
      </div>
    </section>

    <!-- 3. Held chat messages -->
    <section class="mb-4">
      <h2>Held chat messages</h2>
      <p>The system delivered these behind a warning.</p>
      <Spinner v-if="loadingHeldChats" :size="40" />
      <notice-message v-else-if="!heldChatMessages.length">
        No held chat messages at the moment.
      </notice-message>
      <div
        v-for="message in heldChatMessages"
        v-else
        :key="'heldchat-' + message.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <span>
            {{ message.fromuser?.displayname || 'A member' }}: {{ message.message }}
          </span>
          <SpinButton
            variant="white"
            icon-name="ban"
            label="Reject"
            confirm
            :handle-param="message.id"
            @handle="rejectChatHandler"
          />
        </div>
      </div>
      <p v-if="heldChatMessages.length">
        <NuxtLink to="/modtools/chats/review">See all held chat messages</NuxtLink>
      </p>
    </section>

    <!-- 4. Messages to Freegle -->
    <section class="mb-4">
      <h2>Messages to Freegle</h2>
      <p>The system sent the automatic reply.</p>
      <Spinner v-if="loadingMessagesToFreegle" :size="40" />
      <notice-message v-else-if="!messagesToFreegle.length">
        No new messages to Freegle in this period.
      </notice-message>
      <div
        v-for="chat in messagesToFreegle"
        v-else
        :key="'m2f-' + chat.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <span>{{ chat.name }}</span>
          <NuxtLink :to="'/modtools/chats/' + chat.id">Add a reply</NuxtLink>
        </div>
      </div>
    </section>

    <!-- 5. New members -->
    <section class="mb-4">
      <h2>New members</h2>
      <p>The system welcomed these members.</p>
      <Spinner v-if="loadingNewMembers" :size="40" />
      <notice-message v-else-if="!newMembers.length">
        No new members in this period.
      </notice-message>
      <div
        v-for="member in newMembers"
        v-else
        :key="'newmember-' + member.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <span>{{ member.displayname }}</span>
          <SpinButton
            variant="white"
            icon-name="comment"
            label="Add a personal welcome"
            :handle-param="member.id"
            @handle="welcomeMemberHandler"
          />
        </div>
      </div>
    </section>

    <!-- 6. Completed freegles -->
    <section class="mb-4">
      <h2>Completed freegles</h2>
      <p>The system recorded the outcome.</p>
      <Spinner v-if="loadingOutcomes" :size="40" />
      <notice-message v-else-if="!outcomes.length">
        No completed freegles in this period.
      </notice-message>
      <div
        v-for="outcome in outcomes"
        v-else
        :key="'outcome-' + outcome.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <span>
            {{ outcome.subject }} &mdash; {{ outcome.outcome }}
            <span v-if="outcome.otheruser">
              by {{ outcome.otheruser.displayname }}
            </span>
          </span>
          <SpinButton
            v-if="outcome.otheruser"
            variant="white"
            icon-name="heart"
            label="Send a thank you"
            :handle-param="outcome.otheruser.id"
            @handle="thankMemberHandler"
          />
        </div>
      </div>
    </section>

    <!-- 7. Flagged members -->
    <section class="mb-4">
      <h2>Flagged members</h2>
      <p>The system noted the signal and blocked nothing.</p>
      <Spinner v-if="loadingFlaggedMembers" :size="40" />
      <notice-message v-else-if="!flaggedMembers.length">
        No flagged members in this period.
      </notice-message>
      <div
        v-for="member in flaggedMembers"
        v-else
        :key="'flagged-' + member.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <span>{{ member.displayname }} &mdash; {{ member.flagreason }}</span>
          <SpinButton
            variant="white"
            icon-name="gavel"
            label="Ban"
            confirm
            :handle-param="member.id"
            @handle="banMemberHandler"
          />
        </div>
      </div>
    </section>

    <!-- 8. Events and volunteering -->
    <section class="mb-4">
      <h2>Events and volunteering</h2>
      <p>The system published these.</p>
      <Spinner v-if="loadingEvents" :size="40" />
      <notice-message v-else-if="!eventsAndVolunteering.length">
        Nothing new in this period.
      </notice-message>
      <div
        v-for="item in eventsAndVolunteering"
        v-else
        :key="'ev-' + item.kind + '-' + item.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <span>{{ item.title }} <span class="text-muted">({{ item.kind }})</span></span>
          <SpinButton
            variant="white"
            icon-name="trash-alt"
            label="Remove"
            confirm
            :handle-param="item"
            @handle="removeEventOrVolunteeringHandler"
          />
        </div>
      </div>
    </section>

    <!-- 9. Spammers -->
    <section class="mb-4">
      <h2>Spammers</h2>
      <p>The system blocked known spammers. These are pending reports.</p>
      <Spinner v-if="loadingSpammers" :size="40" />
      <notice-message v-else-if="!spammers.length">
        No pending spammer reports.
      </notice-message>
      <div
        v-for="spammer in spammers"
        v-else
        :key="'spammer-' + spammer.id"
        class="border-bottom py-2"
      >
        <div class="d-flex justify-content-between align-items-center gap-2">
          <span>
            {{ spammer.user?.displayname }} ({{ spammer.user?.email }})
            &mdash; {{ spammer.reason }}
          </span>
          <div class="d-flex gap-1">
            <SpinButton
              variant="white"
              icon-name="check"
              label="Confirm"
              confirm
              :handle-param="spammer"
              @handle="confirmSpammerHandler"
            />
            <SpinButton
              variant="white"
              icon-name="times"
              label="Clear"
              :handle-param="spammer"
              @handle="clearSpammerHandler"
            />
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import dayjs from 'dayjs'
import { ref, computed, onMounted, watch } from 'vue'
import { useMiscStore } from '@/stores/misc'
import { useConfigStore } from '@/stores/config'
import { useMe } from '~/composables/useMe'
import { useMessageStore } from '~/stores/message'
import { useChatStore } from '~/stores/chat'
import { useCommunityEventStore } from '~/stores/communityevent'
import { useVolunteeringStore } from '~/stores/volunteering'
import { useSpammerStore } from '~/modtools/stores/spammer'
import api from '~/api'

// Self-moderating rework: this page used to be a per-community dashboard
// (groupid picker, date range, ActivityGraph, ModDashboard* stats
// components). Moderators are now a national pool, and ModTools no longer
// gates anything on a person: every section below is something the system
// has already finished (past tense), with at most one optional action a
// volunteer can add on top. See .claude-agent-status/briefs/modtools-rework.md
// for the section-by-section contract this page implements.

const miscStore = useMiscStore()
const configStore = useConfigStore()
const messageStore = useMessageStore()
const chatStore = useChatStore()
const communityEventStore = useCommunityEventStore()
const volunteeringStore = useVolunteeringStore()
const spammerStore = useSpammerStore()

const { me } = useMe()

const showVolunteersWeek = ref(false)
const appVersions = ref(null)

const sinceHours = computed({
  get: () => miscStore.get('dashboardSinceHours') || 24,
  set: (newValue) =>
    miscStore.set({ key: 'dashboardSinceHours', value: newValue }),
})

const loading = computed(
  () =>
    loadingPublished.value ||
    loadingTakendown.value ||
    loadingHeldChats.value ||
    loadingMessagesToFreegle.value ||
    loadingNewMembers.value ||
    loadingFlaggedMembers.value ||
    loadingOutcomes.value ||
    loadingEvents.value ||
    loadingSpammers.value
)

// --- 1/2. Just published / Taken down --------------------------------------

const loadingPublished = ref(false)
const loadingTakendown = ref(false)
const publishedIds = ref([])
const takendownIds = ref([])

const publishedMessages = computed(() =>
  publishedIds.value.map((id) => messageStore.list[id]).filter(Boolean)
)
const takendownMessages = computed(() =>
  takendownIds.value.map((id) => messageStore.list[id]).filter(Boolean)
)

async function loadPublished() {
  loadingPublished.value = true
  try {
    // fetchMessagesMT returns undefined when the server sent no messages.
    publishedIds.value =
      (await messageStore.fetchMessagesMT({
        filter: 'published',
        since: sinceHours.value,
      })) || []
  } catch (e) {
    console.log('Failed to fetch published messages', e)
  } finally {
    loadingPublished.value = false
  }
}

async function loadTakendown() {
  loadingTakendown.value = true
  try {
    takendownIds.value =
      (await messageStore.fetchMessagesMT({
        filter: 'takendown',
        since: sinceHours.value,
      })) || []
  } catch (e) {
    console.log('Failed to fetch taken down messages', e)
  } finally {
    loadingTakendown.value = false
  }
}

function takeDownHandler(callback, id) {
  messageStore
    .takeDown(id, 'Taken down by a moderator')
    .then(() => {
      publishedIds.value = publishedIds.value.filter((i) => i !== id)
      return loadTakendown()
    })
    .finally(() => callback())
}

function restoreHandler(callback, id) {
  messageStore
    .restore(id)
    .then(() => {
      takendownIds.value = takendownIds.value.filter((i) => i !== id)
      return loadPublished()
    })
    .finally(() => callback())
}

// --- 3. Held chat messages ---------------------------------------------------

const loadingHeldChats = ref(false)

const heldChatMessages = computed(() => {
  return (chatStore.messagesById(null) || []).filter(Boolean)
})

async function loadHeldChats() {
  loadingHeldChats.value = true
  try {
    await chatStore.fetchReviewChatsMT(null, {})
  } catch (e) {
    console.log('Failed to fetch held chat messages', e)
  } finally {
    loadingHeldChats.value = false
  }
}

function rejectChatHandler(callback, id) {
  chatStore
    .rejectChat(id)
    .then(loadHeldChats)
    .finally(() => callback())
}

// --- 4. Messages to Freegle --------------------------------------------------

const loadingMessagesToFreegle = ref(false)

const messagesToFreegle = computed(() =>
  Object.values(chatStore.listByChatId).filter(
    (c) => c.chattype === 'User2Mod'
  )
)

async function loadMessagesToFreegle() {
  loadingMessagesToFreegle.value = true
  try {
    await chatStore.listChatsMT({ chattypes: ['User2Mod'], summary: true })
  } catch (e) {
    console.log('Failed to fetch messages to Freegle', e)
  } finally {
    loadingMessagesToFreegle.value = false
  }
}

// --- 5/7. New members / Flagged members -------------------------------------

const loadingNewMembers = ref(false)
const loadingFlaggedMembers = ref(false)
const newMembers = ref([])
const flaggedMembers = ref([])

async function loadNewMembers() {
  loadingNewMembers.value = true
  try {
    const { members } = await api().modmembers.fetch({
      filter: 'new',
      since: sinceHours.value,
    })
    newMembers.value = members || []
  } catch (e) {
    console.log('Failed to fetch new members', e)
  } finally {
    loadingNewMembers.value = false
  }
}

async function loadFlaggedMembers() {
  loadingFlaggedMembers.value = true
  try {
    const { members } = await api().modmembers.fetch({
      filter: 'flagged',
      since: sinceHours.value,
    })
    flaggedMembers.value = members || []
  } catch (e) {
    console.log('Failed to fetch flagged members', e)
  } finally {
    loadingFlaggedMembers.value = false
  }
}

function welcomeMemberHandler(callback, userid) {
  chatStore
    .openChatToUser({ userid, chattype: 'User2Mod' })
    .finally(() => callback())
}

function banMemberHandler(callback, id) {
  api()
    .modmembers.ban(id, 'Banned by a moderator')
    .then(() => {
      flaggedMembers.value = flaggedMembers.value.filter((m) => m.id !== id)
    })
    .finally(() => callback())
}

// --- 6. Completed freegles ---------------------------------------------------

const loadingOutcomes = ref(false)
const outcomes = ref([])

async function loadOutcomes() {
  loadingOutcomes.value = true
  try {
    const { outcomes: fetched } = await api().modmembers.fetchOutcomes({
      since: sinceHours.value,
    })
    outcomes.value = fetched || []
  } catch (e) {
    console.log('Failed to fetch completed freegles', e)
  } finally {
    loadingOutcomes.value = false
  }
}

function thankMemberHandler(callback, userid) {
  chatStore
    .openChatToUser({ userid, chattype: 'User2Mod' })
    .finally(() => callback())
}

// --- 8. Events and volunteering ----------------------------------------------

const loadingEvents = ref(false)
const eventIds = ref([])
const volunteeringIds = ref([])

const eventsAndVolunteering = computed(() => {
  const events = eventIds.value
    .map((id) => communityEventStore.byId(id))
    .filter(Boolean)
    .map((item) => ({ ...item, kind: 'event' }))
  const volunteering = volunteeringIds.value
    .map((id) => volunteeringStore.byId(id))
    .filter(Boolean)
    .map((item) => ({ ...item, kind: 'volunteering' }))
  return [...events, ...volunteering]
})

async function loadEventsAndVolunteering() {
  loadingEvents.value = true
  try {
    const [eventIdList, volunteeringIdList] = await Promise.all([
      api().communityevent.list({ filter: 'recent' }),
      api().volunteering.list({ filter: 'recent' }),
    ])
    eventIds.value = eventIdList || []
    volunteeringIds.value = volunteeringIdList || []
    await Promise.all([
      ...eventIds.value.map((id) => communityEventStore.fetch(id, true)),
      ...volunteeringIds.value.map((id) => volunteeringStore.fetch(id, true)),
    ])
  } catch (e) {
    console.log('Failed to fetch events and volunteering', e)
  } finally {
    loadingEvents.value = false
  }
}

function removeEventOrVolunteeringHandler(callback, item) {
  const promise =
    item.kind === 'event'
      ? communityEventStore.delete(item.id)
      : volunteeringStore.delete(item.id)

  promise
    .then(() => {
      if (item.kind === 'event') {
        eventIds.value = eventIds.value.filter((id) => id !== item.id)
      } else {
        volunteeringIds.value = volunteeringIds.value.filter(
          (id) => id !== item.id
        )
      }
    })
    .finally(() => callback())
}

// --- 9. Spammers --------------------------------------------------------------

const loadingSpammers = ref(false)

const spammers = computed(() => spammerStore.list)

async function loadSpammers() {
  loadingSpammers.value = true
  try {
    await spammerStore.fetch({ collection: 'PendingAdd' })
  } catch (e) {
    console.log('Failed to fetch spammers', e)
  } finally {
    loadingSpammers.value = false
  }
}

function confirmSpammerHandler(callback, spammer) {
  spammerStore
    .confirm({ id: spammer.id, userid: spammer.userid })
    .finally(() => callback())
}

function clearSpammerHandler(callback, spammer) {
  spammerStore
    .remove({ id: spammer.id, userid: spammer.userid })
    .finally(() => callback())
}

// --- Refresh everything -------------------------------------------------------

function refreshAll() {
  loadPublished()
  loadTakendown()
  loadHeldChats()
  loadMessagesToFreegle()
  loadNewMembers()
  loadFlaggedMembers()
  loadOutcomes()
  loadEventsAndVolunteering()
  loadSpammers()
}

watch(sinceHours, () => {
  // The chat, spammer and events/volunteering sections aren't scoped by
  // since/period on the server, so only reload the sections that are.
  loadPublished()
  loadTakendown()
  loadNewMembers()
  loadFlaggedMembers()
  loadOutcomes()
})

onMounted(async () => {
  // Volunteers' Week is between 1st and 7th June every year.
  if (
    dayjs().get('month') === 5 &&
    dayjs().get('date') >= 1 &&
    dayjs().get('date') <= 7
  ) {
    showVolunteersWeek.value = true

    setTimeout(() => {
      showVolunteersWeek.value = false
    }, 30000)
  }

  // Fetch app version info
  try {
    const fdIosVersion = await configStore.fetch('app_fd_version_ios_latest')
    const fdIosDate = await configStore.fetch('app_fd_version_ios_date')
    const fdAndroidVersion = await configStore.fetch(
      'app_fd_version_android_latest'
    )
    const fdAndroidDate = await configStore.fetch('app_fd_version_android_date')
    const mtIosVersion = await configStore.fetch('app_mt_version_ios_latest')
    const mtIosDate = await configStore.fetch('app_mt_version_ios_date')
    const mtAndroidVersion = await configStore.fetch(
      'app_mt_version_android_latest'
    )
    const mtAndroidDate = await configStore.fetch('app_mt_version_android_date')

    if (
      fdIosVersion?.length &&
      fdAndroidVersion?.length &&
      mtIosVersion?.length &&
      mtAndroidVersion?.length
    ) {
      appVersions.value = {
        fd: {
          ios: {
            version: fdIosVersion[0].value,
            date: fdIosDate?.length
              ? dayjs(fdIosDate[0].value).format('D MMM YYYY')
              : 'Unknown',
          },
          android: {
            version: fdAndroidVersion[0].value,
            date: fdAndroidDate?.length
              ? dayjs(fdAndroidDate[0].value).format('D MMM YYYY')
              : 'Unknown',
          },
        },
        mt: {
          ios: {
            version: mtIosVersion[0].value,
            date: mtIosDate?.length
              ? dayjs(mtIosDate[0].value).format('D MMM YYYY')
              : 'Unknown',
          },
          android: {
            version: mtAndroidVersion[0].value,
            date: mtAndroidDate?.length
              ? dayjs(mtAndroidDate[0].value).format('D MMM YYYY')
              : 'Unknown',
          },
        },
      }
    }
  } catch (e) {
    console.log('Failed to fetch app versions', e)
  }

  refreshAll()
})
</script>
