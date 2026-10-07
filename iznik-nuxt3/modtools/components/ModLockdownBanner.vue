<template>
  <NoticeMessage
    v-if="lockdownStore.active"
    variant="danger"
    class="mb-2 text-center"
  >
    <v-icon icon="triangle-exclamation" class="me-1" />
    Lockdown is active{{ lockdownStore.reason ? ': ' + lockdownStore.reason : '.' }}
    Only the basic Approve buttons are shown while it holds.
  </NoticeMessage>
</template>
<script setup>
import { useLockdownStore } from '~/stores/lockdown'

// plans/active/2026-09-27-lockdown-switch.md section 10.8: "Members: nothing,
// by default. Moderators: always the banner." Unlike LockdownNotice.vue on
// the member site, showing this is not a choice the presser makes - it is
// tied directly to lockdownStore.active, fed by the same thirty-second work
// poll as the button-hiding (useModMe.js), so a moderator always knows why
// some Approve buttons have gone missing.
const lockdownStore = useLockdownStore()
</script>
