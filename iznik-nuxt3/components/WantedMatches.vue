<template>
  <!-- Renders only when there's at least one matching offer. This panel never
       gates the compose flow: it emits nothing that blocks navigation, and its
       links open in a new tab so the draft is never lost. -->
  <section v-if="!dismissed && ids.length" class="wanted-matches">
    <div class="wanted-matches__head">
      <h3 class="wanted-matches__heading">
        People are offering these near you right now
      </h3>
      <b-button
        variant="link"
        size="sm"
        class="wanted-matches__dismiss"
        @click="dismissed = true"
      >
        Not what I'm looking for
      </b-button>
    </div>
    <div class="wanted-matches__row">
      <div v-for="mid in ids" :key="mid" class="wanted-matches__item">
        <MessageSummary :id="mid" @expand="openInNewTab(mid)">
          <template #footer>
            <div class="wanted-matches__actions">
              <b-button
                variant="primary"
                size="sm"
                class="wanted-matches__view"
                @click.stop="openInNewTab(mid)"
              >
                View post
              </b-button>
            </div>
          </template>
        </MessageSummary>
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
// Monotonic token so a slow response from a superseded query/location can't
// overwrite the results of a newer one (out-of-order response guard).
let loadToken = 0

async function load() {
  const token = ++loadToken
  const q = (props.query || '').trim()
  if (!q || (!props.lat && !props.lng)) {
    ids.value = []
    return
  }

  let results
  try {
    results = await messageStore.matches(q, props.lat, props.lng, 6)
  } catch (e) {
    if (token === loadToken) ids.value = []
    return
  }
  if (token !== loadToken) {
    return // a newer load() started while we awaited — discard this stale result
  }
  if (!results || !results.length) {
    ids.value = []
    return
  }

  const found = results.map((r) => r.id)
  await Promise.all(found.map((id) => messageStore.fetch(id).catch(() => null)))
  if (token !== loadToken) {
    return
  }
  ids.value = found.filter((id) => messageStore.list[id])

  if (ids.value.length) {
    // Fire and forget, but swallow rejections so they never surface as unhandled.
    messageStore.markSeen(ids.value, SOURCE).catch(() => {})
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
  align-items: stretch;
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
  // Stack the card and its "View post" button, and let the card grow so every
  // card is the same height (the row stretches items to the tallest) with the
  // button pinned to the bottom of each.
  display: flex;
  flex-direction: column;

  // The card fills the item (rows stretch items to the tallest), and the text
  // area grows so the "View post" button pins to the bottom — giving every card
  // the same height with the button on the same line across the row.
  :deep(.message-summary-mobile) {
    flex: 1 1 auto;
  }

  :deep(.content-section) {
    flex: 1 1 auto;
  }

  @include media-breakpoint-down(sm) {
    width: 72vw;
  }

  // MessageSummary is viewport-responsive, not container-responsive: at lg+ it
  // switches to a horizontal list-row layout (a fixed 200px square photo with the
  // text beside it) which assumes a full-width row. In a 15rem tile that leaves
  // ~40px for the text, so subject, location and description all collapse to zero
  // width and the card renders as a bare photo. Keep the vertical tile layout it
  // already uses at md, at every width.
  @include media-breakpoint-up(lg) {
    :deep(.message-summary-mobile) {
      flex-direction: column;
      max-height: none;
    }

    :deep(.photo-area) {
      width: 100%;
      height: 0;
      padding-bottom: 75%;
    }
  }

  // The header puts the type tag beside the title, which costs ~70px of a tile's
  // width. Stack it above so the title gets the full width.
  :deep(.content-header) {
    grid-template-columns: 1fr;
  }

  :deep(.content-tag) {
    justify-self: start;
  }

  // A single-line ellipsis suits a full-width row, but in a tile it clips the
  // subject to a word or two. Let it wrap to two lines instead.
  :deep(.content-subject) {
    white-space: normal;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
}

// The footer slot sits inside the card; centre the button and let it size to its
// label rather than stretch full width.
.wanted-matches__actions {
  display: flex;
  justify-content: center;
  padding: 0.25rem 0.5rem 0.75rem;
}

.wanted-matches__view {
  flex: 0 0 auto;
}
</style>
