<template>
  <b-col>
    <client-only>
      <MicroVolunteering />
    </client-only>
    <b-row class="m-0">
      <b-col cols="0" lg="3" class="d-none d-lg-block">
        <client-only>
          <VisibleWhen
            :not="['xs', 'sm', 'md']"
            class="position-fixed"
            style="width: 300px"
          >
            <ExternalDa
              ad-unit-path="/22794232631/freegle_productemail"
              max-height="600px"
              div-id="div-gpt-ad-1691925773522-0"
              class="mt-2"
              placement="message_sidebar"
              show-logged-out
            />
          </VisibleWhen>
        </client-only>
      </b-col>
      <b-col cols="12" lg="6" class="p-0">
        <div v-if="gone" class="error-page">
          <div class="error-content">
            <div class="error-card">
              <v-icon icon="heart" class="error-icon" />
              <h1 class="error-title">This post isn't available</h1>
              <p
                v-if="
                  message?.outcomes?.length &&
                  message?.outcomes[0].outcome === 'Taken'
                "
                class="error-message"
              >
                Great news - it was successfully taken!
              </p>
              <p
                v-else-if="
                  message?.outcomes?.length &&
                  message?.outcomes[0].outcome === 'Received'
                "
                class="error-message"
              >
                Great news - it was successfully received!
              </p>
              <p
                v-else-if="
                  message?.outcomes?.length &&
                  message?.outcomes[0].outcome === 'Withdrawn'
                "
                class="error-message"
              >
                This post was withdrawn by the poster.
              </p>
              <p v-else-if="message?.deadline" class="error-message">
                This post had a deadline of
                {{ dateonlyNoYear(message.deadline) }}.
              </p>
              <p v-else class="error-message">
                If it was an OFFER, it's probably been TAKEN. If it was a
                WANTED, it's probably been RECEIVED.
              </p>
              <div class="error-buttons">
                <b-button to="/give" variant="primary">
                  <v-icon icon="gift" class="me-1" />Give stuff
                </b-button>
                <b-button to="/find" variant="secondary">
                  <v-icon icon="search" class="me-1" />Find stuff
                </b-button>
              </div>
            </div>
          </div>
        </div>
        <div
          v-else-if="
            mountComplete && myid && message && message.fromuser === myid
          "
        >
          <client-only>
            <MyMessage :id="id" :show-old="true" expand />
          </client-only>
        </div>
        <div v-else class="botpad">
          <GlobalMessage />
          <!-- The post subject is the page's heading. It's visually handled inside
          MessageExpanded, so keep this out of the way but present for crawlers and
          screen readers, which otherwise find no heading at all on this page. -->
          <h1 class="visually-hidden">{{ message?.subject }}</h1>
          <OurMessage
            :id="id"
            class="mt-3"
            :start-expanded="true"
            :view-source="viewSource"
            hide-close
            record-view
            @not-found="error = true"
            @replied="onReplied"
          />
        </div>
      </b-col>
      <b-col cols="0" lg="3" class="d-none d-lg-flex justify-content-end">
        <client-only>
          <VisibleWhen
            :not="['xs', 'sm', 'md']"
            class="position-fixed similar-posts-sidebar"
            style="width: 300px"
          >
            <ExternalDa
              ad-unit-path="/22794232631/freegle_productemail"
              max-width="300px"
              div-id="div-gpt-ad-1691925773522-0"
              class="mt-2"
              show-logged-out
              :jobs="false"
            />
            <!-- Desktop: similar posts live in the sidebar so they never push
                 the post itself around. On mobile they'd just be clutter, so
                 there they're shown as a modal after the user replies (see the
                 SimilarPostsModal wiring below). -->
            <SimilarPosts
              v-if="message && !showtaken"
              :msgid="id"
              variant="sidebar"
              :max="6"
              class="mt-3"
            />
          </VisibleWhen>
        </client-only>
      </b-col>
    </b-row>
    <!-- Mobile only: after a reply, surface the recommendations as a modal so
         they never clutter the post itself on a small screen (on desktop they
         live in the sidebar instead). -->
    <client-only>
      <b-modal
        v-model="showSimilarModal"
        title="More like this nearby"
        size="md"
        ok-only
        ok-title="Close"
      >
        <SimilarPosts
          v-if="showSimilarModal && message"
          :msgid="id"
          variant="modal"
          eager
          :max="6"
        />
      </b-modal>
    </client-only>
  </b-col>
</template>
<script setup>
import { buildHead, seoDescription } from '~/composables/useBuildHead'
import { messageJsonLd } from '~/composables/useMessageJsonLd'
import {
  ref,
  computed,
  onMounted,
  useHead,
  useRuntimeConfig,
  useRoute,
  setResponseStatus,
} from '#imports'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'
import { twem } from '~/composables/useTwem'
import { dateonlyNoYear } from '~/composables/useTimeFormat'
import MyMessage from '~/components/MyMessage'
import OurMessage from '~/components/OurMessage'
import GlobalMessage from '~/components/GlobalMessage'
import SimilarPosts from '~/components/SimilarPosts'
import VisibleWhen from '~/components/VisibleWhen'
import ExternalDa from '~/components/ExternalDa'
import MicroVolunteering from '~/components/MicroVolunteering'

const runtimeConfig = useRuntimeConfig()
const route = useRoute()
const messageStore = useMessageStore()
const authStore = useAuthStore()

// We don't use lazy because we want the page to be rendered for SEO.
const id = route?.params?.id ? parseInt(route.params.id) : 0

// Get showtaken query parameter
const showtaken = route?.query?.showtaken

/* Read through the same optional chaining the rest of this file uses. The template
used to reach for `$route.query.src` directly, which throws during the SSR hydration
race where route is briefly undefined - the very case the guards above exist for. */
const viewSource = route?.query?.src || 'message_page'

const failed = ref(false)
const error = ref(false)
const mountComplete = ref(false)

// After a reply, surface the "more like this" recommendations as a modal on
// mobile only. On desktop they already live in the sidebar, so opening a modal
// there would be redundant.
const showSimilarModal = ref(false)
function onReplied() {
  if (
    typeof window !== 'undefined' &&
    window.matchMedia &&
    window.matchMedia('(max-width: 991.98px)').matches
  ) {
    showSimilarModal.value = true
  }
}

const myid = computed(() => authStore.user?.id)

let ret = null

try {
  ret = await messageStore.fetch(id)
} catch (e) {
  // Likely to be because the message doesn't exist.
  console.log('Message fetch failed', e)
  failed.value = true
}

if (!ret) {
  failed.value = true
}

const message = computed(() => {
  return messageStore.byId(id)
})

/* Has this post finished its life - taken, received, withdrawn, deleted or rejected
everywhere? Roughly 8.3m of our message URLs are in this state against ~42k live ones,
and they used to answer 200 with the item's subject as the title, which reads to a
crawler as millions of near-identical thin pages. */
const gone = computed(() => {
  if (showtaken) {
    /* Someone followed a deliberate "show me it anyway" link. */
    return false
  }

  if (failed.value || !message.value) {
    return true
  }

  const m = message.value

  return Boolean(
    m.outcomes?.length > 0 ||
    m.deleted ||
    (m.groups?.length && m.groups.every((g) => g.collection === 'Rejected'))
  )
})

if (gone.value) {
  /* 410 rather than 404: these are posts that genuinely existed and are deliberately
  and permanently finished, which is exactly what Gone means. No-op on the client. */
  setResponseStatus(410)
}

if (message.value) {
  try {
    /* The API doesn't return a `snippet` field, so this used to fall through to the
    literal "Click for more details" on every post page on the site - telling Google
    that every post was a duplicate of every other one. Derive it from the body. */
    const snip = message.value.snippet
      ? twem(message.value.snippet) + '...'
      : seoDescription(twem(message.value.textbody || '')) ||
        'Click for more details'

    const headData = buildHead(
      route,
      runtimeConfig,
      message.value.subject,
      snip,
      message.value.attachments && message.value.attachments?.length > 0
        ? message.value.attachments[0].path
        : null,
      {},
      {
        /* Always the bare post URL: a post is reached with all sorts of ?src=
        tracking on it, and from the group pages too. */
        canonical: '/message/' + id,
        ogType: gone.value ? 'website' : 'product',
        /* A post that's been taken, withdrawn or deleted is not something we want
        in the index - there are millions of them and only tens of thousands live. */
        noindex: gone.value,
      }
    )
    const ld = messageJsonLd(message.value, runtimeConfig.public.USER_SITE, {
      gone: gone.value,
    })

    if (ld) {
      headData.script = [
        {
          type: 'application/ld+json',
          innerHTML: JSON.stringify(ld),
        },
      ]
    }

    useHead(headData)
  } catch (e) {
    console.error('Error in head setup', e)
    // Fallback to basic head
    useHead({ title: message.value.subject || 'Message' })
  }
} else if (gone.value) {
  /* Nothing to describe, but still keep it out of the index. */
  useHead({
    meta: [{ key: 'robots', name: 'robots', content: 'noindex, follow' }],
  })
}

/* We want to delay render of MyMessage until the mount fetch is complete, as it would otherwise not
contain the reply information correctly. */

onMounted(async () => {
  // We need to fetch again on the client, as the server may have rendered the page with data censored, because
  // it always renders logged out.
  try {
    await messageStore.fetch(id, true)
  } catch (e) {
    console.log('Message fetch on mount failed', e)
  }

  mountComplete.value = true
})
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

/* The fixed sidebar holds the ad and the similar-posts list stacked; cap its
   height to the viewport and let it scroll internally so a tall list never
   runs off the bottom of the screen. */
.similar-posts-sidebar {
  max-height: calc(100vh - 5rem);
  overflow-y: auto;
}

.error-page {
  min-height: 60vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.error-content {
  max-width: 400px;
  width: 100%;
}

.error-card {
  background: white;
  border-radius: var(--radius-lg, 0.75rem);
  box-shadow: var(--shadow-md);
  padding: 2rem;
  text-align: center;
}

.error-icon {
  font-size: 3rem;
  color: $color-success;
  margin-bottom: 1rem;
}

.error-title {
  font-size: 1.5rem;
  font-weight: 600;
  color: $color-gray--darker;
  margin-bottom: 0.75rem;
}

.error-message {
  color: var(--color-gray-600);
  margin-bottom: 1.5rem;
  line-height: 1.5;
}

.error-buttons {
  display: flex;
  gap: 0.75rem;
  justify-content: center;
  flex-wrap: wrap;
}
</style>
