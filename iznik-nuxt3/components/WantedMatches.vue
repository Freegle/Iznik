<template>
  <!-- Renders only when there's at least one matching offer. This panel never
       gates the compose flow: it emits nothing that blocks navigation, and its
       links open in a new tab so the draft is never lost. -->
  <section v-if="!dismissed && ids.length" class="wanted-matches">
    <div class="wanted-matches__head">
      <h3 class="wanted-matches__heading">
        Good news - people are offering these near you right now
      </h3>
      <b-button
        variant="link"
        size="sm"
        class="wanted-matches__dismiss"
        @click="dismissed = true"
      >
        Not what I'm looking for - keep posting
      </b-button>
    </div>
    <div class="wanted-matches__row">
      <div v-for="mid in ids" :key="mid" class="wanted-matches__item">
        <MessageSummary :id="mid" @expand="openInNewTab(mid)" />
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useMessageStore } from '~/stores/message'
import MessageSummary from '~/components/MessageSummary'

const props = defineProps({
  // Item text of the wanted being composed.
  query: { type: String, default: '' },
  // The poster's chosen location.
  lat: { type: Number, default: 0 },
  lng: { type: Number, default: 0 },
})

const SOURCE = 'wanted_match'

const messageStore = useMessageStore()

const ids = ref([])
const dismissed = ref(false)

async function load() {
  const q = (props.query || '').trim()
  if (!q || (!props.lat && !props.lng)) {
    ids.value = []
    return
  }

  let results
  try {
    results = await messageStore.matches(q, props.lat, props.lng, 6)
  } catch (e) {
    ids.value = []
    return
  }
  if (!results || !results.length) {
    ids.value = []
    return
  }

  const found = results.map((r) => r.id)
  await Promise.all(found.map((id) => messageStore.fetch(id).catch(() => null)))
  ids.value = found.filter((id) => messageStore.list[id])

  if (ids.value.length) {
    messageStore.markSeen(ids.value, SOURCE)
  }
}

// Re-run whenever the item text or chosen location changes (e.g. the poster
// edits their postcode). Fires immediately for the initial load.
watch(() => [props.query, props.lat, props.lng], load, { immediate: true })

function openInNewTab(mid) {
  // Open in a new tab so the in-progress wanted draft in this tab is preserved.
  window.open('/message/' + mid + '?src=' + SOURCE, '_blank', 'noopener')
}
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.wanted-matches {
  margin: 1rem 0;
  padding: 0.75rem;
  border: 1px solid $color-gray--light;
  border-radius: var(--radius-md, 0.375rem);
  background: $color-white;
}

.wanted-matches__head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

.wanted-matches__heading {
  font-size: 1.05rem;
  font-weight: 700;
  margin: 0;
}

.wanted-matches__dismiss {
  padding: 0;
}

.wanted-matches__row {
  display: flex;
  gap: 0.75rem;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  -webkit-overflow-scrolling: touch;
  padding: 0.5rem 0 0;
}

.wanted-matches__item {
  flex: 0 0 auto;
  width: 15rem;
  scroll-snap-align: start;

  @include media-breakpoint-down(sm) {
    width: 72vw;
  }
}
</style>
