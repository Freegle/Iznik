<template>
  <b-modal
    ref="modal"
    scrollable
    title="Make donating even easier"
    modal-class="paypal-giving-fund-modal"
  >
    <template #default>
      <div class="text-center">
        <v-icon icon="star" class="pgf-icon" />
      </div>
      <p>
        Thanks for donating with PayPal! You can make it even easier to help
        Freegle in future.
      </p>
      <p>
        Set <strong>Freegle</strong> as your favourite charity in
        <strong>PayPal Giving Fund</strong>. Then, whenever you buy something
        online from a shop that takes part, PayPal offers you the chance to add
        a small donation, such as £1, at the checkout. It's an easy way to keep
        supporting Freegle.
      </p>
      <p class="mb-0">
        When you tap <strong>Yes please</strong> we'll open PayPal Giving Fund
        in a new tab. Just use the button there to set Freegle as your favourite
        charity, and you're done.
      </p>
    </template>
    <template #footer>
      <b-button variant="white" @click="hide"> No thanks </b-button>
      <ExternalLink :href="fundraiserUrl" @click="hide">
        <b-button variant="primary"> Yes please </b-button>
      </ExternalLink>
    </template>
  </b-modal>
</template>

<script setup>
import { useOurModal } from '~/composables/useOurModal'
import ExternalLink from '~/components/ExternalLink'

// The same PayPal Giving Fund fundraiser page that /donate links to.
const fundraiserUrl = 'https://www.paypal.com/fundraiser/charity/55681'

const { modal, hide } = useOurModal()
</script>

<style scoped lang="scss">
.pgf-icon {
  color: $color-gold;
  font-size: 2.5rem;
  margin-bottom: 0.5rem;
}
</style>
