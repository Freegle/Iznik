<template>
  <div class="bg-white p-3">
    <div v-if="ssoError === 'notmod'">
      <p class="text-danger">
        Discourse is only available to Freegle moderators, and your ModTools
        account isn't currently a moderator of any community.
      </p>
      <p>
        If you think it should be, ask one of your community's owners to
        restore your role, or email
        <a href="mailto:geeks@ilovefreegle.org">geeks@ilovefreegle.org</a>.
      </p>
      <p><a href="/">Back to ModTools</a></p>
    </div>
    <div v-else-if="ssoError">
      <p class="text-danger">We couldn't verify your ModTools session.</p>
      <p>
        Please log out of ModTools,
        <a href="/login">log in again</a>, then try Discourse again.
      </p>
    </div>
    <div v-else>
      <p>
        This should redirect you back to Discourse. If it doesn't, mail
        geeks@ilovefreegle.org.
      </p>
      <Spinner :size="50" />
    </div>
  </div>
</template>
<script setup>
import { ref, watch, onMounted } from 'vue'
import { useRoute } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'

// How this page fits the flow:
//
//   Us link -> here (no query) -> set cookie -> Discourse homepage
//   Discourse -> /discourse_sso?sso&sig (API, via Netlify) -> reads the cookie
//     no cookie            -> here, same sso&sig       -> set cookie, retry once
//     refused (?ssoerror)  -> here, message, no retry
//
// A refusal carries its class in ?ssoerror because a retry with the same
// cookie gets the same answer. The retry counter below is the belt to that
// brace: whatever sends this page round again, it stops after a couple of
// goes rather than reloading itself every few hundred milliseconds.

const RETRY_KEY = 'discourse-sso-retries'
const RETRY_WINDOW_MS = 60 * 1000
const MAX_RETRIES = 2

const { myid } = useMe()
const route = useRoute()

// '' while redirecting, otherwise 'notmod' or 'session'. Any other value the
// API might one day send is shown as a session problem: safe, and it still
// stops the retry.
const ssoError = ref(errorClassFromQuery(route.query.ssoerror))

function errorClassFromQuery(value) {
  if (!value) {
    return ''
  }
  return String(value) === 'notmod' ? 'notmod' : 'session'
}

function readRetries() {
  try {
    const raw = window.sessionStorage.getItem(RETRY_KEY)
    if (raw) {
      const state = JSON.parse(raw)
      if (state && Date.now() - state.since < RETRY_WINDOW_MS) {
        return state
      }
    }
  } catch (e) {
    // sessionStorage unavailable or unreadable: treat as no retries yet.
  }
  return { count: 0, since: Date.now() }
}

function clearRetries() {
  try {
    window.sessionStorage.removeItem(RETRY_KEY)
  } catch (e) {
    // Nothing to clear.
  }
}

// Records one more retry and says whether it may go ahead.
function allowRetry() {
  const state = readRetries()
  if (state.count >= MAX_RETRIES) {
    clearRetries()
    return false
  }
  state.count += 1
  try {
    window.sessionStorage.setItem(RETRY_KEY, JSON.stringify(state))
  } catch (e) {
    // Can't count, so can't loop-guard; the server's ?ssoerror still stops us.
  }
  return true
}

function setSSOCookie(persistent) {
  const cookieValue = encodeURIComponent(JSON.stringify(persistent))
  document.cookie =
    'Iznik-Discourse-SSO=' +
    cookieValue +
    '; path=/; domain=' +
    window.location.hostname +
    '; secure; samesite=none'
  console.log('[discourse] Iznik-Discourse-SSO cookie set', {
    sessionId: persistent.id,
    series: persistent.series,
    domain: window.location.hostname,
  })
}

function redirect() {
  if (ssoError.value) {
    return
  }

  const authStore = useAuthStore()
  const persistent = authStore.auth.persistent
  const ssoChallengeInUrl = !!(route.query.sso && route.query.sig)

  console.log('[discourse] redirect called', {
    myid: myid.value,
    hasPersistent: !!persistent,
    hasJwt: !!authStore.auth.jwt,
    ssoChallengeInUrl,
  })

  if (!persistent) {
    // Logged in as far as the API is concerned, but with nothing to put in
    // the cookie, so the SSO endpoint could never accept us.
    console.warn('[discourse] auth.persistent is null - cannot set SSO cookie')
    ssoError.value = 'session'
    clearRetries()
    return
  }

  if (!ssoChallengeInUrl) {
    // Entry point: the Us link. Discourse starts the SSO handshake itself.
    clearRetries()
    setSSOCookie(persistent)
    window.location = 'https://discourse.ilovefreegle.org'
    return
  }

  // The SSO endpoint found no cookie and sent us back with the original
  // nonce. Set the cookie and retry that same nonce - not the Discourse
  // homepage, which would mint a new nonce and never finish.
  if (!allowRetry()) {
    console.error('[discourse] SSO retried too often; stopping')
    ssoError.value = 'session'
    return
  }
  setSSOCookie(persistent)
  window.location =
    window.location.origin +
    '/discourse_sso?sso=' +
    route.query.sso +
    '&sig=' +
    route.query.sig
}

watch(myid, (newVal, oldVal) => {
  if (!oldVal && newVal) {
    redirect()
  }
})

onMounted(() => {
  if (ssoError.value) {
    // The API refused us; the message is up and nothing else should happen.
    clearRetries()
    return
  }
  if (myid.value) {
    redirect()
    return
  }
  const me = useAuthStore().user
  if (me && me.id) {
    redirect()
  }
})
</script>
