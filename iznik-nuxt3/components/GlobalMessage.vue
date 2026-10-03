<template>
  <div>
    <PrivacyUpdate />
    <div v-if="relevantGroup">
      <b-card v-if="show">
        <div class="grid">
          <div class="hide">
            <b-button
              variant="link"
              size="xs"
              class="p-0"
              title="Hide banner"
              @click="hideIt"
            >
              Hide
            </b-button>
          </div>
          <div class="banner">
            <div>
              <p class="header--size2 mb-0">
                Quick survey - win a £25 voucher!
              </p>
              <p>
                We're asking freeglers on Wandsworth a few questions. Can you
                help?
              </p>
              <b-button
                variant="primary"
                size="lg"
                href="https://ilovefreegle.org/shortlink/WandsworthSurvey"
                target="_blank"
              >
                Click to open survey
              </b-button>
            </div>
          </div>
        </div>
      </b-card>
      <div v-else class="text-danger text-end clickme" @click="showit">
        Show notice.
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import { useMiscStore } from '~/stores/misc'
import PrivacyUpdate from '~/components/PrivacyUpdate.vue'

const miscStore = useMiscStore()

const warningid = ref('hideglobalwarning20250530')

// This banner was a time-boxed survey for members of one community (Wandsworth), gated on
// group membership. The campaign's end date has long passed (2025-06-22), so it always
// evaluated to false anyway; the per-group check is removed rather than kept as dead code.
const relevantGroup = computed(() => false)

const show = computed(() => {
  return !miscStore?.get(warningid.value)
})

const hideIt = (e) => {
  e.preventDefault()
  miscStore.set({
    key: warningid.value,
    value: true,
  })
}

const showit = () => {
  miscStore.set({
    key: warningid.value,
    value: false,
  })
}
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/_functions.scss';
@import 'bootstrap/scss/_variables.scss';
@import 'bootstrap/scss/mixins/_breakpoints.scss';

.logo {
  width: 75px;
  height: 75px;
  @include media-breakpoint-up(md) {
    width: 125px;
    height: 125px;
  }
}

.grid {
  display: grid;
  grid-template-rows: 1fr;
  grid-template-columns: 1fr;

  .banner {
    grid-row: 1 / 1;
    grid-column: 1 / 1;
    display: flex;
    flex-wrap: wrap;
  }

  .hide {
    grid-row: 1 / 1;
    grid-column: 1 / 1;
    justify-self: end;
    z-index: 1000;
  }
}

:deep(img) {
  width: 100%;
}

:deep(.christmas) {
  max-width: 100px;
  max-height: 100px;

  @include media-breakpoint-up(md) {
    max-width: 200px;
    max-height: 200px;
  }
}
</style>
