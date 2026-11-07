<template>
  <div v-if="items.length" class="itemlist" data-testid="item-list">
    <h4 class="itemlist__heading">
      <v-icon icon="list" class="me-1" />
      {{ items.length }} things in this post
    </h4>
    <ul class="itemlist__list">
      <li
        v-for="item in items"
        :key="item.id"
        class="itemlist__item"
        :class="{ 'itemlist__item--gone': item.available === false }"
        :data-testid="'item-' + item.id"
      >
        <button
          type="button"
          class="itemlist__photo"
          :disabled="!thumb(item)"
          :title="thumb(item) ? 'Show photo' : ''"
          :aria-label="thumb(item) ? 'Show photo of ' + item.name : undefined"
          @click.stop.prevent="enlarge(item)"
        >
          <img
            v-if="thumb(item)"
            :src="thumb(item)"
            alt=""
            loading="lazy"
            @error="brokenImage"
          />
          <v-icon v-else icon="image" class="itemlist__nophoto" />
        </button>
        <span class="itemlist__name">
          {{ item.name }}
          <span v-if="item.quantity > 1" class="itemlist__qty"
            >× {{ item.quantity }}</span
          >
        </span>
        <b-badge
          v-if="item.available === false"
          variant="secondary"
          class="itemlist__gone"
          >Gone</b-badge
        >
        <b-button
          v-if="editable"
          size="sm"
          variant="white"
          class="itemlist__toggle"
          :disabled="busy === item.id"
          :data-testid="'item-toggle-' + item.id"
          @click.stop="toggle(item)"
        >
          {{ item.available === false ? 'Still here' : 'Mark gone' }}
        </b-button>
      </li>
    </ul>
    <MessagePhotosModal
      v-if="showPhotos"
      :attachments="photoAttachments"
      @hidden="showPhotos = false"
    />
  </div>
</template>
<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { useMessageStore } from '~/stores/message'

const MessagePhotosModal = defineAsyncComponent(
  () => import('~/components/MessagePhotosModal')
)

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  // The poster's own view: each item can be marked gone or back.
  editable: {
    type: Boolean,
    default: false,
  },
})

// all-gone: the poster has just marked the last item gone.
const emit = defineEmits(['all-gone'])

const messageStore = useMessageStore()
const message = computed(() => messageStore.byId(props.id))
const items = computed(() => message.value?.bulkitems || [])

function thumb(item) {
  const att = item.attachments && item.attachments[0]
  return att ? att.paththumb || att.path || null : null
}

function brokenImage(event) {
  event.target.src = '/placeholder.jpg'
}

const showPhotos = ref(false)
const photoAttachments = ref([])
function enlarge(item) {
  if (!item.attachments?.length) return
  photoAttachments.value = item.attachments
  showPhotos.value = true
}

const busy = ref(null)
async function toggle(item) {
  busy.value = item.id
  try {
    await messageStore.bulkItemAvailable(
      props.id,
      item.id,
      item.available === false
    )
    if (items.value.length && items.value.every((i) => i.available === false)) {
      emit('all-gone')
    }
  } finally {
    busy.value = null
  }
}
</script>
<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.itemlist {
  margin-top: 1rem;
}

.itemlist__heading {
  font-size: 1rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.itemlist__list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.itemlist__item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid $color-gray--light;
}

.itemlist__item--gone .itemlist__name {
  text-decoration: line-through;
  color: $color-gray--dark;
}

.itemlist__photo {
  flex: 0 0 48px;
  width: 48px;
  height: 48px;
  padding: 0;
  border: 1px solid $color-gray--light;
  border-radius: 4px;
  background: $color-white;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.itemlist__nophoto {
  color: $color-gray--normal;
}

.itemlist__name {
  flex: 1 1 auto;
  min-width: 0;
  overflow-wrap: anywhere;
}

.itemlist__qty {
  color: $color-gray--dark;
  white-space: nowrap;
}

.itemlist__toggle {
  flex: 0 0 auto;
}
</style>
