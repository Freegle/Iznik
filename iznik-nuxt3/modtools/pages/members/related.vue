<template>
  <div>
    <client-only>
      <ScrollToTop />
      <ModHelpRelated />
      <ModGroupSelect
        v-model="groupid"
        all
        modonly
        systemwide
        :work="['relatedmembers']"
        remember="membersrelated"
      />

      <div
        v-for="member in visibleMembers"
        :key="'memberlist-' + member.id"
        class="p-0 mt-2"
      >
        <ModRelatedMember :memberid="member.id" @processed="bump++" />
      </div>

      <infinite-loading
        direction="top"
        :distance="distance"
        :identifier="bump"
        @infinite="loadMore"
      >
        <template #spinner>
          <Spinner :size="50" />
        </template>
        <template #complete>
          <notice-message v-if="!visibleMembers?.length">
            There are no related members at the moment.
          </notice-message>
        </template>
      </infinite-loading>
    </client-only>
  </div>
</template>
<script setup>
import { computed, watch } from 'vue'
import { setupModMembers } from '~/composables/useModMembers'
import { useMemberStore } from '~/stores/member'

const memberStore = useMemberStore()
const { bump, collection, context, distance, groupid, show, loadMore } =
  setupModMembers(true)
collection.value = 'Related'

// Clear synchronously so no stale data from a prior visit flashes on first render.
memberStore.clear()

// Only the related pair entries (not the synthetic per-user entries).
const members = computed(() => {
  if (!memberStore) return []
  return Object.values(memberStore.list).filter(
    (m) => m.collection === 'Related' && !m._syntheticRelated
  )
})

// Filtering by groupid is handled by the API (groupid is passed in loadMore).
// No client-side filter needed — it would require userStore data that isn't
// populated for Related pairs, causing the single-community view to show nothing.
const visibleMembers = computed(() => members.value)

// When the community selection changes, reset state so loadMore re-fetches
// from the API with the new groupid rather than re-filtering stale data.
watch(groupid, () => {
  memberStore.clear()
  context.value = null
  show.value = 0
  bump.value++
})
</script>
