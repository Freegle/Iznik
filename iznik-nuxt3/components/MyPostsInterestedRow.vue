<template>
  <div class="interested-row" :data-testid="'interested-row-' + id">
    <nuxt-link :to="'/message/' + id" class="interested-row__thumb">
      <OurUploadedImage
        v-if="message?.attachments?.[0]?.ouruid"
        :src="message.attachments[0].ouruid"
        :modifiers="message.attachments[0].externalmods"
        :alt="subjectItemName || 'Item photo'"
        :width="128"
        fit="cover"
      />
      <ProxyImage
        v-else-if="message?.attachments?.[0]?.path"
        :src="message.attachments[0].path"
        :alt="subjectItemName || 'Item photo'"
        :width="128"
        fit="cover"
      />
      <MessagePhotoPlaceholder v-else :placeholder-class="placeholderClass" />
    </nuxt-link>
    <nuxt-link :to="'/message/' + id" class="interested-row__text">
      <span class="interested-row__name">{{ subjectItemName }}</span>
      <span v-if="subjectLocation" class="interested-row__where">
        {{ subjectLocation }}
      </span>
    </nuxt-link>
    <div class="interested-row__buttons">
      <b-button
        variant="primary"
        size="sm"
        :to="'/message/' + id + '?reply=1'"
        :data-testid="'interested-reply-' + id"
      >
        Reply
      </b-button>
      <b-button
        variant="link"
        size="sm"
        class="interested-row__remove"
        :data-testid="'interested-remove-' + id"
        @click="$emit('remove', id)"
      >
        Remove
      </b-button>
    </div>
  </div>
</template>

<script setup>
import { toRef } from 'vue'
import { useMessageDisplay } from '~/composables/useMessageDisplay'
import OurUploadedImage from '~/components/OurUploadedImage'
import ProxyImage from '~/components/ProxyImage'
import MessagePhotoPlaceholder from '~/components/MessagePhotoPlaceholder'

// One saved post in My Posts: a small photo, the item and where it is, then Reply and Remove. Kept to
// one short line so a few saved posts do not push the member's own posts off the screen.
const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

defineEmits(['remove'])

const { message, subjectItemName, subjectLocation, placeholderClass } =
  useMessageDisplay(toRef(props, 'id'))
</script>

<style scoped lang="scss">
.interested-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem;
  margin-bottom: 0.5rem;
  background: $color-white;
  border: 1px solid $color-gray--light;
}

.interested-row__thumb {
  /* The placeholder is absolutely positioned and fills its nearest positioned parent. */
  position: relative;
  flex: 0 0 64px;
  width: 64px;
  height: 64px;
  overflow: hidden;

  :deep(img) {
    width: 64px;
    height: 64px;
    object-fit: cover;
  }
}

.interested-row__text {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  color: inherit;
  text-decoration: none;
}

.interested-row__name {
  font-weight: bold;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.interested-row__where {
  font-size: 0.85rem;
  color: $color-gray--dark;
}

.interested-row__buttons {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: stretch;
}

.interested-row__remove {
  color: $color-gray--dark;
}
</style>
