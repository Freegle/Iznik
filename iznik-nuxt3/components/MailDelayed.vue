<template>
  <b-row v-if="deferral">
    <b-col cols="12" xl="6" offset-xl="3" class="bottom verytop">
      <NoticeMessage variant="warning" class="mb-3 text-center">
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

const NoticeMessage = defineAsyncComponent(
  () => import('~/components/NoticeMessage')
)

const { me } = useMe()

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
</script>
<style scoped>
.bottom {
  position: fixed;
  bottom: 0;
}
</style>
