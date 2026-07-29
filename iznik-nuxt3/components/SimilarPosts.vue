<template>
  <!-- Renders nothing at all (no heading, no reserved space) unless we have at
       least one genuine match; shows up to `max` of them. In the sidebar it
       lazy-loads when it scrolls into view; in a modal it loads eagerly because
       it's shown deliberately. -->
  <section
    v-observe-visibility="{
      callback: onVisible,
      options: { rootMargin: '200px' },
    }"
    class="similar-posts"
    :class="`similar-posts--${variant}`"
  >
    <template v-if="show">
      <h3 v-if="variant !== 'modal'" class="similar-posts__heading">
        More like this nearby
      </h3>
      <div class="similar-posts__list">
        <div v-for="mid in ids" :key="mid" class="similar-posts__item">
          <SimilarPostCard :id="mid" @click="open(mid)" />
        </div>
      </div>
    </template>
  </section>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { navigateTo } from '#imports'
import { useMessageStore } from '~/stores/message'
import { useMe } from '~/composables/useMe'
import SimilarPostCard from '~/components/SimilarPostCard'

const props = defineProps({
  // The post we're finding matches for.
  msgid: { type: [Number, String], required: true },
  // 'sidebar' (desktop, alongside the post) or 'modal' (mobile, after a reply).
  variant: { type: String, default: 'sidebar' },
  // Load immediately on mount rather than waiting for the visibility callback.
  // The modal is only mounted when it's opened, so it should fetch straight away.
  eager: { type: Boolean, default: false },
  // Cap the number of cards rendered. The sidebar has limited height alongside
  // the post; the modal can show more. 0 means no cap.
  max: { type: Number, default: 0 },
})

const SOURCE = 'similar_posts'
// A recorded control impression: a held-out view that deliberately showed nothing,
// so the server can compare its reply rate against the shown SOURCE above.
const HOLDOUT_SOURCE = 'similar_posts_holdout'
// Fraction of eligible (logged-in, non-mod) views held out as that control.
const HOLDOUT_FRACTION = 0.1

const messageStore = useMessageStore()
const { myid, mod } = useMe()

const ids = ref([])
// The observe-visibility directive has no "once" option and re-fires on every
// intersection change, so guard against re-entry / repeated loads ourselves.
let loaded = false

const show = computed(() => ids.value.length > 0)

// Reduce a subject to the bare item, for spotting cards that would read as the same:
// drop the "OFFER:"/"WANTED:" prefix and the trailing " (Location)".
function itemNameKey(m) {
  return (m?.subject || '')
    .replace(/^\s*(OFFER|WANTED)\s*:\s*/i, '')
    .replace(/\s*\([^)]*\)\s*$/, '')
    .trim()
    .toLowerCase()
}

// Collapse cards that would look like duplicates. People read "duplicate" from the
// PICTURE: two different posts of the same item whose photo is AI-generated (or a
// placeholder) look identical, so keep only the first of each item name among those.
// A real photo distinguishes genuinely-different items, so real-photo cards are never
// collapsed — there might legitimately be several of the same thing on offer.
function dedupeLookalikes(idList) {
  const seenGeneric = new Set()
  return idList.filter((id) => {
    const m = messageStore.list[id]
    const att = m?.attachments?.[0]
    if (att && !att.ai) {
      return true // a real photo — always distinguishable
    }
    const key = itemNameKey(m)
    if (seenGeneric.has(key)) {
      return false
    }
    seenGeneric.add(key)
    return true
  })
}

async function load() {
  if (loaded) {
    return
  }
  loaded = true

  let results
  try {
    // Over-fetch a little: the AI-lookalike dedupe below can drop some, and we still
    // want up to `max` distinct cards.
    results = await messageStore.similar(props.msgid, 12)
  } catch (e) {
    return
  }
  if (!results || results.length === 0) {
    return
  }

  const found = results.map((r) => r.id)
  // Hydrate the summaries so the card can render them from the store.
  await Promise.all(found.map((id) => messageStore.fetch(id).catch(() => null)))
  // Only keep the ones that actually resolved to a renderable message.
  let renderable = found.filter((id) => messageStore.list[id])
  renderable = dedupeLookalikes(renderable)
  if (props.max > 0) {
    renderable = renderable.slice(0, props.max)
  }
  if (renderable.length === 0) {
    return
  }

  // Random holdout: a control slice of eligible views never sees the strip, so we
  // can measure whether the recommendations actually change behaviour. Moderators
  // and logged-out users are excluded — mods are power users who would skew it, and
  // an anonymous view can't be tied to any later reply. It's a plain RNG rather than
  // an id-derived bucket: Galera hands out user ids in strided gaps, so id % N is a
  // biased sampler (some trailing digits never occur). Record the withheld view
  // (source-tagged) so the server can compare shown vs held-out reply rates, then
  // stop before rendering anything.
  if (myid.value && !mod.value && Math.random() < HOLDOUT_FRACTION) {
    messageStore.markSeen(renderable, HOLDOUT_SOURCE).catch(() => {})
    return
  }

  ids.value = renderable
  // Count the impression once (source-tagged so it's attributable). Fire and
  // forget, but swallow rejections so they never surface as unhandled.
  messageStore.markSeen(ids.value, SOURCE).catch(() => {})
}

function onVisible(visible) {
  if (visible) {
    load()
  }
}

onMounted(() => {
  if (props.eager) {
    load()
  }
})

function open(mid) {
  navigateTo('/message/' + mid + '?src=' + SOURCE)
}
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.similar-posts {
  margin-top: 1.5rem;
}

.similar-posts__heading {
  font-size: 1.05rem;
  font-weight: 700;
  margin-bottom: 0.5rem;
}

/* Vertical list: cards stack down the column. Used both in the desktop sidebar
   (alongside the post, so it never pushes the post around) and in the mobile
   after-reply modal. */
.similar-posts__list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.similar-posts__item {
  width: 100%;
}

/* In the fixed desktop sidebar the list can grow taller than the viewport once
   there are several cards, so give the list its own scroll rather than letting it
   run off the bottom of the screen. The modal scrolls itself. */
.similar-posts--sidebar .similar-posts__list {
  max-height: calc(100vh - 14rem);
  overflow-y: auto;
  padding-right: 4px;
}
</style>
