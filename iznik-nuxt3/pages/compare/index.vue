<template>
  <div class="compare__wrapper">
    <article class="compare">
      <h1 class="compare__title">Freegle and the alternatives</h1>

      <p class="compare__lead">
        There are lots of ways to give and get things for free, and we think
        that’s brilliant, the more stuff gets reused instead of binned, the
        better. If you’re weighing up your options, here’s an honest look at how
        Freegle compares with a few of the others.
      </p>

      <ul class="compare__list">
        <li v-for="item in comparisonList" :key="item.slug">
          <NuxtLink
            no-prefetch
            :to="`/compare/${item.slug}`"
            class="compare__list-link"
          >
            {{ item.title }}
          </NuxtLink>
          <span class="compare__list-blurb">{{ item.intro }}</span>
        </li>
      </ul>

      <p class="compare__help">
        Got a question about how Freegle works? Have a look at our
        <NuxtLink no-prefetch to="/help">help section</NuxtLink>.
      </p>

      <p class="compare__close">{{ sharedClose }}</p>
    </article>
  </div>
</template>

<script setup>
import { buildHead } from '~/composables/useBuildHead'
import { useRoute, useRuntimeConfig, useHead } from '#imports'
import { comparisonList, sharedClose } from '~/constants/comparisons'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()

useHead(
  buildHead(
    route,
    runtimeConfig,
    'Freegle and the alternatives',
    'How Freegle compares with Trash Nothing, Olio, Freecycle and Facebook ' +
      'Marketplace: an honest, friendly look at the free-reuse options.'
  )
)
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.compare__wrapper {
  display: flex;
  justify-content: center;
  background: $gray-100;
  min-height: 100vh;
}

.compare {
  width: 100%;
  max-width: 800px;
  padding: 1rem;
  color: $gray-700;

  @include media-breakpoint-up(md) {
    padding: 1.5rem;
  }
}

.compare__title {
  font-size: 1.6rem;
  font-weight: 700;
  color: $gray-900;
  margin-bottom: 1rem;
}

.compare__lead {
  font-size: 1.05rem;
  line-height: 1.7;
  margin-bottom: 1.5rem;
}

.compare__list {
  list-style: none;
  padding: 0;
  margin: 0 0 1.5rem;

  li {
    padding: 1rem 0;
    border-bottom: 1px solid $gray-200;
  }
}

.compare__list-link {
  display: block;
  font-size: 1.1rem;
  font-weight: 600;
  margin-bottom: 0.25rem;
}

.compare__list-blurb {
  display: block;
  font-size: 0.9375rem;
  line-height: 1.6;
  color: $gray-600;
}

.compare__help {
  font-size: 0.9375rem;
  line-height: 1.7;
  margin-bottom: 1rem;
}

.compare__close {
  font-size: 1rem;
  line-height: 1.7;
  font-style: italic;
  color: $gray-800;
  margin-top: 1.5rem;
}
</style>
