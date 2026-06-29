<template>
  <b-container fluid class="bg-white">
    <b-card no-body class="mb-2">
      <b-card-body>
        <h4>Escalated clearance conversations</h4>
        <p v-if="!rows.length" class="mt-2 fw-bold">
          No escalated clearance conversations.
        </p>
        <div
          v-for="row in rows"
          :key="row.id"
          data-testid="escalated-row"
          class="mt-2 d-flex flex-wrap gap-2 align-items-start border-bottom pb-2"
        >
          <div class="flex-grow-1">
            <nuxt-link :to="'/clearance/' + row.msgid">
              {{ row.subject }}
            </nuxt-link>
            <span class="ms-2 text-muted small">{{ row.escalation_reason }}</span>
          </div>
          <div>
            <nuxt-link :to="'/user/' + row.userid">
              Member #{{ row.userid }}
            </nuxt-link>
          </div>
        </div>
      </b-card-body>
    </b-card>
  </b-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '~/stores/auth'

const authStore = useAuthStore()
const runtimeConfig = useRuntimeConfig()

const rows = ref([])

onMounted(async () => {
  const apiBase = runtimeConfig.public.APIv2
  const jwt = authStore.auth?.jwt
  const persistent = authStore.auth?.persistent
  const userid = authStore.user?.id

  const headers = {
    'Cache-Control': 'max-age=0, must-revalidate, no-cache, no-store, private',
  }
  if (jwt) headers.Authorization = JSON.stringify(jwt)
  if (persistent) headers.Authorization2 = JSON.stringify(persistent)

  let url = apiBase + '/helper/escalated'
  if (userid) url += '?loggedInAs=' + userid

  try {
    const res = await fetch(url, { headers })
    if (res.ok) {
      rows.value = await res.json()
    }
  } catch (e) {
    console.log('Failed to fetch escalated helpers', e)
  }
})

definePageMeta({
  layout: 'modtools',
})
</script>
