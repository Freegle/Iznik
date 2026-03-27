<template>
  <div class="feed-empty">
    <div class="feed-empty__icon" aria-hidden="true">
      &#x1F331;
    </div>
    <h2 class="feed-empty__heading">
      Nothing nearby yet
    </h2>
    <p class="feed-empty__subtitle">
      Be the first to offer something!
    </p>
  </div>
</template>

<script setup>
</script>

<style scoped lang="scss">
.feed-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex: 1;
  padding: 48px 24px;
  text-align: center;

  &__icon {
    font-size: 48px;
    margin-bottom: 16px;
  }

  &__heading {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 600;
    color: #333333;
  }

  &__subtitle {
    margin: 0;
    font-size: 14px;
    color: #777777;
  }
}
</style>
