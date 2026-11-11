<template>
  <div>
    <b-button
      variant="primary"
      size="lg"
      aria-label="Donate to Freegle with PayPal"
      @click="clicked"
    >
      <div class="d-flex align-items-center">
        <div
          :id="uniqueId"
          :key="bump"
          ref="paypalbutton"
          class="me-2"
          @click="suppress"
        >
          <img
            v-if="usePayPalPage"
            src="/pp_cc_mark_37x23.jpg"
            :title="'Donate ' + (show ? show : '') + ' with PayPal'"
            :alt="'Donate ' + (show ? show : '') + ' with PayPal'"
          />
        </div>
        <div v-if="text">{{ text }}</div>
        <div v-else-if="!show">Donate</div>
        <div v-else>{{ show }}</div>
      </div>
    </b-button>
    <PayPalGivingFundModal
      v-if="showGivingFund"
      @hidden="showGivingFund = false"
    />
  </div>
</template>
<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { loadScript } from 'vue-plugin-load-script'
import { uid } from '~/composables/useId'
import { useNuxtApp } from '#app'
import PayPalGivingFundModal from '~/components/PayPalGivingFundModal'
import { useMobileStore } from '~/stores/mobile'

const props = defineProps({
  directDonation: {
    type: Boolean,
    required: false,
    default: false,
  },
  value: {
    type: String,
    required: false,
    default: null,
  },
  text: {
    type: String,
    required: false,
    default: null,
  },
  // After a completed PayPal donation, offer to set Freegle as the member's
  // favourite charity in PayPal Giving Fund. Off on /donate, where that
  // suggestion already has its own card.
  suggestGivingFund: {
    type: Boolean,
    required: false,
    default: true,
  },
})

const bump = ref(1)
const showGivingFund = ref(false)

// PayPal's donate button opens its checkout in a pop-up. In the app's WebView
// and in the in-app browsers of the Google, Facebook and Instagram apps the
// pop-up cannot open, PayPal gives up with "Expected element to be passed to
// render iframe", and the tap does nothing. There we draw the button ourselves
// and open PayPal's donate page instead, which the app hands to the system
// browser. PayPal then cannot tell us the amount, so 'clicked' is not emitted.
const IN_APP_BROWSER = /FBAN\/|FBAV\/|Instagram|GSA\//
const mobileStore = useMobileStore()
const needsPayPalPage =
  import.meta.client &&
  (mobileStore.isApp || IN_APP_BROWSER.test(window.navigator.userAgent))

// Set on mount rather than in setup, so the server render and the first client
// render agree.
const usePayPalPage = ref(false)

watch(
  () => props.value,
  (newVal, oldVal) => {
    bump.value++
    nextTick(() => {
      if (!usePayPalPage.value) {
        checkPayPalLoaded()
      }
    })
  }
)

const emit = defineEmits(['clicked'])
const paypalbutton = ref(null)
const nuxtApp = useNuxtApp()

if (!needsPayPalPage) {
  await loadScript('https://www.paypalobjects.com/donate/sdk/donate-sdk.js')
}

// Computed properties
const uniqueId = computed(() => {
  return uid('donation-')
})

const buttonId = computed(() => {
  // These are set up at https://www.paypal.com/donate/buttons/manage
  switch (props.value) {
    case '1':
      return 'BA7SYG5KVH4WW'
    case '1510':
      return 'XEFBZCF2RDEQ8'
    case '2':
      return 'S3RKX5JHQUUL4'
    case '2510':
      return 'KSDKLE7WBW2X2'
    case '3':
      return 'L4GDMEU6FMNFJ'
    case '5':
      return '92MLE3SKST546'
    case '10':
      return 'KTNBE4YMDUGUY'
    case '15':
      return '7T354NV6HL9P4'
    case '25':
      return 'D5P8XMVRDLC7N'
    case 'any':
      // Any amount.
      return '2DZ6YUDERBVKC'
    default:
      // And whatyoucan. Works better to suggest amounts.
      return 'KSDKLE7WBW2X2'
  }
})

const show = computed(() => {
  return props.value ? '£' + props.value : null
})

// Methods
function checkPayPalLoaded() {
  if (window.PayPal) {
    makeButton()
  } else {
    setTimeout(checkPayPalLoaded, 100)
  }
}

function makeButton() {
  window.PayPal.Donation.Button({
    env: 'production',
    hosted_button_id: buttonId.value,
    image: {
      src: '/pp_cc_mark_37x23.jpg',
      title: 'Donate ' + (show.value ? show.value : '') + ' with PayPal',
      alt: 'Donate ' + (show.value ? show.value : '') + ' with PayPal',
    },
    onComplete(params) {
      // Because we get a callback we can record the actual amount donated.
      console.log('Donation completed with', params)
      emit('clicked', params.amt)
      if (props.suggestGivingFund) {
        showGivingFund.value = true
      }
    },
  }).render('#' + uniqueId.value)
}

function suppress(e) {
  // Stop clicking on the PayPal button itself triggering the button click which will click the PayPal button.
  // Our own drawing of it has no click of its own, so that one goes through.
  if (!usePayPalPage.value) {
    e.stopPropagation()
  }
}

function clicked() {
  if (nuxtApp.$gtm?.enabled()) {
    nuxtApp.$gtm.trackEvent({
      event: 'Donate',
      label: 'Z1RRCIfbv7kZELy618UD',
    })
  }

  if (usePayPalPage.value) {
    window.open(
      'https://www.paypal.com/donate/?hosted_button_id=' + buttonId.value,
      '_blank'
    )
  } else {
    paypalbutton.value?.firstElementChild?.click()
  }
}

// Lifecycle hooks
onMounted(() => {
  usePayPalPage.value = needsPayPalPage

  if (!usePayPalPage.value) {
    checkPayPalLoaded()
  }
})
</script>
