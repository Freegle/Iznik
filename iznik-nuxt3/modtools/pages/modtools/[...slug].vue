<template>
  <div>
    <h1>
      Redirecting you to...
      <NuxtLink :to="path">{{ path }}</NuxtLink>
    </h1>
  </div>
</template>

<script setup>
import { useRoute, useRouter } from '#imports'

// Derive the target once, here in setup, and hold it as a plain string.
//
// It must not be a computed over useRoute(). A computed getter re-run from
// outside a Vue call stack - which is what the timer below is - gets no
// injection context, so useRoute() falls through to the live global route
// rather than this page's own. params.slug only exists while this catch-all is
// the matched route, so on any other route it is undefined.
const route = useRoute()
const slug = route?.params?.slug

// A catch-all param is an array of segments, or '' when nothing followed
// /modtools. Tolerate a bare string too rather than iterating whatever we got.
const segments = Array.isArray(slug) ? slug : slug ? [slug] : []
const path = segments.length ? segments.map((p) => '/' + p).join('') : '/'

const router = useRouter()
let timer = null

onMounted(() => {
  timer = setTimeout(() => {
    timer = null
    router.push(path)
  }, 2000)
})

onBeforeUnmount(() => {
  // The page can go before the redirect fires: the moderator clicks the link
  // rather than waiting out the two seconds, or the layout re-keys its subtree
  // once login state settles. An uncleared timer outlives the page and
  // navigates whoever is on screen by then.
  if (timer) {
    clearTimeout(timer)
    timer = null
  }
})
</script>
