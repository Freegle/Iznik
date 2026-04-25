<template>
  <div
    class="chat-bubble"
    :class="{ 'chat-bubble--outgoing': outgoing }"
  >
    <p class="chat-bubble__text">{{ text }}</p>
    <span class="chat-bubble__time">{{ time }}</span>
  </div>
</template>

<script setup>
defineProps({
  text: {
    type: String,
    required: true,
  },
  time: {
    type: String,
    default: '',
  },
  outgoing: {
    type: Boolean,
    default: false,
  },
})
</script>

<style scoped lang="scss">
.chat-bubble {
  max-width: 80%;
  padding: 8px 12px;
  border-radius: 12px;
  background: var(--chat-incoming-bg, #f0f0f0);
  color: #1a1a1a;
  align-self: flex-start;
  word-wrap: break-word;
  overflow-wrap: break-word;

  &--outgoing {
    background: var(--chat-outgoing-bg, #dcf8c6);
    align-self: flex-end;
    margin-left: auto;
  }

  &__text {
    margin: 0;
    font-size: 15px;
    line-height: 1.4;
  }

  &__time {
    display: block;
    margin-top: 4px;
    font-size: 11px;
    color: #888888;
    text-align: right;
  }
}
</style>
