<template>
  <client-only>
    <b-container class="py-3">
      <b-row>
        <b-col cols="12" lg="8" offset-lg="2">
          <ClearanceManager :id="id" />
        </b-col>
      </b-row>
    </b-container>
  </client-only>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter, useHead } from '#imports'
import { useRuntimeConfig } from '#app'
import { buildHead } from '~/composables/useBuildHead'
import ClearanceManager from '~/components/ClearanceManager'

definePageMeta({
  layout: 'login',
})

const route = useRoute()
const router = useRouter()
const runtimeConfig = useRuntimeConfig()

const id = computed(() => parseInt(route.params.id))

useHead(
  buildHead(
    router.currentRoute.value,
    runtimeConfig,
    'Manage clearance',
    'See replies, allocate items and track collection for your bulk offer.'
  )
)
</script>
