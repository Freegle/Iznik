<template>
  <div>
    <ModBulkSelectBar
      v-if="visibleMembers.length"
      :count="bulk.count.value"
      :limit="bulk.limit"
      :shown-ids="shownUserIds"
      :copy="false"
      :busy="bulkBusy"
      @select-all="bulk.selectAll"
      @clear="bulk.clear"
    >
      <b-button
        variant="danger"
        size="sm"
        :disabled="bulkBusy || !bulk.count.value"
        data-test="bulk-spam"
        @click="showBulkSpam = true"
      >
        <v-icon v-if="bulkBusy" icon="sync" class="fa-spin" />
        <v-icon v-else icon="ban" />
        Report {{ bulk.count.value }} as spammers
      </b-button>
    </ModBulkSelectBar>
    <NoticeMessage
      v-if="bulkResult"
      :variant="bulkResult.failed.length ? 'warning' : 'success'"
      class="mb-2"
      data-test="bulk-result"
    >
      <div v-if="bulkResult.done">
        Reported {{ bulkResult.done }} to the Spam team for checking.
      </div>
      <div v-if="bulkResult.skipped">
        {{ bulkResult.skipped }} already reported or on the spammer list, so
        left as they were.
      </div>
      <div v-if="bulkResult.failed.length">
        {{ bulkResult.failed.length }} could not be reported:
        <ul class="mb-0">
          <li v-for="f in bulkResult.failed" :key="'bulkfail-' + f.userid">
            #{{ f.userid }} {{ f.name }}: {{ f.message }}
          </li>
        </ul>
      </div>
    </NoticeMessage>
    <div
      v-for="member in visibleMembers"
      :key="'memberlist-' + member.id"
      class="p-0 mt-2 d-flex align-items-start"
    >
      <b-form-checkbox
        :model-value="bulk.isSelected(member.userid)"
        :disabled="
          bulkBusy || (bulk.atLimit.value && !bulk.isSelected(member.userid))
        "
        title="Select"
        class="mt-2 me-1 bulk-check"
        :data-test="'bulk-check-' + member.userid"
        @update:model-value="bulk.toggle(member.userid)"
      />
      <ModMember
        :membershipid="member.id"
        :actions="false"
        :expand-comments="parseInt(filter) === 1"
        class="flex-grow-1 min-width-0"
      />
    </div>
    <ModBulkSpammerReport
      v-if="showBulkSpam"
      :count="bulk.count.value"
      @confirm="bulkReport"
      @hidden="showBulkSpam = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { setupModMembers } from '~/composables/useModMembers'
import { useBulkSelect } from '~/modtools/composables/useBulkSelect'
import { useSpammerStore } from '~/stores/spammer'
import { useMemberStore } from '~/stores/member'
import { useMe } from '~/composables/useMe'

const { filter, visibleMembers } = setupModMembers()
const spammerStore = useSpammerStore()
const memberStore = useMemberStore()
const { me } = useMe()

// Bulk selection keyed by userid, not membership: a search across all communities
// lists one row per membership, and picking a person once must pick them once.
//
// There is no Copy here. Moderators do not take member lists away
// (.claude/rules/conventions.md); the spammer list has Copy, for the spam team's
// records, once the people are on it.
const bulk = useBulkSelect()
const bulkBusy = ref(false)
const bulkResult = ref(null)
const showBulkSpam = ref(false)

const shownUserIds = computed(() => [
  ...new Set(visibleMembers.value.map((m) => m.userid)),
])

// The list is replaced under us when the community, filter, order or search
// changes, and this component stays mounted. A selection that outlived the list
// would report people no longer on screen.
watch(shownUserIds, (ids) => {
  bulk.keepOnly(ids)
})

function nameOf(userid) {
  const member = visibleMembers.value.find((m) => m.userid === userid)
  return member?.displayname || member?.fullname || ''
}

// Reporting goes to the Spam team as Pending Add for everyone, Spam admin
// included: a Spam admin confirms them in one go on the spammer list. A direct add
// would replace a safelisted, held or already reported row, and lose who first
// reported them; Pending Add leaves an existing row alone.
async function bulkReport(reason) {
  const userids = [...bulk.selected.value]
  if (!userids.length) return

  bulkBusy.value = true
  bulkResult.value = null

  try {
    const result = await spammerStore.reportMany({ userids, reason })

    // Show it on the cards straight away: the member rows carry the spammer
    // state the card reads, and nothing refetches them here.
    const reported = {
      collection: 'PendingAdd',
      reason,
      added: new Date().toISOString(),
      byuserid: me.value?.id,
      heldby: null,
    }
    Object.values(memberStore.list).forEach((row) => {
      if (result.done.includes(row.userid)) {
        row.spammer = { ...reported, id: result.values.get(row.userid) }
      }
    })

    bulkResult.value = {
      done: result.done.length,
      skipped: result.skipped.length,
      failed: result.failed.map((f) => ({
        userid: f.item,
        name: nameOf(f.item),
        message: f.error?.message || 'Something went wrong',
      })),
    }
  } catch (e) {
    console.error('Bulk report failed', e)
    bulkResult.value = {
      done: 0,
      skipped: 0,
      failed: userids.map((userid) => ({
        userid,
        name: nameOf(userid),
        message: e?.message || 'Something went wrong',
      })),
    }
  } finally {
    bulkBusy.value = false
    bulk.clear()
  }
}
</script>
<style scoped>
.min-width-0 {
  min-width: 0;
}
</style>
