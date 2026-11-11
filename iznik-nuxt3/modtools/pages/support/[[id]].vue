<template>
  <div>
    <div v-if="supportOrAdmin">
      <div>
        <b-tabs v-model="activeTab" content-class="mt-3" card>
          <!-- User Tab -->
          <b-tab>
            <template #title>
              <h2 class="ms-2 me-2">User</h2>
            </template>
            <ModSupportFindUser :id="id" />
          </b-tab>

          <!-- Volunteers Tab -->
          <b-tab>
            <template #title>
              <h2 class="ms-2 me-2">Volunteers</h2>
            </template>
            <ModSupportCheckVolunteers />
          </b-tab>

          <!-- Message Tab -->
          <b-tab>
            <template #title>
              <h2 class="ms-2 me-2">Message</h2>
            </template>
            <p>
              You can search for message by id, or by subject. This will only
              return the first few results, so the more specific, the better.
            </p>
            <ModFindMessage
              :message-term="messageTerm"
              @searched="searchedMessage"
              @changed="changedMessageTerm"
            />
            <div v-if="messages" class="mt-2">
              <ModMessage
                v-for="message in messages"
                :key="'message-' + message.id"
                :messageid="message.id"
                noactions
              />
            </div>
            <NoticeMessage v-if="error" class="mt-2" variant="warning">
              Couldn't fetch that message. Almost always this is because the
              message doesn't exist (or has been very deleted).
            </NoticeMessage>
          </b-tab>

          <!-- AI Support Helper Tab -->
          <b-tab @click="onAITab">
            <template #title>
              <h2 class="ms-2 me-2">AI Support Helper</h2>
            </template>
            <NoticeMessage variant="warning" class="mb-2">
              This is the AI Support Helper. It can help diagnose problems using
              screen size, browser, and a detailed trace. Please report bugs or
              usability issues to
              <ExternalLink href="mailto:geeks@ilovefreegle.org">
                geeks@ilovefreegle.org</ExternalLink
              >.
            </NoticeMessage>
            <ModSupportAIAssistant
              v-if="showAIAssistant"
              :key="'aiassistant-' + aiAssistantBump"
            />
          </b-tab>

          <!-- Lockdown Tab: in red - see plans/active/2026-09-27-lockdown-switch.md
               section 11.11. Support opens on it only while a lockdown is on. -->
          <b-tab>
            <template #title>
              <h2
                class="ms-2 me-2 text-danger fw-bold"
                data-testid="lockdown-tab-title"
              >
                Lockdown
              </h2>
            </template>
            <ModSupportLockdown />
          </b-tab>

          <!-- Cases Tab: appended after Lockdown so the existing tab indexes keep their numbers. -->
          <b-tab>
            <template #title>
              <h2 class="ms-2 me-2" data-testid="cases-tab-title">
                Cases
                <b-badge
                  v-if="work?.supportcases"
                  variant="danger"
                  :title="work.supportcases + ' waiting for Support'"
                  data-testid="cases-tab-count"
                >
                  {{ work.supportcases }}
                </b-badge>
              </h2>
            </template>
            <ModSupportCaseList />
          </b-tab>
        </b-tabs>
      </div>
    </div>
    <NoticeMessage v-else variant="warning">
      You don't have access to Support Tools.
    </NoticeMessage>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { useRoute } from '#imports'
import { useChatStore } from '~/stores/chat'
import { useMessageStore } from '~/stores/message'
import { useLockdownStore } from '~/stores/lockdown'
import { useMe } from '~/composables/useMe'
import { useAuthStore } from '~/stores/auth'

// Stores
const chatStore = useChatStore()
const messageStore = useMessageStore()
const lockdownStore = useLockdownStore()
const authStore = useAuthStore()

// Composables
const { supportOrAdmin } = useMe()

// Route
const route = useRoute()

// Template refs

// Local state (formerly data())
const error = ref(false)
const messageTerm = ref(null)
const id = ref('id' in route.params ? parseInt(route.params.id) : 0)
const showAIAssistant = ref(false)
const aiAssistantBump = ref(0)

// Tab name to index mapping
const topTabMap = {
  user: 0,
  volunteers: 1,
  message: 2,
  ai: 3,
  lockdown: 4,
  cases: 5,
}

// The red count on the Cases tab and on the Support menu entry: cases waiting for Support.
const work = computed(() => authStore.work)

// Support Tools opens on Lockdown while a lockdown is on, or when the link asks for it
// (?tab=lockdown). Otherwise it opens on Cases while cases are waiting for Support, so the red
// count on the menu leads to them, unless the link names a member. Otherwise on User.
function initialTab() {
  if (route.query.tab === 'lockdown' || lockdownStore.active) {
    return topTabMap.lockdown
  }
  if (!id.value && !route.query.tab && work.value?.supportcases > 0) {
    return topTabMap.cases
  }
  return topTabMap.user
}
const activeTab = ref(initialTab())

// On a direct load the work counts arrive after the page opens. Until a tab is chosen, by the
// visitor or by the link, follow them onto Cases.
let tabChosen = false
watch(activeTab, () => {
  tabChosen = true
})
watch(
  () => work.value?.supportcases,
  () => {
    if (
      !tabChosen &&
      activeTab.value === topTabMap.user &&
      initialTab() === topTabMap.cases
    ) {
      activeTab.value = topTabMap.cases
    }
  }
)

// Computed properties
const messages = computed(() => {
  return messageStore.all
})

// Initialize (formerly created hook)
chatStore.list = [] // chatStore.clear()
messageStore.clear()

// Lifecycle
onMounted(() => {
  // Handle tab query parameter after component is mounted.
  const tabParam = route.query.tab

  if (tabParam && topTabMap[tabParam] !== undefined) {
    nextTick(() => {
      activeTab.value = topTabMap[tabParam]

      if (tabParam === 'ai') {
        onAITab()
      }
    })
  }
})

// Methods
function changedMessageTerm(term) {
  messageTerm.value = term.trim()
}

async function searchedMessage() {
  const term = messageTerm.value
  await messageStore.clearContext()
  await messageStore.clear()

  if (term) {
    if (!isNaN(term)) {
      // This is a raw message id
      await searchById(term)
    } else if (term.substring(0, 1) === '#' && !isNaN(term.substring(1))) {
      // This is a #id
      await searchById(term.substring(1))
    } else {
      await searchBySubject(term)
    }
  }
}

async function searchById(msgId) {
  error.value = false

  try {
    const message = await messageStore.fetchMT(
      {
        id: msgId,
      },
      false
    )
    if (message) messageStore.list[msgId] = message
  } catch (e) {
    console.log("Couldn't fetch", e)
    error.value = true
  }
}

async function searchBySubject(subj) {
  error.value = false
  await messageStore.searchMT({ term: subj, groupid: undefined })
}

function onAITab() {
  // Initialize AI Support Helper tab.
  nextTick(() => {
    onAIAssistantTab()
  })
}

function onAIAssistantTab() {
  // Mount the assistant on first open and then LEAVE it mounted. Bumping the
  // :key here (as it used to) remounted the component on every tab click, which
  // wiped the whole conversation, the Claude session id and the device panel.
  showAIAssistant.value = true
}
</script>

<style scoped>
.max {
  max-width: 300px;
}
</style>
