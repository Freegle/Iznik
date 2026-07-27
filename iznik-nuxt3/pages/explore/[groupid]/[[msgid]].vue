<template>
  <div>
    <client-only>
      <div>
        <b-row v-if="id && !group" class="m-0">
          <b-col
            cols="12"
            md="6"
            lg="6"
            class="p-0"
            offset-md="3"
            offset-lg="3"
          >
            <NoticeMessage variant="danger" class="mt-2">
              Sorry, we don't recognise that community name.
            </NoticeMessage>
          </b-col>
        </b-row>
        <b-row v-else class="m-0">
          <b-col
            cols="12"
            md="6"
            lg="6"
            class="p-0"
            offset-md="3"
            offset-lg="3"
          >
            <ExploreGroup :id="group.id" :msgid="msgid" :show-give-find="!me" />
          </b-col>
        </b-row>
      </div>
    </client-only>
    <!-- Everything above is client-only, which meant the HTML a crawler received
    contained no links to posts at all. This list is rendered on the server so that
    each community page is a real crawl path into its own posts. It's hidden from
    sighted users - the interactive list above is the one they use - but it is in the
    DOM and in the served HTML, with genuine links and real link text. -->
    <nav
      v-if="summaries.length"
      class="visually-hidden"
      aria-label="Recent posts"
    >
      <h2>Recent posts on {{ group?.namedisplay }}</h2>
      <ul>
        <li v-for="summary in summaries" :key="summary.id">
          <NuxtLink :to="'/message/' + summary.id">
            {{ summary.subject }}
          </NuxtLink>
        </li>
      </ul>
    </nav>
  </div>
</template>
<script setup>
import { computed, ref, useHead, useRuntimeConfig, useRoute } from '#imports'
import { buildHead } from '~/composables/useBuildHead'
import { useGroupStore } from '~/stores/group'
import ExploreGroup from '~/components/ExploreGroup'
import NoticeMessage from '~/components/NoticeMessage'
import { useMe } from '~/composables/useMe'

const runtimeConfig = useRuntimeConfig()
const route = useRoute()
const id = route.params.groupid
const msgid = parseInt(route.params.msgid)
const { me } = useMe()

const groupStore = useGroupStore()

const group = computed(() => {
  return groupStore.get(id)
})

/* Post subjects and ids for the server-rendered crawl path. Fetched directly rather
than through the message store because we want the smallest possible payload, and
we're rendering it on the server on every ISR regeneration. */
const summaries = ref([])

async function fetchSummaries(groupId) {
  if (!groupId) {
    return
  }

  try {
    const rsp = await fetch(
      runtimeConfig.public.APIv2 + '/group/' + groupId + '/message/summary'
    )

    if (rsp.ok) {
      summaries.value = (await rsp.json()) || []
    }
  } catch (e) {
    /* Not worth failing the page over - the interactive list still works. */
    console.log('Failed to fetch post summaries', e)
  }
}

if (id) {
  // Fetch the specific group.
  try {
    await groupStore.fetch(id, true)
  } catch (e) {
    console.error('Failed to fetch group', e.message)
  }

  if (group.value) {
    await fetchSummaries(group.value.id)

    const head = buildHead(
      route,
      runtimeConfig,
      'Explore ' + group.value.namedisplay,
      group.value.description
        ? group.value.description
        : "Give and get stuff for free. Offer things you don't need, and ask for things you'd like. Don't just recycle - reuse with Freegle!",
      group.value.profile ? group.value.profile : '/icon.png',
      {},
      {
        /* /explore/<group>/<msgid> is the same community page with one post opened,
        so point both forms at the plain community URL. */
        canonical: '/explore/' + id,
        /* Setting head.meta here used to REPLACE the array, throwing away the
        description and every og: and twitter: tag along with it. */
        noindex: !group.value.publish,
      }
    )

    useHead(head)
  } else {
    // Make sure it's not indexed.
    useHead({
      title: 'Community not found',
      meta: [{ name: 'robots', content: 'noindex' }],
    })
  }
} else {
  // Fetch all groups for the map.  No need to await - rendering the map is eye candy.
  groupStore.fetch()
  useHead(
    buildHead(
      route,
      runtimeConfig,
      'Explore Freegle',
      "Give and get stuff for free. Offer things you don't need, and ask for things you'd like. Don't just recycle - reuse with Freegle!"
    )
  )
}
</script>
