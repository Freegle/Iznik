<template>
  <div class="mobile-app">
    <slot />
    <AdBanner />
  </div>
</template>

<script setup>
import AdBanner from '~/components/AdBanner.vue'
</script>

<style scoped lang="scss">
.mobile-app {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  min-height: 100dvh;
  background: #ffffff;
}
</style>
