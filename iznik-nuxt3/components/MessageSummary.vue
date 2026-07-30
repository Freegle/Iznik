<template>
  <div
    v-if="message"
    class="message-summary-mobile"
    :class="{
      offer: isOffer,
      wanted: isWanted,
      freegled: message.successful && showFreegled,
      promisedfade: showPromised && message.promised && !message.promisedtome,
      'mobile-landscape': isMobileLandscape,
    }"
    @click="expand"
  >
    <!-- Status overlay images - outside photo-area to avoid fade filter -->
    <b-img
      v-if="message.successful"
      lazy
      src="/freegled.jpg"
      class="status-overlay-image"
      :alt="successfulText"
      width="421"
      height="90"
    />
    <b-img
      v-else-if="message.promised && showPromised"
      lazy
      src="/promised.jpg"
      class="status-overlay-image"
      alt="Promised"
      width="421"
      height="90"
    />

    <!-- Photo area with overlay -->
    <div class="photo-area">
      <!-- Actual photo -->
      <div v-if="gotAttachments" class="photo-container">
        <!-- Responsive image sizes: 200px display (400px for 2x retina), 100px landscape (200px retina) -->
        <OurUploadedImage
          v-if="message.attachments[0]?.ouruid"
          :src="message.attachments[0].ouruid"
          :modifiers="message.attachments[0].externalmods"
          :alt="photoAlt"
          class="photo-image"
          :width="400"
          fit="inside"
          sizes="(orientation: landscape) and (max-width: 991px) 100px, 200px"
          :preload="preload"
        />
        <NuxtPicture
          v-else-if="message.attachments[0]?.externaluid"
          format="webp"
          provider="uploadcare"
          :src="message.attachments[0].externaluid"
          :modifiers="message.attachments[0].externalmods"
          :alt="photoAlt"
          class="photo-image"
          :width="400"
          fit="inside"
          sizes="(orientation: landscape) and (max-width: 991px) 100px, 200px"
          :preload="preload"
          :loading="preload ? 'eager' : 'lazy'"
        />
        <ProxyImage
          v-else-if="message.attachments[0]?.path"
          class-name="photo-image"
          :alt="photoAlt"
          :src="message.attachments[0].path"
          :width="400"
          fit="inside"
          sizes="(orientation: landscape) and (max-width: 991px) 100px, 200px"
          :preload="preload"
        />
        <!-- Multi-photo indicator -->
        <div v-if="attachmentCount > 1" class="photo-count">
          {{ attachmentCount }} <v-icon icon="camera" />
        </div>
      </div>

      <!-- No photo - show placeholder -->
      <div v-else class="photo-container">
        <MessagePhotoPlaceholder
          :placeholder-class="placeholderClass"
          :icon="categoryIcon"
        />
      </div>

      <!-- Pinned (paid bulk-offer clearance) indicator. On the photo so it shows in every
           layout - mobile portrait, tablet/desktop and mobile-landscape. -->
      <div v-if="isPinned" class="pinned-badge">
        <v-icon icon="thumbtack" class="pinned-icon" />Pinned
      </div>

      <!-- Title/info overlay at bottom of photo (mobile only) -->
      <div class="title-overlay title-overlay-mobile">
        <div class="info-row">
          <MessageTag :id="id" :inline="true" class="title-tag ps-1 pe-1" />
          <div class="info-icons">
            <span v-if="hasLocation" class="location">
              <v-icon icon="map-marker-alt" />{{ locationText }}
            </span>
            <span class="time"
              ><v-icon icon="clock" />{{ timeAgo || '...' }}</span
            >
          </div>
        </div>
        <div class="title-row">
          <span class="title-subject">{{ strippedSubject }}</span>
          <b-badge v-if="bulkCount" variant="info" class="ms-1 bulk-badge"
            >{{ bulkAvailable }} available</b-badge
          >
        </div>
      </div>
    </div>

    <!-- Content section for tablet/desktop - shows below photo -->
    <div class="content-section">
      <div class="content-header">
        <MessageTag :id="id" :inline="true" class="content-tag" />
        <div class="content-title-location">
          <span class="content-subject">{{ subjectItemName }}</span>
          <b-badge v-if="bulkCount" variant="info" class="ms-1 bulk-badge"
            >{{ bulkAvailable }} available</b-badge
          >
          <span v-if="subjectLocation" class="content-location">
            {{ subjectLocation }}
          </span>
        </div>
      </div>
      <div class="content-description">
        {{ descriptionText || 'Click to see more details.' }}
      </div>
      <div class="content-meta">
        <span v-if="hasLocation" class="meta-location">
          <v-icon icon="map-marker-alt" />{{ locationText }}
        </span>
        <span class="meta-time">
          <v-icon icon="clock" />{{ displayTimeAgo || '...' }}
        </span>
      </div>
    </div>
    <!-- Empty by default; callers (e.g. WantedMatches) can put actions inside the card. -->
    <slot name="footer" />
  </div>
</template>

<script setup>
import { computed, toRef } from 'vue'
import { useMessageDisplay } from '~/composables/useMessageDisplay'
import { useMiscStore } from '~/stores/misc'
import { useOrientation } from '~/composables/useOrientation'
import { action } from '~/composables/useClientLog'
import MessageTag from '~/components/MessageTag'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  showFreegled: {
    type: Boolean,
    default: true,
  },
  showPromised: {
    type: Boolean,
    default: true,
  },
  preload: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['expand'])

// Use shared composable
const idRef = toRef(props, 'id')
const {
  message,
  strippedSubject,
  subjectItemName,
  subjectLocation,
  gotAttachments,
  attachmentCount,
  timeAgo,
  timeAgoExpanded,
  distanceText,
  distanceTextExpanded,
  isPinned,
  isOffer,
  isWanted,
  successfulText,
  placeholderClass,
  categoryIcon,
} = useMessageDisplay(idRef)

/* Name the item in the alt text rather than the literal "Item Photo" that used to be
on every photo on the site. */
const photoAlt = computed(() => subjectItemName.value || 'Item photo')

// Bulk offer ("clearance") indicator. bulkCount only gates the badge (is this a
// bulk offer?); the badge itself shows the TOTAL quantity available, matching the
// post's "N available" (message.availablenow), not the number of catalogue rows.
const bulkCount = computed(() => message.value?.bulkitems?.length || 0)
const bulkAvailable = computed(() => {
  const m = message.value
  if (!m?.bulkitems?.length) return 0
  // Prefer availablenow (sum of quantities across items still available); fall
  // back to summing the catalogue quantities if the list payload omits it.
  return (
    m.availablenow ||
    m.bulkitems.reduce((sum, i) => sum + (parseInt(i.quantity, 10) || 0), 0)
  )
})

const miscStore = useMiscStore()
const { isLandscape } = useOrientation()

const isLgPlus = computed(() => {
  return ['lg', 'xl', 'xxl'].includes(miscStore.breakpoint)
})

const isMobileLandscape = computed(() => {
  return isLandscape.value && ['xs', 'sm', 'md'].includes(miscStore.breakpoint)
})

// Truncated description for tablet/desktop view
const descriptionText = computed(() => {
  const text = message.value?.textbody
  if (!text || text === 'null') return null
  // On lg+ let CSS line-clamp handle truncation for cleaner display
  if (isLgPlus.value) return text
  const maxLen = 120
  if (text.length <= maxLen) return text
  return text.substring(0, maxLen).trim() + '...'
})

const locationText = computed(() => {
  // Show area name if available, otherwise distance
  if (message.value?.area) {
    return message.value.area
  }
  return isLgPlus.value ? distanceTextExpanded.value : distanceText.value
})

const displayTimeAgo = computed(() => {
  return isLgPlus.value ? timeAgoExpanded.value : timeAgo.value
})

const hasLocation = computed(() => {
  const loc = locationText.value
  // Hide if empty, unknown, or just whitespace
  if (!loc) return false
  const lower = loc.toLowerCase().trim()
  if (!lower) return false
  if (lower === 'unknown' || lower === 'unknown location') return false
  return true
})

function expand(e) {
  // Log the click for debugging mobile navigation issues.
  action('message_card_click', {
    message_id: props.id,
    is_successful: !!message.value?.successful,
    breakpoint: miscStore.breakpoint,
    click_x: e?.clientX,
    click_y: e?.clientY,
    viewport_width: window.innerWidth,
    viewport_height: window.innerHeight,
  })

  if (!message.value?.successful) {
    emit('expand')

    if (e) {
      e.preventDefault()
      e.stopPropagation()
    }
  }
}
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';
@import 'assets/css/_feed-card.scss';

/* Use very specific selectors to override MessageList.vue's deep selectors */
.message-summary-mobile {
  position: relative;
  overflow: hidden;
  box-shadow: var(--shadow-sm, 0 1px 2px rgba(0, 0, 0, 0.05));
  border-radius: var(--radius-md, 0.375rem);
  cursor: pointer;
  background: $color-white;
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;

  @include media-breakpoint-up(lg) {
    display: flex;
    flex-direction: row;
    align-items: stretch;
    /* The square photo sets the row height - see assets/css/_feed-card.scss. */
    max-height: var(--summary-row-size, #{$feed-card-max});
    border: 1px solid $color-gray--light;
    box-shadow: var(
      --shadow-md,
      0 4px 6px -1px rgba(0, 0, 0, 0.1),
      0 2px 4px -2px rgba(0, 0, 0, 0.1)
    );
  }

  /* Mobile landscape: use list view layout */
  &.mobile-landscape {
    flex-direction: row;
    align-items: stretch;
    border: 1px solid $color-gray--light;
    box-shadow: var(
      --shadow-md,
      0 4px 6px -1px rgba(0, 0, 0, 0.1),
      0 2px 4px -2px rgba(0, 0, 0, 0.1)
    );
  }
}

.photo-area {
  position: relative;
  width: 100%;
  height: 0;
  padding-bottom: 115%;
  background: $color-gray--light;
  overflow: hidden;
  flex-shrink: 0;

  .freegled &,
  .promisedfade & {
    filter: contrast(50%);
  }

  /* Smaller photo on tablet/desktop to make room for text */
  @include media-breakpoint-up(md) {
    padding-bottom: 75%;
  }

  /* Horizontal layout on lg+ - square photo sized from the viewport height so that a
     short screen still shows a useful number of posts. */
  @include media-breakpoint-up(lg) {
    width: var(--summary-row-size, #{$feed-card-max});
    height: var(--summary-row-size, #{$feed-card-max});
    padding-bottom: 0;
    flex-shrink: 0;
  }

  /* Mobile landscape: small thumbnail on left */
  .mobile-landscape & {
    width: 20%;
    height: auto;
    padding-bottom: 0;
    aspect-ratio: 1;
    flex-shrink: 0;
  }
}

.photo-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: $color-gray--light;

  @include media-breakpoint-up(lg) {
    position: relative;
    width: 100%;
    height: 100%;
  }

  .mobile-landscape & {
    position: relative;
    width: 100%;
    height: 100%;
  }
}

:deep(.photo-image),
:deep(picture),
:deep(picture img),
:deep(.photo-image img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  position: absolute;
  top: 0;
  left: 0;
}

.photo-count {
  position: absolute;
  top: 8px;
  right: 8px;
  background: $color-black-opacity-60;
  color: $color-white;
  padding: 0.2rem 0.5rem;
  font-size: 0.7rem;
  border-radius: var(--radius-sm, 0.375rem);
  z-index: 5;
  height: auto;
}

/* Pinned (paid clearance) badge - top-left of the photo, above the image but below the
   "freegled"/"promised" stamp. Solid gold reads as "featured" and stays distinct from
   the green OFFER / blue WANTED tags. Deliberately uses only background/shadow (no
   filters or transforms) so it renders identically with GPU acceleration disabled. */
.pinned-badge {
  position: absolute;
  top: 8px;
  left: 8px;
  z-index: 6;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.22rem 0.5rem;
  font-size: 0.7rem;
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: $color-white;
  background: $color-gold;
  border-radius: var(--radius-sm, 0.375rem);
  box-shadow: 0 1px 3px $color-black-opacity-50;
  text-shadow: 0 1px 1px $color-black-opacity-30;
  pointer-events: none;

  .pinned-icon {
    font-size: 0.72rem;
  }
}

.status-overlay-image {
  position: absolute;
  z-index: 20;
  transform: rotate(15deg) translate(-50%, -50%);
  top: 45%;
  left: 50%;
  width: 50%;
  max-width: 120px;
  pointer-events: none;
  height: auto;

  /* In list view (lg+) the photo is a square on the left whose side varies with the
     viewport height, so centre the stamp on that square rather than on a fixed 200px. */
  @include media-breakpoint-up(lg) {
    left: calc(var(--summary-row-size, #{$feed-card-max}) / 2);
    top: calc(var(--summary-row-size, #{$feed-card-max}) / 2);
    width: calc(var(--summary-row-size, #{$feed-card-max}) * 0.6);
    max-width: 120px;
  }

  /* Mobile landscape: photo is 20% width */
  .mobile-landscape & {
    left: 10%; /* Center of 20% photo area */
    width: 15%;
    max-width: none;
  }
}

/* Mobile overlay - hidden on tablet+ and mobile landscape */
.title-overlay-mobile {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  width: 100%;
  height: auto;
  padding: 2rem 0.5rem 0.5rem;
  background: linear-gradient(
    to top,
    rgba(0, 0, 0, 0.9) 0%,
    rgba(0, 0, 0, 0.88) 8%,
    rgba(0, 0, 0, 0.84) 16%,
    rgba(0, 0, 0, 0.78) 24%,
    rgba(0, 0, 0, 0.68) 32%,
    rgba(0, 0, 0, 0.55) 42%,
    rgba(0, 0, 0, 0.4) 52%,
    rgba(0, 0, 0, 0.26) 62%,
    rgba(0, 0, 0, 0.15) 72%,
    rgba(0, 0, 0, 0.08) 82%,
    rgba(0, 0, 0, 0.03) 92%,
    rgba(0, 0, 0, 0) 100%
  );
  color: $color-white;
  z-index: 3;
  overflow: hidden;

  @include media-breakpoint-up(md) {
    display: none;
  }

  .mobile-landscape & {
    display: none;
  }
}

.info-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.25rem;
  height: auto;
}

.title-tag {
  flex-shrink: 0;
  font-size: 0.8rem;
  height: auto;
}

:deep(.title-tag .tagbadge) {
  position: relative;
  left: auto;
  top: auto;
  font-size: 0.8rem;
}

.info-icons {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.7rem;
  opacity: 0.9;
}

.location {
  display: flex;
  align-items: center;
  gap: 0.2rem;
  height: auto;
}

.time {
  display: flex;
  align-items: center;
  gap: 0.2rem;
  height: auto;
}

.title-row {
  height: auto;
  width: 100%;
  min-width: 0;
}

.title-subject {
  display: block;
  width: 100%;
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  height: auto;
}

/* Content section - hidden on mobile portrait, visible on tablet+ and mobile landscape */
.content-section {
  display: none;
  padding: 0.75rem;
  background: $color-white;

  @include media-breakpoint-up(md) {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    border: 1px solid $color-gray--light;
    border-top: none;
    box-shadow: var(
      --shadow-md,
      0 4px 6px -1px rgba(0, 0, 0, 0.1),
      0 2px 4px -2px rgba(0, 0, 0, 0.1)
    );
  }

  @include media-breakpoint-up(lg) {
    /* Padding shrinks with the row so that the title, location and meta line still fit
       beside a small photo; at the 200px maximum it lands back on 1rem 1.5rem. */
    padding: clamp(
        0.25rem,
        calc((var(--summary-row-size, #{$feed-card-max}) - 64px) / 6),
        1rem
      )
      clamp(
        0.75rem,
        calc(var(--summary-row-size, #{$feed-card-max}) * 0.12),
        1.5rem
      );
    border: none;
    border-left: 1px solid $color-gray--light;
    box-shadow: none;
    overflow: hidden;
    flex: 1;
    min-width: 0;
  }

  .mobile-landscape & {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    padding: 0.75rem 1rem;
    justify-content: center;
    border: none;
    border-left: 1px solid $color-gray--light;
    box-shadow: none;
  }
}

.content-header {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.5rem;
  margin-bottom: 0.35rem;
  align-items: center;

  /* Compact rows have no description underneath, so don't reserve the gap for one. */
  @include media-breakpoint-up(lg) {
    @media (max-height: $feed-card-compact-max) {
      margin-bottom: 0;
    }
  }
}

.content-tag {
  align-self: center;
  white-space: nowrap;
}

:deep(.content-tag) {
  position: static !important;
  display: inline-flex !important;
  white-space: nowrap !important;
}

:deep(.content-tag .tagbadge),
:deep(.content-tag.tagbadge) {
  position: static !important;
  left: auto !important;
  top: auto !important;
  font-size: 0.8rem;
  padding: 0.35rem 0.7rem;
  white-space: nowrap !important;
  max-width: none !important;
  text-wrap: nowrap !important;
}

.content-title-location {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.content-subject {
  font-size: 0.95rem;
  font-weight: 600;
  color: $color-gray--darker;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.content-location {
  font-size: 0.75rem;
  font-weight: 500;
  color: $color-gray--darker;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Bulk-offer count pill. Uses the standard "N available" pill styling
   (variant="info", as on the post); the only layout fix is align-self so it hugs
   its own text instead of stretching to the flex-column's full width. */
.bulk-badge {
  align-self: flex-start;
}

.content-description {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--color-gray-600);
  line-height: 1.35;
  flex: 1;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  margin-bottom: 0.35rem;

  /* In list layout (lg+), fill available space with fade-out overflow */
  @include media-breakpoint-up(lg) {
    display: block;
    -webkit-line-clamp: unset;
    -webkit-box-orient: unset;
    position: relative;
    min-height: 0;

    &::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 1.5em;
      background: linear-gradient(transparent, $color-white);
      pointer-events: none;
    }
  }

  /* Only one line fits, so ellipsise it - the fade would just grey out the only line there
     is. Line clamping can't be used here because flex: 1 stretches the box past the line it
     is meant to cap, so this is a plain single-line ellipsis with the box sized to its
     content. See assets/css/_feed-card.scss for where these heights come from. */
  @include media-breakpoint-up(lg) {
    @media (max-height: $feed-card-oneline-max) {
      display: block;
      flex: 0 0 auto;
      white-space: nowrap;
      text-overflow: ellipsis;

      &::after {
        content: none;
      }
    }
  }

  /* Not even one line fits, so drop it. Subject, location, distance and age all stay. */
  @include media-breakpoint-up(lg) {
    @media (max-height: $feed-card-compact-max) {
      display: none;
    }
  }
}

.content-meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  font-size: 0.7rem;
  color: var(--color-gray-600);
  margin-top: auto;
}

.meta-location,
.meta-time {
  display: flex;
  align-items: center;
  gap: 0.2rem;
}
</style>
