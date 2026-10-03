<template>
  <div>
    <div
      v-for="member in visibleMembers"
      :key="'memberlist-' + member.id"
      class="p-0 mt-2"
    >
      <ModMember :membershipid="member.id" :actions="false" />
    </div>
  </div>
</template>

<script setup>
import { setupModMembers } from '~/composables/useModMembers'

// The old "With notes" filter value (numeric 1) that :expand-comments used
// to key off no longer exists - the contract's filter values are
// new/flagged/banned/search. No new value maps onto "expand comments by
// default", so it's simply off now for every filter.
const { visibleMembers } = setupModMembers()
</script>
