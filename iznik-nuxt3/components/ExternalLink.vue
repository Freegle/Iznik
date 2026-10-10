<template>
  <!-- eslint-disable-next-line -->
  <a :href="carefulHref" :target="target" rel="noopener noreferrer" @click="openInBrowser"><slot /></a>
</template>
<script setup>
import { computed } from 'vue'
import { useMobileStore } from '@/stores/mobile'

const props = defineProps({
  href: {
    type: String,
    required: true,
  },
})

const carefulHref = computed(() => {
  return props.href?.startsWith('http') || props.href?.startsWith('mailto')
    ? props.href
    : 'https://' + props.href
})

const target = computed(() => {
  return props.href?.startsWith('mailto') ? '_self' : '_blank'
})

function openInBrowser(event) {
  const mobileStore = useMobileStore()
  if (mobileStore.isApp) {
    // Returning false from a Vue handler does not cancel the click, so without this the
    // link opens as well and the page is opened twice - for a job advert, two clicks of
    // which WhatJobs pays for neither.
    event?.preventDefault()
    const url = carefulHref.value
    import('@capacitor/app-launcher').then(({ AppLauncher }) => {
      AppLauncher.openUrl({ url })
    })
    return false
  }
  return true
}
</script>
