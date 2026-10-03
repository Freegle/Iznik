<template>
  <div v-if="!hideIntro">
    <p v-if="donated">
      You've donated before, so you know that
      <strong>Freegle</strong> is a charity that's free to use, but not
      free to run. If you're able to <strong>donate again</strong> that would be
      lovely.
    </p>
    <p v-else>
      <strong>Freegle</strong> is a charity that's free to use, but not
      free to run.
    </p>
    <p>
      This month we're trying to raise
      <strong>&pound;{{ target }}</strong> across the UK.
    </p>
    <p>
      If you can,
      <strong> please donate </strong>
      to keep us running.
    </p>
  </div>
</template>

<script setup>
defineProps({
  target: {
    type: Number,
    default: 2000,
  },
  donated: {
    type: String,
    default: null,
  },
  hideIntro: {
    type: Boolean,
    required: false,
    default: false,
  },
})
</script>
