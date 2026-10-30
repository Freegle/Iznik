<template>
  <HostingAdModal v-if="open" />
</template>
<script setup>
// Experiment krystal-hosting-ad. Mounted by LayoutCommon on every page; decides on arrival whether to
// ask the server about the Krystal hosting ad (composables/useHostingAd.js).
import { defineAsyncComponent, onMounted, watch } from 'vue'
import { useRoute } from '#imports'
import { useMiscStore } from '~/stores/misc'
import { useMobileStore } from '~/stores/mobile'
import { useUserStore } from '~/stores/user'
import { useMe } from '~/composables/useMe'
import {
  hostingAdOpen as open,
  considerHostingAd,
} from '~/composables/useHostingAd'

const HostingAdModal = defineAsyncComponent(
  () => import('~/components/HostingAdModal.vue')
)

const route = useRoute()
const { loggedIn } = useMe()
const userStore = useUserStore()

function consider() {
  considerHostingAd({
    loggedIn: loggedIn.value,
    path: route.path,
    query: route.query,
    native: useMobileStore().isApp,
    modtools: useMiscStore().modtools,
    fetchHostingAd: () => userStore.fetchHostingAd(),
  })
}

onMounted(consider)

// A member who arrives signed out and signs in on this page view is considered then.
watch(loggedIn, (now) => {
  if (now) consider()
})
</script>
