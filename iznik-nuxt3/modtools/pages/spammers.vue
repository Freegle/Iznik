<template>
  <div>
    <ModHelpSpammers />
    <div>
      <div>
        <b-tabs v-model="tabIndex" content-class="mt-3" card lazy>
          <b-tab id="Spammers" :active="!hasPermissionSpamAdmin">
            <template #title>
              <h2 class="ms-2 me-2">Confirmed Spammers</h2>
            </template>
          </b-tab>
          <b-tab v-if="hasPermissionSpamAdmin" id="PendingAdd">
            <template #title>
              <h2 class="ms-2 me-2">
                Pending Add
                <b-badge v-if="pendingaddcount" variant="danger">
                  {{ pendingaddcount }}
                </b-badge>
              </h2>
            </template>
          </b-tab>
          <b-tab v-if="hasPermissionSpamAdmin" id="Safelisted">
            <template #title>
              <h2 class="ms-2 me-2">Safelisted</h2>
            </template>
          </b-tab>
          <b-tab v-if="hasPermissionSpamAdmin" id="PendingRemove">
            <template #title>
              <h2 class="ms-2 me-2">
                Pending Remove
                <b-badge v-if="pendingremovecount" variant="danger">
                  {{ pendingremovecount }}
                </b-badge>
              </h2>
            </template>
          </b-tab>
        </b-tabs>
        <p v-if="tabIndex === 0" spam class="p-2">
          To remove from spammer list, please mail geeks.
        </p>
        <p v-if="tabIndex === 2" spam class="p-2">
          To remove from safe list, please mail geeks.
        </p>
        <ModMemberSearchbox
          v-if="tabIndex === 0"
          spam
          class="mb-2"
          @search="searched"
        />
        <ModBulkSelectBar
          v-if="visibleSpammers.length"
          :count="bulk.count.value"
          :limit="bulk.limit"
          :shown-ids="selectableIds"
          :copy-rows="copyRows"
          :busy="bulkBusy"
          @select-all="bulk.selectAll"
          @clear="bulk.clear"
        >
          <b-button
            v-if="canBulkConfirm"
            variant="primary"
            size="sm"
            :disabled="bulkBusy || !bulk.count.value"
            data-test="bulk-confirm"
            @click="confirmSelected"
          >
            <v-icon v-if="bulkBusy" icon="sync" class="fa-spin" />
            <v-icon v-else icon="check" />
            Confirm add to spammer list ({{ bulk.count.value }})
          </b-button>
        </ModBulkSelectBar>
        <NoticeMessage
          v-if="bulkResult"
          :variant="bulkResult.failed.length ? 'warning' : 'success'"
          class="mb-2"
          data-test="bulk-result"
        >
          <div v-if="bulkResult.done">
            Added {{ bulkResult.done }} to the spammer list.
          </div>
          <div v-if="bulkResult.failed.length">
            {{ bulkResult.failed.length }} could not be added:
            <ul class="mb-0">
              <li v-for="f in bulkResult.failed" :key="'bulkfail-' + f.id">
                #{{ f.userid }} {{ f.name }}: {{ f.message }}
              </li>
            </ul>
          </div>
        </NoticeMessage>
        <div
          v-for="(spammer, index) in visibleSpammers"
          :key="'spammer-' + tabIndex + '-' + spammer.id"
          class="d-flex align-items-start mb-1"
        >
          <b-form-checkbox
            :model-value="bulk.isSelected(spammer.id)"
            :disabled="
              bulkBusy ||
              !isSelectable(spammer) ||
              (bulk.atLimit.value && !bulk.isSelected(spammer.id))
            "
            :title="
              isSelectable(spammer)
                ? 'Select'
                : 'Held by a moderator - release before confirming'
            "
            class="mt-2 me-1 bulk-check"
            :data-test="'bulk-check-' + spammer.id"
            @update:model-value="bulk.toggle(spammer.id)"
          />
          <ModMember
            :membershipid="spammer.user.id"
            :sameip="spammer.sameip"
            class="flex-grow-1 min-width-0"
            :index="index"
          />
        </div>
        <infinite-loading
          :distance="10"
          :identifier="infiniteId"
          @infinite="loadMore"
        >
          <template #spinner>
            <Spinner :size="50" />
          </template>
        </infinite-loading>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { useSpammerStore } from '~/stores/spammer'
import { useModMe } from '~/composables/useModMe'
import { useBulkSelect } from '~/modtools/composables/useBulkSelect'
import { getPreferredEmail } from '~/modtools/composables/usePreferredEmail'

const route = useRoute()
const authStore = useAuthStore()
const spammerStore = useSpammerStore()
const { hasPermissionSpamAdmin } = useModMe()

// Reactive state (was data())
const infiniteId = ref(0)
const tabIndex = ref(0)
const show = ref(0)
const busy = ref(false)
const search = ref(null)

// Computed properties
const pendingaddcount = computed(() => {
  return authStore.work ? authStore.work.spammerpendingadd : 0
})

const pendingremovecount = computed(() => {
  return authStore.work ? authStore.work.spammerpendingremove : 0
})

const collection = computed(() => {
  let ret = null

  switch (tabIndex.value) {
    case 0: {
      ret = 'Spammer'
      break
    }
    case 1: {
      ret = 'PendingAdd'
      break
    }
    case 2: {
      ret = 'Whitelisted'
      break
    }
    case 3: {
      ret = 'PendingRemove'
      break
    }
  }

  console.log('Spammer collection', tabIndex.value, ret)
  return ret
})

const spammers = computed(() => {
  return spammerStore.getList(collection.value)
})

const visibleSpammers = computed(() => {
  return spammers.value ? spammers.value.slice(0, show.value) : []
})

// Bulk selection, keyed by the spam_users row id. Capped at fifty: a spammer who
// has made many accounts can be dealt with in one go, as the spam team asked
// (Discourse 3802), without a stray "select all" acting on the whole list.
const bulk = useBulkSelect()
const bulkBusy = ref(false)
const bulkResult = ref(null)

// Confirming is a Spam admin decision on a Pending Add report. A held row is
// somebody else's claim, so it is not selectable there, matching the single
// Confirm button which hides on a held row.
const canBulkConfirm = computed(
  () => collection.value === 'PendingAdd' && hasPermissionSpamAdmin.value
)

function isSelectable(spammer) {
  return !(collection.value === 'PendingAdd' && spammer.heldby)
}

const selectableIds = computed(() =>
  visibleSpammers.value.filter(isSelectable).map((s) => s.id)
)

// What "Copy" puts on the clipboard: the selected rows, or everything shown when
// nothing is selected. Plain fields only, for the spam team's records.
const copyRows = computed(() => {
  const rows = bulk.count.value
    ? visibleSpammers.value.filter((s) => bulk.isSelected(s.id))
    : visibleSpammers.value

  return rows.map((s) => ({
    id: s.userid,
    name: s.user?.displayname || '',
    email: getPreferredEmail(s.user) || '',
    reason: s.reason || '',
    added: s.added ? String(s.added).slice(0, 10) : '',
    by: s.byuser?.displayname || '',
  }))
})

async function confirmSelected() {
  const items = visibleSpammers.value
    .filter((s) => bulk.isSelected(s.id) && isSelectable(s))
    .map((s) => ({
      id: s.id,
      userid: s.userid,
      name: s.user?.displayname || '',
    }))

  if (!items.length) return

  bulkBusy.value = true
  bulkResult.value = null

  try {
    const result = await spammerStore.confirmMany(items)

    bulkResult.value = {
      done: result.done.length,
      failed: result.failed.map((f) => ({
        id: f.item.id,
        userid: f.item.userid,
        name: f.item.name,
        message: f.error?.message || 'Something went wrong',
      })),
    }

    if (result.heldConflict) {
      // Somebody took a hold after we fetched. Fetch the list again, with the
      // search we are showing, so the holder's name appears on the row.
      spammerStore.clear()
      infiniteId.value++
    }
  } catch (e) {
    console.error('Bulk confirm failed', e)
    bulkResult.value = {
      done: 0,
      failed: items.map((item) => ({
        ...item,
        message: e?.message || 'Something went wrong',
      })),
    }
  } finally {
    bulkBusy.value = false
    bulk.clear()
  }
}

// Watchers
watch(tabIndex, () => {
  spammerStore.clear()
  bulk.clear()
  bulkResult.value = null
  infiniteId.value++
})

// Rows leave the list when they are confirmed, rejected or held elsewhere.
watch(spammers, (list) => {
  bulk.keepOnly(list.map((s) => s.id))
})

watch(
  () => route.path,
  () => {
    // Clear store when we move away to prevent items showing again when we come back on potentially a different tab.
    spammerStore.clear()
  }
)

// Methods
function searched(term) {
  spammerStore.clear()
  bulk.clear()
  search.value = term
  infiniteId.value++
}

async function loadMore($state) {
  busy.value = true

  if (show.value < spammers.value.length) {
    // This means that we will gradually add the members that we have fetched from the server into the DOM.
    // Doing that means that we will complete our initial render more rapidly and thus appear faster.
    show.value++
    $state.loaded()
    busy.value = false
  } else {
    const currentCount = spammers.value.length
    await spammerStore.fetch({
      collection: collection.value,
      search: search.value,
      modtools: true,
    })

    if (currentCount === spammers.value.length) {
      busy.value = false
      $state.complete()
    } else {
      $state.loaded()
      busy.value = false
      show.value++
    }
  }
}

// Lifecycle - mounted
onMounted(() => {
  // Start in Pending Add if they have rights to see it.
  spammerStore.clear()

  if (hasPermissionSpamAdmin.value) {
    if (search.value) {
      tabIndex.value = 0
    } else {
      tabIndex.value = 1
    }
  } else {
    tabIndex.value = 0
  }
})
</script>
<style scoped>
.min-width-0 {
  min-width: 0;
}
</style>
