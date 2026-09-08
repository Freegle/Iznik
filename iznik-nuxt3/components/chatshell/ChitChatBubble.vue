<template>
  <div class="cc-row" :class="{ mine, reply: !!quote }" :data-testid="'cc-' + item.id">
    <ProfileImage v-if="!mine" :image="item.profile?.paththumb || item.profile?.url" :name="item.displayname" class="cc-avatar" is-thumbnail size="sm" />
    <div class="cc-bubble">
      <div v-if="!mine" class="cc-name" :style="{ color: colour }">{{ item.displayname }}</div>
      <button v-if="quote" type="button" class="cc-quote" :data-testid="'cc-quote-' + item.id" @click="$emit('jump', quote.id)">
        <span class="cc-quote-name">{{ quote.displayname }}</span>
        <span class="cc-quote-text">{{ excerpt(quote.message) }}</span>
      </button>
      <div v-if="item.image" class="cc-image">
        <OurUploadedImage v-if="item.image.ouruid" :src="item.image.ouruid" :modifiers="item.image.externalmods" alt="" width="240" />
        <ProxyImage v-else-if="item.image.paththumb" :src="item.image.paththumb" alt="" :width="240" :height="180" sizes="240px" />
      </div>
      <div class="cc-text">{{ item.message }}</div>
      <div class="cc-foot">
        <span class="cc-time">{{ time }}</span>
        <button type="button" class="cc-love" :class="{ loved: item.loved }" :aria-label="item.loved ? 'Unlove' : 'Love'" :data-testid="'cc-love-' + item.id" @click="$emit('love', item)">
          ❤<span v-if="item.loves"> {{ item.loves }}</span>
        </button>
        <button type="button" class="cc-action" :data-testid="'cc-reply-' + item.id" @click="$emit('reply', item)">Reply</button>
        <b-dropdown variant="link" no-caret toggle-class="cc-more" size="sm" right>
          <template #button-content><span aria-label="More">⋯</span></template>
          <b-dropdown-item @click="$emit('report', item)">Report</b-dropdown-item>
          <b-dropdown-item v-if="!quote" @click="$emit('hide', item)">Hide</b-dropdown-item>
        </b-dropdown>
      </div>
    </div>
  </div>
</template>
<script setup>
import { computed } from '#imports'
import ProfileImage from '~/components/ProfileImage.vue'
import ProxyImage from '~/components/ProxyImage.vue'
import OurUploadedImage from '~/components/OurUploadedImage.vue'

// One chit-chat message as a bubble. A reply carries a quote of what it answers, as
// WhatsApp does; tapping the quote jumps to it. Love is the ❤ reaction.
const props = defineProps({
  item: { type: Object, required: true },
  quote: { type: Object, required: false, default: null },
  mine: { type: Boolean, required: false, default: false },
})
defineEmits(['love', 'reply', 'report', 'hide', 'jump'])

const COLOURS = ['#1e7c4f', '#8e44ad', '#c0392b', '#2471a3', '#b9770e', '#117a65', '#884ea0', '#a04000']
const colour = computed(() => COLOURS[(props.item.userid || 0) % COLOURS.length])
const time = computed(() => {
  const t = props.item.timestamp || props.item.added
  if (!t) return ''
  return new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
})
function excerpt(s) {
  const t = String(s || '').replace(/\s+/g, ' ')
  return t.length > 70 ? t.slice(0, 70) + '…' : t
}
</script>
<style scoped lang="scss">
.cc-row {
  display: flex;
  gap: 0.4rem;
  padding: 0.15rem 0.6rem;
  align-items: flex-end;

  &.mine {
    justify-content: flex-end;
  }
}

.cc-avatar {
  width: 28px;
  height: 28px;
  flex: 0 0 auto;
}

.cc-bubble {
  max-width: 84%;
  background: #fff;
  border-radius: 14px;
  border-top-left-radius: 4px;
  padding: 0.4rem 0.6rem 0.25rem;
  box-shadow: 0 1px 1px rgba(0, 0, 0, 0.06);

  .mine & {
    background: #d9f5e4;
    border-top-left-radius: 14px;
    border-top-right-radius: 4px;
  }
}

.cc-name {
  font-size: 0.78rem;
  font-weight: 600;
}

.cc-quote {
  display: block;
  width: 100%;
  text-align: left;
  border: 0;
  border-left: 3px solid #1e7c4f;
  background: rgba(0, 0, 0, 0.04);
  border-radius: 6px;
  padding: 0.25rem 0.5rem;
  margin: 0.2rem 0;
  font-size: 0.8rem;
}

.cc-quote-name {
  display: block;
  font-weight: 600;
  color: #1e7c4f;
}

.cc-quote-text {
  color: #4a5561;
}

.cc-image {
  margin: 0.2rem 0;

  :deep(img) {
    max-width: 100%;
    border-radius: 8px;
  }
}

.cc-text {
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 0.97rem;
}

.cc-foot {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  justify-content: flex-end;
  margin-top: 0.15rem;
}

.cc-time {
  font-size: 0.68rem;
  color: #7a838c;
}

.cc-love,
.cc-action {
  border: 0;
  background: transparent;
  font-size: 0.8rem;
  color: #5a6470;
  padding: 0.1rem 0.3rem;
}

.cc-love.loved {
  color: #c0392b;
}

:deep(.cc-more) {
  color: #5a6470;
  padding: 0 0.3rem;
  line-height: 1;
}
</style>
