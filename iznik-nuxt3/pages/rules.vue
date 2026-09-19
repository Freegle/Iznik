<template>
  <client-only>
    <div>
      <b-row class="m-0">
        <b-col cols="0" md="3" class="d-none d-md-block" />
        <b-col cols="12" md="6" class="bg-white pt-3 pb-3">
          <h1>Freegle's rules</h1>
          <SiteRules />
        </b-col>
        <b-col cols="0" md="3" class="d-none d-md-block" />
      </b-row>
    </div>
  </client-only>
</template>
<script setup>
import { buildHead } from '~/composables/useBuildHead'
import { useRoute } from '#imports'
import SiteRules from '~/components/SiteRules.vue'

const route = useRoute()

useHead(
  buildHead(
    route,
    useRuntimeConfig(),
    'Rules',
    'One set of rules for the whole of Freegle.'
  )
)
</script>
