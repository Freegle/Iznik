<template>
  <b-row v-if="show">
    <b-col cols="12" xl="6" offset-xl="3" class="bottom verytop">
      <NoticeMessage variant="warning" class="mb-3 text-center delayed">
        <button
          type="button"
          class="test-dismiss dismiss"
          aria-label="Dismiss this message"
          @click="dismiss"
        >
          <v-icon icon="times" />
        </button>
        <v-icon icon="clock" />
        <strong>{{ deferral.domain }}</strong> is currently limiting how quickly
        it accepts our email, so anything we send you may arrive late<span
          v-if="delayedSince"
        >
          - this started {{ delayedSince }}</span
        >. Nothing is lost. Please check back here regularly so you don't miss
        replies to your posts.
      </NoticeMessage>
    </b-col>
  </b-row>
</template>
<script setup>
import { useMe } from '~/composables/useMe'
import { timeago } from '~/composables/useTimeFormat'
import { useMiscStore } from '~/stores/misc'

const NoticeMessage = defineAsyncComponent(
  () => import('~/components/NoticeMessage')
)

const { me } = useMe()
const miscStore = useMiscStore()

// Set by the session call when the member's PREFERRED address is at a domain
// our relay currently cannot deliver to. Deliberately suppressed while
// bouncing is showing: that banner is fixed to the same position, and it is
// also the more urgent of the two, being the one the member can actually act
// on. This one only asks them to wait and check back.
const deferral = computed(() => {
  if (!me.value || me.value.bouncing) {
    return null
  }

  return me.value.emaildeferred || null
})

const delayedSince = computed(() => {
  const since = deferral.value?.since

  return since ? timeago(since) : null
})

// Which delay this is. Dismissing means "I have read this one", not "stop
// telling me about mail", so a later delay - or a different domain going slow -
// speaks up again rather than being silenced by an old dismissal.
const episode = computed(() => {
  const d = deferral.value

  return d ? d.domain + ':' + (d.since || '') : null
})

// The banner is fixed to the bottom of the viewport, so on a phone it covers
// whatever is down there - including the Send button. It has to be possible to
// put it away.
const show = computed(() => {
  return (
    Boolean(deferral.value) &&
    miscStore.vals?.mailDelayedDismissed !== episode.value
  )
})

function dismiss() {
  miscStore.set({ key: 'mailDelayedDismissed', value: episode.value })
}
</script>
<style scoped lang="scss">
.bottom {
  position: fixed;
  bottom: 0;
}

.delayed {
  position: relative;
  /* Keeps the centred text clear of the dismiss button on a narrow screen. */
  padding-right: 2rem;
}

.dismiss {
  position: absolute;
  top: 0;
  right: 0;
  padding: 0.25rem 0.5rem;
  border: 0;
  background: none;
  color: inherit;
  line-height: 1;
  cursor: pointer;
}
</style>
