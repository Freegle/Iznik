<template>
  <b-row v-if="notice">
    <b-col cols="12" xl="6" offset-xl="3" class="bottom verytop">
      <NoticeMessage :variant="variant" class="mb-3 text-center lockdown">
        <v-icon icon="triangle-exclamation" />
        {{ notice.text }}
      </NoticeMessage>
    </b-col>
  </b-row>
</template>
<script setup>
import { useLockdownStore } from '~/stores/lockdown'

const NoticeMessage = defineAsyncComponent(
  () => import('~/components/NoticeMessage')
)

// Fed by GET /lockdown on the navbar's sixty-second pass (see useNavbar.js).
// Nothing by default - Support has to have deliberately chosen a notice
// during a pressed lockdown (plans/active/2026-09-27-lockdown-switch.md
// section 10.8). Unlike MailDelayed this is never dismissible: the choice
// to show it was made once, on purpose, for everyone, and it should stay
// up for as long as that choice stands rather than for as long as nobody
// has clicked it away.
const lockdownStore = useLockdownStore()

const notice = computed(() => lockdownStore.notice)

// The security notice is a warning not to click a link - worth the red.
// Everything else (delay, and the "back to normal" notice shown for a day
// after close) is informational.
const variant = computed(() => (notice.value?.key === 'security' ? 'danger' : 'warning'))
</script>
<style scoped lang="scss">
.bottom {
  position: fixed;
  bottom: 0;

  /* Never dismissible and never interactive (no link, no close button - see
     the comment above), so it must not sit in the way of a click on
     whatever real, interactive UI happens to be under it - the reply
     overlay's send button, ChitChat, anything else pinned near the bottom
     of the viewport. pointer-events: none lets those clicks fall through
     to what's actually underneath while the notice stays visible. */
  pointer-events: none;
}

.lockdown {
  position: relative;
}
</style>
