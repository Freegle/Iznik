<template>
  <div class="height">
    <div
      v-if="loading"
      class="d-flex flex-column justify-content-around text-center pulsate text-muted w-100"
      :class="{ 'loading-overlay': !isApp }"
    >
      Loading donation methods...
    </div>
    <div v-if="isApp" class="d-flex flex-column gap-3">
      <b-button
        v-if="isGooglePayAvailable"
        variant="primary"
        size="lg"
        aria-label="Donate to Freegle with Google Pay"
        @click="useGooglePay"
      >
        <div class="d-flex align-items-center">
          <img src="/GooglePayButton.png" class="w-100" />
        </div>
      </b-button>
      <b-button
        v-if="isApplePayAvailable"
        variant="primary"
        size="lg"
        aria-label="Donate to Freegle with Apple Pay"
        @click="useApplePay"
      >
        <div class="d-flex align-items-center">
          <img src="/ApplePayButton.jpg" class="w-100" />
        </div>
      </b-button>
      <b-button
        v-if="isPayPalAvailable"
        variant="primary"
        size="lg"
        aria-label="Donate to Freegle with PayPay or a card"
        @click="usePayPalCard"
      >
        <div class="d-flex align-items-center">
          <img src="/PayPalButton.png" class="w-100" />
        </div>
      </b-button>
      <p
        v-if="
          !isGooglePayAvailable && !isApplePayAvailable && !isPayPalAvailable
        "
      >
        Sorry, there are no payments methods available on your phone.
      </p>
    </div>
    <div v-else :id="uniqueId"></div>
    <NoticeMessage v-if="error" variant="error">
      {{ error }}
    </NoticeMessage>
  </div>
</template>
<script setup>
import { loadStripe } from '@stripe/stripe-js'
import * as Sentry from '@sentry/browser'
import { Capacitor } from '@capacitor/core'
import {
  Stripe,
  PaymentSheetEventsEnum,
  GooglePayEventsEnum,
  ApplePayEventsEnum,
} from '@capacitor-community/stripe'
import { uid } from '~/composables/useId'
import { useDonationStore } from '~/stores/donations'
import { useMobileStore } from '@/stores/mobile'
import { useAuthStore } from '~/stores/auth'
import { action } from '~/composables/useClientLog'

const runtimeConfig = useRuntimeConfig()
const userSite = runtimeConfig.public.USER_SITE
const donationStore = useDonationStore()
const mobileStore = useMobileStore()
const authStore = useAuthStore()

// Detect iOS robustly - mobileStore.isiOS can be overwritten asynchronously by
// Device.getInfo() returning unexpected platform values on iPad.
// Use Capacitor.getPlatform() as primary, user agent as fallback for iPad.
const capacitorPlatform = Capacitor.getPlatform()
const userAgentIsiOS =
  typeof navigator !== 'undefined' &&
  (/iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.userAgent.includes('Mac') && 'ontouchend' in document))
const isiOS = capacitorPlatform === 'ios' || userAgentIsiOS

const props = defineProps({
  price: {
    type: Number,
    required: true,
  },
  monthly: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const isApp = ref(mobileStore.isApp) // APP

const loading = ref(true)
const isApplePayAvailable = ref(false)
const isGooglePayAvailable = ref(false)
const isPayPalAvailable = ref(false)
const intent = ref(false)

const emit = defineEmits(['loaded', 'error', 'success', 'noPaymentMethods'])

let uniqueId = null
let stripe = null
let stripeInitialized = false

// App Stripe initialization is deferred to onMounted to ensure runtimeConfig is available
if (!isApp.value) {
  uniqueId = uid('stripe-donate-')
  const stripeKey = runtimeConfig.public.STRIPE_PUBLISHABLE_KEY
  if (stripeKey) {
    try {
      stripe = await loadStripe(stripeKey)
    } catch (e) {
      console.error('Stripe load error', e)
      Sentry.captureMessage('Stripe load error', {
        extra: e,
      })
    }
  }
}

// If express checkout has not said it is ready by this time, nothing will appear in the box, so
// let the caller show the PayPal button instead. Generous, because on a slow phone connection a
// wallet can take a while to load, and falling back then would hide it.
const READY_TIMEOUT = 20000
let readyTimer = null
let settled = false

// The second argument says why, for callers that log the fallback ('timeout').
function settle(event, reason) {
  if (settled) return
  settled = true
  clearTimeout(readyTimer)
  loading.value = false
  if (reason) {
    emit(event, reason)
  } else {
    emit(event)
  }
}

onBeforeUnmount(() => clearTimeout(readyTimer))

const appearance = {/* appearance */}

const options = {
  paymentMethods: {
    // In dev, we can't use Google/Apple pay because we aren't over HTTPS and the domain isn't registered.
    googlePay: import.meta.dev ? 'never' : 'auto',
    applePay: import.meta.dev ? 'never' : 'auto',
  },
  paymentMethodOrder: ['googlepay', 'applepay', 'paypal', 'card', 'link'],
  layout: {
    overflow: 'never',
  },
}

let elements = null

if (stripe) {
  // If this fails, elements stays null and onMounted falls back to PayPal.
  try {
    if (props.monthly) {
      console.log('Create elements for subscription')
      const res = await donationStore.stripeSubscription(props.price)
      console.log('Subscription returned', res)

      elements = stripe.elements({
        clientSecret: res.clientSecret,
        appearance,
      })
    } else {
      console.log('Create elements for one-off payment')
      elements = stripe.elements({
        mode: 'payment',
        amount: props.price * 100, // Price is in pence
        currency: 'gbp',
        appearance,
      })
    }
  } catch (e) {
    console.error('Stripe elements error', e)
    Sentry.captureException(e, { tags: { stripe_step: 'elements' } })
    elements = null
  }
}

const error = ref(null)

onMounted(async () => {
  if (isApp.value) {
    const stripeKey = runtimeConfig.public.STRIPE_PUBLISHABLE_KEY

    if (!stripeKey) {
      console.error('Stripe publishableKey not available at mount')
      emit('error')
      loading.value = false
      return
    }

    if (!stripeInitialized) {
      try {
        Stripe.initialize({
          publishableKey: stripeKey,
        })
        stripeInitialized = true
      } catch (e) {
        console.error('Stripe.initialize failed', e)
        Sentry.captureException(e, {
          tags: { stripe_step: 'initialize' },
        })
        emit('error')
        loading.value = false
        return
      }
    }

    // In app mode, the Stripe PaymentSheet (PayPal/card) is always available
    // once Stripe.initialize succeeds. Set this BEFORE any API calls that might
    // fail, so we never incorrectly emit 'noPaymentMethods' and fall back to
    // the web PayPal SDK (which doesn't work in Capacitor WebView).
    isPayPalAvailable.value = true

    try {
      // Skip native event listeners - they cause a native crash on iPad
      // (the Stripe plugin's addListener triggers a fatal error in the
      // WKWebView process). The actual payment flow uses await on
      // presentPaymentSheet/presentApplePay/presentGooglePay directly.

      // Refresh session before creating intent so an expired JWT doesn't cause a
      // silent 401 failure here (which would force a retry via ensureIntent later).
      if (authStore.auth?.jwt || authStore.auth?.persistent) {
        try {
          await authStore.fetchUser()
        } catch (e) {
          console.log('Pre-intent session refresh failed at mount', e)
        }
      }

      // The API refuses to create an intent for someone who is not signed in, so only ask in advance
      // when there is a session. Otherwise the intent is created when they press a button, which
      // tells them to sign in.
      if (authStore.auth?.jwt || authStore.auth?.persistent) {
        try {
          if (props.monthly) {
            intent.value = await donationStore.stripeSubscription(props.price)
          } else {
            intent.value = await donationStore.stripeIntent({
              amount: props.price,
            })
          }
        } catch (intentError) {
          console.error('Payment intent creation failed', intentError)
          Sentry.captureException(intentError, {
            tags: { stripe_step: 'intent_error' },
          })
          // Don't re-throw - intent will be created lazily when user clicks a button
        }
      }

      if (
        !intent.value?.clientSecret &&
        (authStore.auth?.jwt || authStore.auth?.persistent)
      ) {
        console.error('Payment intent returned no clientSecret', intent.value)
      }

      // Check payment method availability
      if (!isiOS) {
        try {
          await Stripe.isGooglePayAvailable()
          isGooglePayAvailable.value = true
        } catch (e) {
          console.log('Google Pay not available', e)
        }
      } else {
        try {
          await Stripe.isApplePayAvailable()
          isApplePayAvailable.value = true
        } catch (e) {
          console.log('Apple Pay not available', e)
        }
      }
    } catch (e) {
      console.error('Stripe Exception', e.message, e)
      Sentry.captureException(e, {
        tags: { stripe_step: 'app_init' },
      })
    }
    if (
      isGooglePayAvailable.value ||
      isApplePayAvailable.value ||
      isPayPalAvailable.value
    ) {
      emit('loaded')
    } else {
      emit('noPaymentMethods')
    }
  }
  let expressCheckoutElement = null
  if (stripe && elements) {
    console.log(
      'Mounted for #',
      uniqueId,
      document.getElementById(uniqueId) !== null
    )
    readyTimer = setTimeout(() => {
      console.log('Express checkout not ready in time')
      settle('noPaymentMethods', 'timeout')
    }, READY_TIMEOUT)
    try {
      expressCheckoutElement = elements.create('expressCheckout', options)
      expressCheckoutElement.mount('#' + uniqueId)
    } catch (e) {
      console.error('Express checkout create error', e)
      Sentry.captureException(e, { tags: { stripe_step: 'express_mount' } })
      expressCheckoutElement = null
      settle('error')
    }
  }
  if (expressCheckoutElement) {
    expressCheckoutElement.on('ready', (event) => {
      console.log('Express checkout ready', event)

      // Stripe reports each wallet as true or false, so an object with no true value means
      // there is nothing to show. We've seen this happen on Brave.
      const methods = event.availablePaymentMethods
      if (
        !methods ||
        typeof methods !== 'object' ||
        !Object.values(methods).some(Boolean)
      ) {
        console.log('No Stripe payment methods available')
        settle('noPaymentMethods')
      } else {
        settle('loaded')
      }
    })
    expressCheckoutElement.on('loaderror', (event) => {
      console.log('Express checkout loadError', event)
      Sentry.captureMessage('Stripe Express Checkout load error', {
        extra: event,
      })
      settle('error')
    })
    expressCheckoutElement.on('change', (event) => {
      console.log('Express checkout change', event)
    })
    expressCheckoutElement.on('loaderstart', (event) => {
      console.log('Express checkout loadStart', event)
    })
    expressCheckoutElement.on('confirm', async (event) => {
      action('donation_payment_started', {
        amount: props.price,
        method: event.expressPaymentType,
      })

      const { submitError } = await elements.submit()

      if (submitError) {
        console.error('Payment submit error')
        Sentry.captureMessage('Stripe Express Checkout load error', {
          extra: event,
        })
        emit('error')
      } else if (!props.monthly) {
        // Create the PaymentIntent and obtain clientSecret
        console.log(
          'One-off payment, price:',
          props.price,
          'type:',
          event.expressPaymentType
        )
        console.log('donationStore:', donationStore)
        console.log('donationStore.config:', donationStore.config)

        let res
        try {
          console.log('Calling stripeIntent...')
          res = await donationStore.stripeIntent({
            amount: props.price,
            paymenttype: event.expressPaymentType,
          })
          console.log('stripeIntent returned:', res)
        } catch (e) {
          console.error('stripeIntent exception:', e)
          console.error('Exception message:', e.message)
          console.error('Exception stack:', e.stack)
          throw e
        }

        const clientSecret = res?.clientSecret
        console.log('clientSecret:', clientSecret)

        const { error } = await stripe.confirmPayment({
          // `elements` instance used to create the Express Checkout Element
          elements,
          // `clientSecret` from the created PaymentIntent
          clientSecret,
          confirmParams: {
            return_url: userSite + '/donated',
          },
          redirect: 'if_required',
        })

        console.log('Confirm payment returned', error)

        if (error) {
          console.error('Confirm payment error', error)
          Sentry.captureMessage('Confirm payment error', {
            extra: event,
          })
          emit('error')
        } else {
          // The payment UI is support to automatically close. But we have
          // seen a PayPal overlay persist, so remove that if it's present.
          const overlap = document.querySelectorAll('[data-testid="overlay"]')

          overlap.forEach((el) => {
            el.remove()
          })

          emit('success')
        }
      } else {
        const { error } = await stripe.confirmPayment({
          // `Elements` instance that was used to create the Payment Element
          elements,
          confirmParams: {
            return_url: userSite + '/donated',
          },
        })

        if (error) {
          console.error('Create subscription error', error)
          Sentry.captureMessage('Create subscription  error', {
            extra: event,
          })

          error.value = error.message

          emit('error')
        } else {
          emit('success')
        }
      }
    })
  } else if (!isApp.value) {
    // No key, Stripe.js would not load (often blocked), or the elements could not be set up:
    // nothing will appear in the box. A no-op if a failed create already settled.
    settle('error')
  }

  // On the web, the loading message stays until express checkout settles, so the box does not
  // empty and then refill.
  if (isApp.value) {
    loading.value = false
  }
})

// Create payment intent on-demand if it wasn't ready at mount time (e.g., API error).
async function ensureIntent() {
  if (intent.value?.clientSecret) {
    return true
  }

  // Proactively refresh the session before attempting payment. The JWT is short-lived;
  // if the user opened the app after some time away the JWT may have expired while the
  // persistent token is still valid. Refreshing here gives the server a chance to issue
  // a fresh JWT before we hit /stripecreateintent, preventing a 401 that would lose the
  // donation.
  if (authStore.auth?.jwt || authStore.auth?.persistent) {
    try {
      await authStore.fetchUser()
    } catch (e) {
      // fetchUser failed (e.g. both tokens expired). It wipes auth itself when no user came back.
      console.log('Pre-payment session refresh failed', e)
    }
  }

  // If tokens are gone after the refresh attempt, the user is not authenticated.
  if (!authStore.auth?.jwt && !authStore.auth?.persistent) {
    error.value =
      'Your session has expired. Please log in again to complete your donation.'
    return false
  }

  try {
    if (props.monthly) {
      intent.value = await donationStore.stripeSubscription(props.price)
    } else {
      intent.value = await donationStore.stripeIntent({
        amount: props.price,
      })
    }

    if (intent.value?.clientSecret) {
      return true
    }
  } catch (e) {
    console.error('Lazy intent creation failed', e)
    Sentry.captureException(e, {
      tags: { stripe_step: 'lazy_intent_error' },
    })
  }

  error.value = 'Payment not ready. Please try again in a moment.'
  return false
}

async function useGooglePay() {
  try {
    action('donation_payment_started', {
      amount: props.price,
      method: 'google_pay',
    })

    if (!(await ensureIntent())) {
      return
    }

    await Stripe.createGooglePay({
      paymentIntentClientSecret: intent.value.clientSecret,
      merchantIdentifier: 'org.ilovefreegle.direct',
      countryCode: 'GB',
      currency: 'GBP',
    })

    const result = await Stripe.presentGooglePay()

    if (result.paymentResult === GooglePayEventsEnum.Completed) {
      emit('success')
    }
  } catch (e) {
    console.error('Stripe Google Pay error', e)
    Sentry.captureException(e, {
      tags: { stripe_step: 'googlepay_flow' },
    })
    error.value = 'Google Pay failed. Please try another method.'
  }
}

async function usePayPalCard() {
  try {
    action('donation_payment_started', {
      amount: props.price,
      method: 'paypal_card',
    })

    if (!(await ensureIntent())) {
      return
    }

    await Stripe.createPaymentSheet({
      paymentIntentClientSecret: intent.value.clientSecret,
      merchantDisplayName: 'Freegle',
      // Enable Apple Pay within the PaymentSheet so users can pay with
      // Apple Pay even from the card/PayPal button flow.
      enableApplePay: true,
      applePayMerchantId: 'merchant.org.ilovefreegle.direct',
      enableGooglePay: !isiOS,
      countryCode: 'GB',
      // returnURL is required on iOS for external payment methods (PayPal etc)
      // and can cause crashes if missing (plugin issue #313).
      returnURL: 'capacitor://localhost/stripe-redirect',
    })

    const result = await Stripe.presentPaymentSheet()

    if (result.paymentResult === PaymentSheetEventsEnum.Completed) {
      emit('success')
    }
  } catch (e) {
    console.error('Stripe PayPal/card error', e)
    Sentry.captureException(e, {
      tags: { stripe_step: 'paypal_flow' },
    })
    error.value = 'Payment failed. Please try another method.'
  }
}

async function useApplePay() {
  try {
    action('donation_payment_started', {
      amount: props.price,
      method: 'apple_pay',
    })

    if (!(await ensureIntent())) {
      return
    }

    await Stripe.createApplePay({
      paymentIntentClientSecret: intent.value.clientSecret,
      paymentSummaryItems: [
        {
          label: 'Freegle Donation',
          // Amount MUST be a float, not integer. The native Swift code does
          // item["amount"] as? NSNumber which returns nil for JS integers,
          // causing a force-unwrap crash (plugin issue #351).
          amount: parseFloat(props.price),
        },
      ],
      merchantIdentifier: 'merchant.org.ilovefreegle.direct',
      countryCode: 'GB',
      currency: 'GBP',
    })

    const result = await Stripe.presentApplePay()

    if (result.paymentResult === ApplePayEventsEnum.Completed) {
      emit('success')
    }
  } catch (e) {
    console.error('Stripe Apple Pay error', e)
    Sentry.captureException(e, {
      tags: { stripe_step: 'applepay_flow' },
    })
    error.value = 'Apple Pay failed. Please try another method.'
  }
}
</script>
<style scoped lang="scss">
.height {
  min-height: 48px;
  position: relative;
}

/* Sits over the express checkout box while it loads, so the box keeps one height. */
.loading-overlay {
  position: absolute;
  inset: 0;
  min-height: 48px;
  /* Solid, so wallet buttons painting underneath before they are ready do not show through. */
  background: var(--bs-body-bg, white);
  z-index: 1;
}
</style>
