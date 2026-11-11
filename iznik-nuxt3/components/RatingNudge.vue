<template>
  <RatingNudgeModal v-if="open" />
</template>
<script setup>
// Experiment rate-on-return. Mounted by LayoutCommon on every page; decides on arrival whether to
// ask the member to rate the people they dealt with (composables/useRatingNudge.js).
import { defineAsyncComponent, onMounted } from 'vue'
import { useRoute } from '#imports'
import { useMiscStore } from '~/stores/misc'
import { useMobileStore } from '~/stores/mobile'
import { useUserStore } from '~/stores/user'
import { useMe } from '~/composables/useMe'
import {
  ratingNudgeOpen as open,
  considerRatingNudge,
} from '~/composables/useRatingNudge'

const RatingNudgeModal = defineAsyncComponent(
  () => import('~/components/RatingNudgeModal.vue')
)

const route = useRoute()
const { loggedIn } = useMe()
const userStore = useUserStore()

onMounted(() => {
  considerRatingNudge({
    loggedIn: loggedIn.value,
    path: route.path,
    query: route.query,
    native: useMobileStore().isApp,
    modtools: useMiscStore().modtools,
    fetchUnrated: () => userStore.fetchUnrated(),
  })
})
</script>
