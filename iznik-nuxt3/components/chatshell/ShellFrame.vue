<template>
  <div class="shell-frame-outer">
    <div class="shell-frame" data-testid="chat-shell">
      <slot />
    </div>
    <div class="shell-footer d-none d-md-flex">
      <nuxt-link no-prefetch to="/privacy">Privacy</nuxt-link>
      <nuxt-link no-prefetch to="/terms">Terms</nuxt-link>
      <nuxt-link no-prefetch to="/about">About</nuxt-link>
      <nuxt-link no-prefetch to="/help">Help</nuxt-link>
    </div>
  </div>
</template>
<script setup>
// The phone. Full screen below 768px; from 768px a 390 by up-to-844 column, centred.
// The transform makes position:fixed children stay inside the column.
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/breakpoints';

.shell-frame-outer {
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
}

.shell-frame {
  width: 100%;
  height: 100vh;
  height: 100dvh;
  background: #fff;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transform: translateZ(0);
  position: relative;

  @include media-breakpoint-up(md) {
    width: 390px;
    height: min(844px, 92vh);
    margin-top: 3vh;
    border-radius: 36px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18), 0 0 0 10px #1f2427, 0 0 0 12px #3a4145;
  }
}

.shell-footer {
  gap: 1.5rem;
  padding: 1rem 0 2rem;
  font-size: 0.85rem;

  a {
    color: #5a6470;
  }
}
</style>
