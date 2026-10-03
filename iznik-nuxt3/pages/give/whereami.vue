<template>
  <client-only>
    <div class="location-page">
      <!-- Compact progress stepper -->
      <div class="stepper-container">
        <WizardProgressCompact :active-stage="2" />
      </div>

      <!-- Main content -->
      <div class="location-content">
        <GlobalMessage />

        <div class="location-card">
          <h1 class="location-title">Where is it?</h1>
          <p class="location-subtitle">
            We'll use this to show your offer to people nearby. Don't worry, we
            won't give other people your postcode.
          </p>

          <!-- Postcode input -->
          <div class="postcode-section">
            <PostCode
              :value="initialPostcode"
              :no-store="false"
              @selected="postcodeSelect"
              @cleared="postcodeClear"
            />
          </div>

          <!-- Personal info warning -->
          <div v-if="postcodeValid" class="community-section">
            <PostPersonalInfoWarning :text="postText" />
          </div>
        </div>

        <!-- Navigation button -->
        <div v-if="postcodeValid" class="next-section">
          <div class="next-container">
            <b-button
              variant="primary"
              size="lg"
              to="/give/options"
              class="next-btn"
            >
              Next: Options <v-icon icon="angle-double-right" />
            </b-button>
          </div>
        </div>
      </div>
    </div>
  </client-only>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useHead, useRuntimeConfig } from '#imports'
import GlobalMessage from '~/components/GlobalMessage.vue'
import PostCode from '~/components/PostCode.vue'
import WizardProgressCompact from '~/components/WizardProgressCompact.vue'
import PostPersonalInfoWarning from '~/components/PostPersonalInfoWarning.vue'
import { setup, postcodeSelect, postcodeClear } from '~/composables/useCompose'
import { buildHead } from '~/composables/useBuildHead'
import { useComposeStore } from '~/stores/compose'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()

useHead(
  buildHead(
    route,
    runtimeConfig,
    'OFFER',
    'OFFER something to people nearby and see who wants it'
  )
)

const { initialPostcode, postcodeValid } = await setup('Offer')

const composeStore = useComposeStore()
const postText = computed(() => {
  const msgs = composeStore.all.filter((m) => m.type === 'Offer')
  if (!msgs.length) return ''
  const msg = msgs[0]
  return ((msg.item || '') + ' ' + (msg.description || '')).trim()
})
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.location-page {
  min-height: 100vh;
  background: $color-gray--lighter;
}

.stepper-container {
  background: white;
  padding: 1rem;
  box-shadow: var(--shadow-sm);

  @include media-breakpoint-up(lg) {
    padding: 1.5rem 2rem;
  }
}

.location-content {
  max-width: 600px;
  margin: 0 auto;
  padding: 1.5rem;

  @include media-breakpoint-up(lg) {
    padding: 2rem;
  }
}

.location-card {
  background: white;
  padding: 2rem;
  box-shadow: var(--shadow-md);
}

.location-title {
  font-size: 1.5rem;
  font-weight: 600;
  color: $color-green-background;
  margin-bottom: 0.5rem;
  text-align: center;
}

.location-subtitle {
  color: $color-gray--normal;
  text-align: center;
  margin-bottom: 1.5rem;
}

.postcode-section {
  display: flex;
  justify-content: center;
  padding: 1rem 0;
}

.community-section {
  margin-top: 1.5rem;
}

.next-section {
  margin-top: 2rem;
  margin-bottom: 3rem;
}

.next-container {
  display: flex;
  justify-content: center;
}

.next-btn {
  min-width: 280px;
  padding: 1rem 2rem;
  font-size: 1.1rem;
  font-weight: 600;
}
</style>
