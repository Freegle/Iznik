<template>
  <!-- Renders nothing at all (no heading, no reserved space) unless we have at
       least a few genuine matches. In the sidebar it lazy-loads when it scrolls
       into view; in a modal it loads eagerly because it's shown deliberately. -->
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
          <MessageSummary :id="mid" @expand="open(mid)" />
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
import MessageSummary from '~/components/MessageSummary'

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

const MIN_RESULTS = 3
const SOURCE = 'similar_posts'

const messageStore = useMessageStore()
const { myid } = useMe()

const ids = ref([])
// The observe-visibility directive has no "once" option and re-fires on every
// intersection change, so guard against re-entry / repeated loads ourselves.
let loaded = false

// 10% deterministic holdout: logged-in users whose id ends in 0 never see the
// recommendations (and they never fetch), giving a clean control group for
// measuring whether they're worth showing. Anonymous users always see them and
// are excluded from the cohort analysis.
const inHoldout = computed(() => !!myid.value && myid.value % 10 === 0)

const show = computed(() => ids.value.length >= MIN_RESULTS)

async function load() {
  if (inHoldout.value || loaded) {
    return
  }
  loaded = true

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
  let renderable = found.filter((id) => messageStore.list[id])
  if (props.max > 0) {
    renderable = renderable.slice(0, props.max)
  }
  ids.value = renderable

  if (ids.value.length >= MIN_RESULTS) {
    // Count the impression once (source-tagged so it's attributable). Fire and
    // forget, but swallow rejections so they never surface as unhandled.
    messageStore.markSeen(ids.value, SOURCE).catch(() => {})
  } else {
    ids.value = []
  }
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
</style>
