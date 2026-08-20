<template>
  <!-- Compact recommendation card. The photo fills the card and the details are
       overlaid on it (type badge + time along the top, title + location along the
       bottom on a gradient), matching the post header — rather than a wide
       photo-beside-text row, which squeezes badly in the 300px sidebar. -->
  <div v-if="message" class="spc" @click="$emit('click')">
    <div class="spc__photo">
      <OurUploadedImage
        v-if="message.attachments?.[0]?.ouruid"
        :src="message.attachments[0].ouruid"
        :modifiers="message.attachments[0].externalmods"
        :alt="photoAlt"
        class="spc__img"
        :width="400"
        fit="cover"
        sizes="300px"
      />
      <NuxtPicture
        v-else-if="message.attachments?.[0]?.externaluid"
        format="webp"
        provider="uploadcare"
        :src="message.attachments[0].externaluid"
        :modifiers="message.attachments[0].externalmods"
        :alt="photoAlt"
        class="spc__img"
        :width="400"
        fit="cover"
        sizes="300px"
        loading="lazy"
      />
      <ProxyImage
        v-else-if="message.attachments?.[0]?.path"
        class-name="spc__img"
        :alt="photoAlt"
        :src="message.attachments[0].path"
        :width="400"
        fit="cover"
        sizes="300px"
      />
      <MessagePhotoPlaceholder
        v-else
        placeholder-class="spc__placeholder"
        :icon="categoryIcon"
      />

      <!-- Overlaid info, as in the post header. -->
      <div class="spc__toprow">
        <MessageTag :id="id" :inline="true" class="spc__tag" />
        <span class="spc__time"
          ><v-icon icon="clock" />{{ timeAgo || '...' }}</span
        >
      </div>
      <div class="spc__caption">
        <div class="spc__subject">{{ subjectItemName }}</div>
        <div v-if="subjectLocation" class="spc__loc">
          <v-icon icon="map-marker-alt" />{{ subjectLocation }}
        </div>
      </div>
      <span v-if="attachmentCount > 1" class="spc__count">
        {{ attachmentCount }} <v-icon icon="camera" />
      </span>
    </div>
  </div>
</template>

<script setup>
import { toRef, computed } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useMessageDisplay } from '~/composables/useMessageDisplay'

const props = defineProps({
  id: { type: [Number, String], required: true },
})
defineEmits(['click'])

const idRef = toRef(props, 'id')
const messageStore = useMessageStore()
const message = computed(() => messageStore.byId(props.id))

const {
  subjectItemName,
  subjectLocation,
  timeAgo,
  attachmentCount,
  categoryIcon,
} = useMessageDisplay(idRef)

const photoAlt = computed(() => subjectItemName.value || 'Item photo')
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.spc {
  cursor: pointer;
  position: relative;
  border-radius: 6px;
  overflow: hidden;
  background: $color-gray--lighter;
  transition: box-shadow 0.1s ease-in-out;

  &:hover {
    box-shadow: 0 1px 8px rgba(0, 0, 0, 0.25);
  }
}

.spc__photo {
  position: relative;
  width: 100%;
  height: 150px;

  :deep(.spc__img),
  :deep(img) {
    width: 100%;
    height: 150px;
    object-fit: cover;
    display: block;
  }

  :deep(.spc__placeholder) {
    width: 100%;
    height: 150px;
  }
}

/* Type badge (left) + age (right), floated over the top of the photo. */
.spc__toprow {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 0.35rem;
  z-index: 3;
}

:deep(.spc__tag .tagbadge) {
  position: relative;
  left: auto;
  top: auto;
}

.spc__time {
  display: flex;
  align-items: center;
  gap: 3px;
  color: $color-white;
  font-size: 0.72rem;
  background: rgba(0, 0, 0, 0.5);
  padding: 1px 7px;
  border-radius: 999px;
}

/* Title + location on a bottom gradient, as in the post header. */
.spc__caption {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 1.75rem 0.5rem 0.5rem;
  background: linear-gradient(
    to top,
    rgba(0, 0, 0, 0.85) 0%,
    rgba(0, 0, 0, 0.7) 30%,
    rgba(0, 0, 0, 0.4) 60%,
    rgba(0, 0, 0, 0) 100%
  );
  color: $color-white;
  z-index: 2;
}

.spc__subject {
  font-weight: 700;
  font-size: 0.9rem;
  line-height: 1.2;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.spc__loc {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 0.72rem;
  opacity: 0.9;
  margin-top: 2px;
}

.spc__count {
  position: absolute;
  bottom: 4px;
  right: 4px;
  z-index: 3;
  background: rgba(0, 0, 0, 0.6);
  color: $color-white;
  font-size: 0.7rem;
  padding: 0 5px;
  border-radius: 3px;
}
</style>
