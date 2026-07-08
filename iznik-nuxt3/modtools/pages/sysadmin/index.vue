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

        <!-- Outgoing Email Tab -->
        <b-tab @click="onEmailStatsTab">
          <template #title>
            <h2 class="ms-2 me-2">
              Outgoing Email
              <b-badge v-if="supportOrAdmin && work?.emailout" variant="danger">
                !
              </b-badge>
            </h2>
          </template>
          <ModSupportEmailStats
            v-if="showEmailStats"
            :key="'emailstats-' + emailStatsBump"
          />
        </b-tab>

        <!-- Scrolling Tab: how far people scroll/engage — digest click-through by
             position, and browse-feed scroll depth. -->
        <b-tab @click="onDigestClicksTab">
          <template #title>
            <h2 class="ms-2 me-2">Scrolling</h2>
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

        <!-- Incoming Email Tab -->
        <b-tab @click="onIncomingEmailTab">
          <template #title>
            <h2 class="ms-2 me-2">
              Incoming Email
              <b-badge v-if="supportOrAdmin && work?.emailin" variant="danger">
                !
              </b-badge>
            </h2>
          </template>
          <ModSupportIncomingEmail
            v-if="showIncomingEmail"
            :key="'incomingemail-' + incomingEmailBump"
          />
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

const topTabMap = {
  housekeeping: 0,
  cronjobs: 1,
  outgoing: 2,
  digest: 3,
  incoming: 4,
  rippling: 5,
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

function onDigestClicksTab() {
  showDigestClicks.value = true
  digestClicksBump.value = Date.now()
}

function onIncomingEmailTab() {
  showIncomingEmail.value = true
  incomingEmailBump.value = Date.now()
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
    else if (tab === 'outgoing') onEmailStatsTab()
    else if (tab === 'digest') onDigestClicksTab()
    else if (tab === 'incoming') onIncomingEmailTab()
    else if (tab === 'rippling') onRipplingTab()
  } else {
    // Default to showing housekeeping
    onHousekeepingTab()
  }
})
</script>
