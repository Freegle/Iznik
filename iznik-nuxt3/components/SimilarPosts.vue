<template>
  <!-- The whole strip is lazy: nothing is fetched until it scrolls into view,
       and it renders nothing at all (no heading, no reserved space) unless we
       have at least a few genuine matches. -->
  <section
    v-observe-visibility="{
      callback: onVisible,
      once: true,
      intersection: { rootMargin: '200px' },
    }"
    class="similar-posts"
  >
    <template v-if="show">
      <h3 class="similar-posts__heading">More like this nearby</h3>
      <div class="similar-posts__row">
        <div v-for="mid in ids" :key="mid" class="similar-posts__item">
          <MessageSummary :id="mid" @expand="open(mid)" />
        </div>
      </div>
    </template>
  </section>
</template>

<script setup>
import { ref, computed } from 'vue'
import { navigateTo } from '#imports'
import { useMessageStore } from '~/stores/message'
import { useMe } from '~/composables/useMe'
import MessageSummary from '~/components/MessageSummary'

const props = defineProps({
  // The post we're finding matches for.
  msgid: { type: [Number, String], required: true },
})

const MIN_RESULTS = 3
const SOURCE = 'similar_posts'

const messageStore = useMessageStore()
const { myid } = useMe()

const ids = ref([])

// 10% deterministic holdout: logged-in users whose id ends in 0 never see the
// strip (and it never fetches), giving a clean control group for measuring
// whether the recommendations are worth showing. Anonymous users always see it
// and are excluded from the cohort analysis.
const inHoldout = computed(() => !!myid.value && myid.value % 10 === 0)

const show = computed(() => ids.value.length >= MIN_RESULTS)

async function onVisible(visible) {
  if (!visible || inHoldout.value || ids.value.length) {
    return
  }

  let results
  try {
    results = await messageStore.similar(props.msgid, 8)
  } catch (e) {
    return
  }
  if (!results || results.length < MIN_RESULTS) {
    return
  }

  const found = results.map((r) => r.id)
  // Hydrate the summaries so MessageSummary can render them from the store.
  await Promise.all(found.map((id) => messageStore.fetch(id).catch(() => null)))
  // Only keep the ones that actually resolved to a renderable message.
  ids.value = found.filter((id) => messageStore.list[id])

  if (ids.value.length >= MIN_RESULTS) {
    // Count the impression once (source-tagged so it's attributable).
    messageStore.markSeen(ids.value, SOURCE)
  } else {
    ids.value = []
  }
}

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

/* Horizontal scroller. The row scrolls inside its own container so it never
   pushes the page into horizontal scroll; a partial next card peeks to signal
   there's more. */
.similar-posts__row {
  display: flex;
  gap: 0.75rem;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  -webkit-overflow-scrolling: touch;
  padding-bottom: 0.5rem;
}

.similar-posts__item {
  flex: 0 0 auto;
  width: 15rem;
  scroll-snap-align: start;

  @include media-breakpoint-down(sm) {
    width: 72vw;
  }
}
</style>
