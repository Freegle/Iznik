<template>
  <b-row v-if="notice" class="lockdown">
    <b-col cols="12" xl="6" offset-xl="3">
      <NoticeMessage :variant="variant" class="mb-3 text-center">
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
const variant = computed(() =>
  notice.value?.key === 'security' ? 'danger' : 'warning'
)
</script>
<style scoped lang="scss">
/*
 * Deliberately NOT position: fixed. MailDelayed and BouncingEmail pin
 * themselves to the bottom of the viewport (the "bottom verytop" pattern,
 * z-index 10000) and MailDelayed's own comment admits what that costs: "on
 * a phone it covers whatever is down there - including the Send button" -
 * mitigated there only by being dismissible. LockdownNotice can't take that
 * mitigation (it is deliberately never dismissible, see above), so a fixed
 * position would permanently sit over the reply composer's send button
 * (and anything else near the bottom of the screen) with no way for a
 * member to get it out of the way. It rendered above ChatReplyPane's
 * .reply-overlay too, at z-index 9999, one tier below the "verytop" the
 * banner was reusing, so the overlap wasn't just visual - the banner
 * silently ate the click.
 *
 * Rendered instead as a normal block at the top of the page content (see
 * LayoutCommon.vue, inside main.pageContent, above the slot), so it pushes
 * the rest of the page down rather than floating over it. A member always
 * sees it on page load without needing position tricks, and it can never
 * sit above a fixed-position overlay like the reply composer, because
 * normal in-flow content always paints below anything explicitly
 * positioned - no z-index arms race required.
 */
.lockdown {
  flex: 0 0 auto;
}
</style>
