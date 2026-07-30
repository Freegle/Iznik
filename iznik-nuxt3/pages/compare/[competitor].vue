<template>
  <div class="compare__wrapper">
    <article v-if="entry" class="compare">
      <h1 class="compare__title">{{ entry.title }}</h1>

      <p class="compare__lead">{{ entry.intro }}</p>

      <section class="compare__section">
        <h2>Which should you use?</h2>
        <p>{{ entry.whichToUse }}</p>
      </section>

      <section class="compare__section">
        <h2>What’s a bit different about Freegle</h2>
        <p v-for="(para, i) in entry.differences" :key="i">
          {{ para }}
        </p>
      </section>

      <section class="compare__section">
        <h2>In short</h2>
        <table class="compare__table">
          <thead>
            <tr>
              <th scope="col"></th>
              <th scope="col">Freegle</th>
              <th scope="col">{{ entry.name }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in entry.atAGlance" :key="row.point">
              <th scope="row">{{ row.point }}</th>
              <td>{{ row.freegle }}</td>
              <td>{{ row.them }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="compare__section">
        <h2>Questions people ask</h2>
        <div v-for="faq in entry.faqs" :key="faq.q" class="compare__faq">
          <h3 class="compare__faq-q">{{ faq.q }}</h3>
          <p class="compare__faq-a">{{ faq.a }}</p>
        </div>
        <p class="compare__help">
          Got a question we haven’t covered? Have a look at our
          <NuxtLink no-prefetch to="/help">help section</NuxtLink>.
        </p>
      </section>

      <p class="compare__close">{{ sharedClose }}</p>

      <div class="compare__cta">
        <NuxtLink no-prefetch to="/" class="compare__cta-btn"
          >Have a look at Freegle</NuxtLink
        >
        <NuxtLink no-prefetch to="/compare" class="compare__cta-link"
          >See how we compare with others</NuxtLink
        >
      </div>
    </article>
  </div>
</template>

<script setup>
import { buildHead } from '~/composables/useBuildHead'
import { useRoute, useRuntimeConfig, useHead, createError } from '#imports'
import { comparisons, sharedClose } from '~/constants/comparisons'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()

const entry = comparisons[route.params.competitor]

if (!entry) {
  throw createError({
    statusCode: 404,
    statusMessage: 'Page not found',
    fatal: true,
  })
}

useHead(buildHead(route, runtimeConfig, entry.title, entry.metaDescription))

// FAQ structured data - helps Google rich results and gives AI answers a clean
// question/answer pair to lift.
useHead({
  script: [
    {
      type: 'application/ld+json',
      innerHTML: JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'FAQPage',
        mainEntity: entry.faqs.map((f) => ({
          '@type': 'Question',
          name: f.q,
          acceptedAnswer: { '@type': 'Answer', text: f.a },
        })),
      }),
    },
  ],
})
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

.compare__section {
  margin-bottom: 1.75rem;

  h2 {
    font-size: 1.2rem;
    font-weight: 600;
    color: $gray-800;
    margin-bottom: 0.75rem;
  }

  p {
    font-size: 0.9375rem;
    line-height: 1.7;
    margin-bottom: 0.75rem;
  }
}

.compare__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9375rem;

  th,
  td {
    text-align: left;
    padding: 0.5rem 0.6rem;
    border-bottom: 1px solid $gray-200;
    vertical-align: top;
  }

  thead th {
    font-weight: 600;
    color: $gray-800;
  }

  tbody th {
    font-weight: 600;
    color: $gray-700;
  }
}

.compare__faq {
  margin-bottom: 1rem;
}

.compare__faq-q {
  font-size: 1rem;
  font-weight: 600;
  color: $gray-800;
  margin-bottom: 0.25rem;
}

.compare__faq-a {
  font-size: 0.9375rem;
  line-height: 1.7;
  margin-bottom: 0;
}

.compare__close {
  font-size: 1rem;
  line-height: 1.7;
  font-style: italic;
  color: $gray-800;
  margin: 1.5rem 0;
}

.compare__cta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
  margin-top: 1.5rem;
}

.compare__cta-btn {
  display: inline-block;
  padding: 0.6rem 1.2rem;
  background: $primary;
  color: $white;
  font-weight: 600;
  text-decoration: none;
  border-radius: 0;

  &:hover {
    filter: brightness(0.95);
    color: $white;
  }
}

.compare__cta-link {
  font-size: 0.9375rem;
}
</style>
