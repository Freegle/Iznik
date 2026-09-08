<template>
  <div class="bubble-row" :class="'from-' + from" :data-testid="'bubble-' + from">
    <div class="bubble">
      <div v-if="name && from === 'them'" class="bubble-name" :style="{ color: colour }">
        {{ name }}
      </div>
      <div class="bubble-body"><slot /></div>
      <div v-if="time" class="bubble-time">{{ time }}</div>
    </div>
  </div>
</template>
<script setup>
// One message bubble. Freegle and other people sit on the left; the member on the right.
defineProps({
  from: { type: String, required: true }, // freegle | me | them
  time: { type: String, required: false, default: null },
  name: { type: String, required: false, default: null },
  colour: { type: String, required: false, default: '#1e7c4f' },
})
</script>
<style scoped lang="scss">
.bubble-row {
  display: flex;
  padding: 0.15rem 0.6rem;

  &.from-me {
    justify-content: flex-end;
  }
}

.bubble {
  max-width: 86%;
  border-radius: 14px;
  padding: 0.5rem 0.7rem;
  font-size: 0.97rem;
  line-height: 1.35;
  white-space: pre-wrap;
  word-break: break-word;
  box-shadow: 0 1px 1px rgba(0, 0, 0, 0.06);

  .from-freegle &,
  .from-them & {
    background: #fff;
    border-top-left-radius: 4px;
  }

  .from-me & {
    background: #d9f5e4;
    border-top-right-radius: 4px;
  }
}

.bubble-name {
  font-size: 0.8rem;
  font-weight: 600;
  margin-bottom: 0.1rem;
}

.bubble-time {
  font-size: 0.68rem;
  color: #7a838c;
  text-align: right;
  margin-top: 0.15rem;
}
</style>
