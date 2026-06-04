<template>
  <client-only>
    <b-row class="m-0">
      <b-col cols="0" md="3" />
      <b-col cols="12" md="6" class="mt-2">
        <div v-if="error">
          <h1 class="text-danger">Error</h1>
          <p>{{ error }}</p>
        </div>
        <div v-else>
          <h1>{{ eventData.name }}</h1>
          <div v-if="eventData.description" class="mb-3">
            <p v-html="descriptionWithLinks"></p>
          </div>
          <div v-if="eventData.name">
            <add-to-calendar-button
              style-light="background-color: var(--color-primary-bg); color: white; border-radius: 4px; padding: 10px 20px; border: none; font-weight: 500;"
              button-style="default"
              light-mode="bodyScheme"
            >
              {{
                JSON.stringify({
                  name: eventData.name,
                  description: eventData.description,
                  startDate: eventData.startDate,
                  startTime: eventData.startTime,
                  endTime: eventData.endTime,
                  timeZone: eventData.timeZone,
                  location: eventData.location,
                  options: ['Google', 'Apple', 'Microsoft365', 'Yahoo', 'iCal'],
                })
              }}
            </add-to-calendar-button>
          </div>
        </div>
      </b-col>
    </b-row>
  </client-only>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from '#imports'
import { buildHead } from '~/composables/useBuildHead'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()

// Load the web component non-blockingly so setup never awaits on it.
// The button is only rendered when eventData has a name (v-if="eventData.name"),
// so it will be available by the time it needs to render.
onMounted(() => {
  import('add-to-calendar-button').catch(() => {})
})

/**
 * Decode a base64 or base64url string.
 * base64url uses '-' instead of '+' and '_' instead of '/'.
 * A '+' in a query string is decoded to a space by URL parsers, so we
 * must handle both alphabets.  Standard base64 strings contain no '-' or '_'
 * so the replace is harmless for them.
 */
function b64decode(s) {
  const t = s.replace(/-/g, '+').replace(/_/g, '/')
  const pad = t.length % 4 ? '='.repeat(4 - (t.length % 4)) : ''
  return atob(t + pad)
}

/**
 * Reactively derive the raw base64 string from the route query.
 * Falls back to window.location.search for the transient instant when
 * Nuxt's router has not yet propagated the query (e.g. immediately after
 * Netlify's trailing-slash 301 redirect).
 */
const rawData = computed(() => {
  const q = route?.query?.data
  if (q) return Array.isArray(q) ? q[0] : q
  if (process.client && typeof window !== 'undefined') {
    return new URLSearchParams(window.location.search).get('data')
  }
  return null
})

/**
 * Single reactive derivation that returns { error, event }.
 * Because this is a computed, it re-evaluates whenever rawData changes —
 * so a stale "no data" error is automatically cleared when the query arrives.
 */
const parsed = computed(() => {
  if (!rawData.value) {
    return { error: 'No calendar event data provided', event: null }
  }
  try {
    const eventObj = JSON.parse(b64decode(rawData.value))
    if (!eventObj.name || !eventObj.startDate || !eventObj.startTime) {
      return { error: 'Missing required event information', event: null }
    }
    return { error: null, event: eventObj }
  } catch (e) {
    return { error: 'Unable to load calendar event: ' + e.message, event: null }
  }
})

const error = computed(() => parsed.value.error)

const eventData = computed(() => {
  const ev = parsed.value.event
  if (ev) {
    return {
      name: ev.name || '',
      description: ev.description || '',
      startDate: ev.startDate || '',
      startTime: ev.startTime || '',
      endTime: ev.endTime || '',
      timeZone: ev.timeZone || 'Europe/London',
      location: ev.location || '',
    }
  }
  return {
    name: '',
    description: '',
    startDate: '',
    startTime: '',
    endTime: '',
    timeZone: 'Europe/London',
    location: '',
  }
})

const descriptionWithLinks = computed(() => {
  if (!eventData.value.description) return ''
  return eventData.value.description.replace(
    /(https?:\/\/[^\s]+)/g,
    '<a href="$1" target="_blank">$1</a>'
  )
})

useHead({
  ...buildHead(
    route,
    runtimeConfig,
    'Add to Calendar',
    'Add this Freegle handover to your calendar'
  ),
  link: [
    {
      rel: 'stylesheet',
      href: 'https://cdn.jsdelivr.net/npm/add-to-calendar-button@2/assets/css/atcb.css',
    },
  ],
})
</script>
