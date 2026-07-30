<template>
  <div>
    <div v-if="supportOrAdmin">
      <b-tabs v-model="activeTab" content-class="mt-3" card>
        <!-- Housekeeping Tab -->
        <b-tab @click="onHousekeepingTab">
          <template #title>
            <h2 class="ms-2 me-2">
              Housekeeping
              <b-badge
                v-if="supportOrAdmin && work?.housekeeping"
                variant="danger"
              >
                {{ work.housekeeping }}
              </b-badge>
            </h2>
          </template>
          <ModSysAdminHousekeeping
            v-if="showHousekeeping"
            :key="'housekeeping-' + housekeepingBump"
          />
        </b-tab>

        <!-- Cron Jobs Tab -->
        <b-tab @click="onCronJobsTab">
          <template #title>
            <h2 class="ms-2 me-2">
              Cron Jobs
              <b-badge v-if="supportOrAdmin && work?.cronjobs" variant="danger">
                {{ work.cronjobs }}
              </b-badge>
            </h2>
          </template>
          <ModSysAdminCronJobs
            v-if="showCronJobs"
            :key="'cronjobs-' + cronJobsBump"
          />
        </b-tab>

        <!-- Mail Tab: outgoing + incoming email, grouped as sub-tabs. -->
        <b-tab @click="onMailTab">
          <template #title>
            <h2 class="ms-2 me-2">
              Mail
              <b-badge
                v-if="supportOrAdmin && (work?.emailout || work?.emailin)"
                variant="danger"
              >
                !
              </b-badge>
            </h2>
          </template>
          <b-tabs v-model="mailSubTab" content-class="mt-3" pills>
            <b-tab @click="onEmailStatsTab">
              <template #title>
                <span class="subtab-label">
                  Outgoing
                  <b-badge
                    v-if="supportOrAdmin && work?.emailout"
                    variant="danger"
                  >
                    !
                  </b-badge>
                </span>
              </template>
              <ModSupportEmailStats
                v-if="showEmailStats"
                :key="'emailstats-' + emailStatsBump"
              />
            </b-tab>
            <b-tab @click="onIncomingEmailTab">
              <template #title>
                <span class="subtab-label">
                  Incoming
                  <b-badge
                    v-if="supportOrAdmin && work?.emailin"
                    variant="danger"
                  >
                    !
                  </b-badge>
                </span>
              </template>
              <ModSupportIncomingEmail
                v-if="showIncomingEmail"
                :key="'incomingemail-' + incomingEmailBump"
              />
            </b-tab>
          </b-tabs>
        </b-tab>

        <!-- Behaviour Tab: scrolling, recommendations and reengagement
             effectiveness, grouped as sub-tabs. -->
        <b-tab @click="onBehaviourTab">
          <template #title>
            <h2 class="ms-2 me-2">Behaviour</h2>
          </template>
          <b-tabs v-model="behaviourSubTab" content-class="mt-3" pills>
            <!-- Scrolling: how far people scroll/engage — digest click-through by
                 position, and browse-feed scroll depth. -->
            <b-tab @click="onDigestClicksTab">
              <template #title>
                <span class="subtab-label">Scrolling</span>
              </template>
              <template v-if="showDigestClicks">
                <h3 class="ms-2 mt-2">Digest click-through by position</h3>
                <ModSysAdminDigestClicks
                  :key="'digestclicks-' + digestClicksBump"
                />
                <hr class="my-4" />
                <h3 class="ms-2 mt-2">Browse-feed scroll depth</h3>
                <ModSysAdminBrowseScroll
                  :key="'browsescroll-' + digestClicksBump"
                />
              </template>
            </b-tab>
            <b-tab @click="onRecommendationsTab">
              <template #title>
                <span class="subtab-label">Recommendations</span>
              </template>
              <ModSysAdminRecommendations
                v-if="showRecommendations"
                :key="'recommendations-' + recommendationsBump"
              />
            </b-tab>
            <b-tab @click="onReengageTab">
              <template #title>
                <span class="subtab-label">Reengagement</span>
              </template>
              <ModSysAdminReengageEffectiveness
                v-if="showReengage"
                :key="'reengage-' + reengageBump"
              />
            </b-tab>
          </b-tabs>
        </b-tab>

        <!-- Rippling Tab -->
        <b-tab @click="onRipplingTab">
          <template #title>
            <h2 class="ms-2 me-2">Rippling</h2>
          </template>
          <ModSysAdminRipplingAnalytics
            v-if="showRippling"
            :key="'rippling-analytics-' + ripplingBump"
          />
        </b-tab>
      </b-tabs>
    </div>
    <NoticeMessage v-else variant="warning">
      You don't have access to SysAdmin tools.
    </NoticeMessage>
  </div>
</template>

<script setup>
import { useMe } from '~/composables/useMe'
import { useAuthStore } from '@/stores/auth'

const { supportOrAdmin } = useMe()
const authStore = useAuthStore()
const work = computed(() => authStore.work)

const route = useRoute()

const activeTab = ref(0)
const mailSubTab = ref(0)
const behaviourSubTab = ref(0)

const showHousekeeping = ref(false)
const housekeepingBump = ref(0)
const showCronJobs = ref(false)
const cronJobsBump = ref(0)
const showEmailStats = ref(false)
const emailStatsBump = ref(0)
const showDigestClicks = ref(false)
const digestClicksBump = ref(0)
const showIncomingEmail = ref(false)
const incomingEmailBump = ref(0)
const showRippling = ref(false)
const ripplingBump = ref(0)
const showRecommendations = ref(false)
const recommendationsBump = ref(0)
const showReengage = ref(false)
const reengageBump = ref(0)

// Top-level tab index per deep-link query param. Outgoing/incoming both open the
// Mail tab; scrolling/recommendations/reengagement all open the Behaviour tab.
// The old per-tab params are kept so existing bookmarks/links still land right.
const topTabMap = {
  housekeeping: 0,
  cronjobs: 1,
  mail: 2,
  outgoing: 2,
  incoming: 2,
  behaviour: 3,
  digest: 3,
  scrolling: 3,
  recommendations: 3,
  reengagement: 3,
  rippling: 4,
}

function onHousekeepingTab() {
  showHousekeeping.value = true
  housekeepingBump.value = Date.now()
}

function onCronJobsTab() {
  showCronJobs.value = true
  cronJobsBump.value = Date.now()
}

function onEmailStatsTab() {
  showEmailStats.value = true
  emailStatsBump.value = Date.now()
}

function onIncomingEmailTab() {
  showIncomingEmail.value = true
  incomingEmailBump.value = Date.now()
}

// Opening the Mail tab shows whichever email sub-tab is active (Outgoing by
// default), so the first render isn't blank before a sub-tab is clicked.
function onMailTab() {
  if (mailSubTab.value === 1) onIncomingEmailTab()
  else onEmailStatsTab()
}

function onDigestClicksTab() {
  showDigestClicks.value = true
  digestClicksBump.value = Date.now()
}

function onRecommendationsTab() {
  showRecommendations.value = true
  recommendationsBump.value = Date.now()
}

function onReengageTab() {
  showReengage.value = true
  reengageBump.value = Date.now()
}

// Opening the Behaviour tab shows whichever sub-tab is active (Scrolling by
// default).
function onBehaviourTab() {
  if (behaviourSubTab.value === 2) onReengageTab()
  else if (behaviourSubTab.value === 1) onRecommendationsTab()
  else onDigestClicksTab()
}

function onRipplingTab() {
  showRippling.value = true
  ripplingBump.value = Date.now()
}

onMounted(() => {
  const tab = route.query.tab
  if (tab && topTabMap[tab] !== undefined) {
    activeTab.value = topTabMap[tab]

    if (tab === 'housekeeping') onHousekeepingTab()
    else if (tab === 'cronjobs') onCronJobsTab()
    else if (tab === 'mail' || tab === 'outgoing') {
      mailSubTab.value = 0
      onEmailStatsTab()
    } else if (tab === 'incoming') {
      mailSubTab.value = 1
      onIncomingEmailTab()
    } else if (tab === 'behaviour' || tab === 'digest' || tab === 'scrolling') {
      behaviourSubTab.value = 0
      onDigestClicksTab()
    } else if (tab === 'recommendations') {
      behaviourSubTab.value = 1
      onRecommendationsTab()
    } else if (tab === 'reengagement') {
      behaviourSubTab.value = 2
      onReengageTab()
    } else if (tab === 'rippling') onRipplingTab()
  } else {
    // Default to showing housekeeping
    onHousekeepingTab()
  }
})
</script>

<style scoped lang="scss">
.subtab-label {
  font-size: 1rem;
  font-weight: 600;
}

/* Sub-tab pill nav (Mail / Behaviour). The top-level tabs use the `card`
   (.nav-tabs) variant, so scoping to .nav-pills targets only these sub-tabs. */
:deep(.nav-pills) {
  gap: 0.375rem;
  border-bottom: 1px solid #e3e8ee;
  padding-bottom: 0.6rem;
  margin-bottom: 0.5rem;
}

:deep(.nav-pills .nav-link) {
  color: #5a6672;
  padding: 0.4rem 1.1rem;
  border-radius: 2rem;
  border: 1px solid transparent;
  transition: color 0.12s ease, background-color 0.12s ease,
    box-shadow 0.12s ease;
}

:deep(.nav-pills .nav-link:hover:not(.active)) {
  color: #008000;
  background-color: #eef6e6;
}

:deep(.nav-pills .nav-link.active) {
  color: #fff;
  background-color: #61ae24;
  box-shadow: 0 2px 4px rgba(97, 174, 36, 0.35);
}
</style>
