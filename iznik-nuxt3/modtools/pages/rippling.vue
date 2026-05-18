<template>
  <div style="position: fixed; top: 68px; left: 0; right: 0; bottom: 0">
    <div
      v-if="!loginStateKnown"
      style="
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        font-family: sans-serif;
        color: #888;
      "
    >
      Loading…
    </div>
    <div
      v-else-if="!mod"
      style="
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        font-family: sans-serif;
      "
    >
      <div style="text-align: center">
        <h2>Access denied</h2>
        <p>This page is only accessible to moderators.</p>
        <a href="/">← Back to modtools</a>
      </div>
    </div>
    <RipplingExplorer v-else :spatial-url="spatialUrl" :jwt="jwtToken" />
  </div>
</template>

<script setup>
import { computed, useRuntimeConfig } from '#imports'
import { useMe } from '~/composables/useMe'
import RipplingExplorer from '~/modtools/components/RipplingExplorer.vue'

definePageMeta({ layout: false })

const runtimeConfig = useRuntimeConfig()
const { mod, jwt: jwtToken, loginStateKnown } = useMe()

const spatialUrl = computed(
  () => runtimeConfig.public.SPATIAL_SERVER_URL || 'http://localhost:8196'
)
</script>
