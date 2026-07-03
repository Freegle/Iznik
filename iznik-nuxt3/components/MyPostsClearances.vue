<template>
  <div
    v-if="clearances.length"
    class="myposts-clearances"
    data-testid="myposts-clearances"
  >
    <h5 class="myposts-clearances__head">
      <v-icon icon="gift" class="text-success me-1" />Your clearances
    </h5>
    <p class="small text-muted mb-2">
      These posts offer several items at once. Manage replies and decide who
      gets what.
    </p>
    <div
      v-for="c in clearances"
      :key="c.id"
      class="myposts-clearances__row"
      :data-testid="'clearance-row-' + c.id"
    >
      <div class="myposts-clearances__detail">
        <span class="myposts-clearances__subject">{{ c.subject }}</span>
        <b-badge variant="info">{{ c.available }} available</b-badge>
        <b-badge v-if="c.interested" variant="info" class="ms-1">
          {{ c.interested }} interested
        </b-badge>
      </div>
      <b-button
        variant="primary"
        size="sm"
        :to="'/clearance/' + c.id"
        :data-testid="'manage-' + c.id"
      >
        Manage <v-icon icon="angle-right" />
      </b-button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useMessageStore } from '~/stores/message'
import { distinctInterestedUsers } from '~/composables/useClearance'

const props = defineProps({
  // The user's posts (message summaries from myposts). The full message — with
  // bulkcount — is loaded into the store for active posts, which is where we
  // read the clearance details from.
  posts: { type: Array, default: () => [] },
})

const messageStore = useMessageStore()

const clearances = computed(() =>
  (props.posts || [])
    // Guard against undefined/idless entries: myposts can hand us holes (e.g. a
    // not-yet-loaded or removed post), and reading p.id on an undefined p throws.
    .filter((p) => p && p.id != null)
    .map((p) => {
      const full = messageStore.byId(p.id)
      const bulkcount = full?.bulkcount ?? full?.bulkitems?.length ?? 0
      // Total quantity still available (matches the post / browse "N available"),
      // not the number of catalogue rows. Falls back to summing item quantities.
      const available =
        full?.availablenow ||
        (full?.bulkitems
          ? full.bulkitems.reduce(
              (s, i) => s + (parseInt(i.quantity, 10) || 0),
              0
            )
          : 0)
      return {
        id: p.id,
        subject: full?.subject || p.subject,
        bulkcount,
        available,
        interested: full?.bulkitems
          ? distinctInterestedUsers(full.bulkitems)
          : 0,
      }
    })
    .filter((c) => c.bulkcount > 0)
)
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.myposts-clearances {
  border: 1px solid $color-gray--lighter;
  border-radius: 6px;
  padding: 0.75rem;
  margin-bottom: 1rem;
  background-color: $color-green--light;
}

.myposts-clearances__head {
  font-weight: 700;
}

.myposts-clearances__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.4rem 0;
  border-top: 1px solid $color-gray--lighter;
}

.myposts-clearances__detail {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.4rem;
  min-width: 0;
}

.myposts-clearances__subject {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
