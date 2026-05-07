<template>
  <div class="bg-white">
    <p v-if="ssoError" class="text-danger">
      Unable to log you into Discourse — your ModTools session could not be
      verified. Please
      <a href="/login">log in to ModTools</a> first, then try Discourse again.
    </p>
    <p v-else>
      This should redirect you back to Discourse. If it doesn't, mail
      geeks@ilovefreegle.org.
    </p>
    <Spinner v-if="!ssoError" :size="50" />
  </div>
</template>
<script setup>
import { ref, watch, onMounted } from 'vue'
import { useRoute } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'

const { myid } = useMe()
const route = useRoute()
const ssoError = ref(false)

function redirect() {
  const authStore = useAuthStore()
  const hasPersistent = !!authStore.auth.persistent
  const hasJwt = !!authStore.auth.jwt
  const ssoChallengeInUrl = !!(route.query.sso && route.query.sig)

  console.log('[discourse] redirect called', {
    myid: myid.value,
    hasPersistent,
    hasJwt,
    ssoChallengeInUrl,
    sso: route.query.sso ? route.query.sso.substring(0, 20) + '…' : null,
  })

  if (hasPersistent) {
    const cookieValue = encodeURIComponent(
      JSON.stringify(authStore.auth.persistent)
    )
    document.cookie =
      'Iznik-Discourse-SSO=' +
      cookieValue +
      '; path=/; domain=' +
      window.location.hostname +
      '; secure; samesite=none'
    console.log('[discourse] Iznik-Discourse-SSO cookie set', {
      sessionId: authStore.auth.persistent.id,
      series: authStore.auth.persistent.series,
      domain: window.location.hostname,
    })
  } else {
    console.warn(
      '[discourse] auth.persistent is null — cannot set SSO cookie.',
      'hasJwt:', hasJwt
    )
  }

  if (ssoChallengeInUrl) {
    // We arrived here because the SSO endpoint couldn't find the cookie and
    // redirected us here with the original sso/sig preserved by Netlify.
    // Now that we've set the cookie, retry the SSO endpoint directly with the
    // same nonce rather than going to the Discourse homepage (which would
    // generate a new nonce and loop forever).
    if (!hasPersistent) {
      // Can't complete SSO — stop the loop and show an error.
      console.error(
        '[discourse] SSO loop broken: no persistent token available.',
        'User appears logged in (myid=' + myid.value + ') but auth.persistent is null.',
        'This means the session was not persisted to the DB or was cleared.'
      )
      ssoError.value = true
      return
    }
    const retryUrl =
      window.location.origin +
      '/discourse_sso?sso=' +
      route.query.sso +
      '&sig=' +
      route.query.sig
    console.log('[discourse] retrying SSO endpoint with cookie:', retryUrl)
    window.location = retryUrl
  } else {
    // Entry point: user clicked Discourse from the modtools nav.
    // Go to Discourse homepage; Discourse will initiate SSO if not logged in.
    console.log('[discourse] redirecting to Discourse homepage')
    window.location = 'https://discourse.ilovefreegle.org'
  }
}

watch(myid, (newVal, oldVal) => {
  console.log('[discourse] watch myid', { oldVal, newVal })
  if (!oldVal && newVal) {
    redirect()
  }
})

onMounted(() => {
  console.log('[discourse] mounted', {
    myid: myid.value,
    sso: route.query.sso ? route.query.sso.substring(0, 20) + '…' : null,
    sig: route.query.sig ? route.query.sig.substring(0, 20) + '…' : null,
  })
  if (myid.value) {
    redirect()
    return
  }
  const authStore = useAuthStore()
  const me = authStore.user
  if (me && me.id) {
    console.log('[discourse] mounted: user in store but myid not yet reactive, id=', me.id)
    redirect()
  }
})
</script>
