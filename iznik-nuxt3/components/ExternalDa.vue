<template>
  <client-only>
    <!--
    If you don't like ads, then you can use an ad blocker.  Plus you could donate to us
    at https://www.ilovefreegle.org/donate - if we got enough donations we would be delighted not to show ads.
     -->
    <div
      v-if="me || showLoggedOut"
      v-observe-visibility="visibilityChanged"
      class="pointer"
      :class="{
        'bg-white': adShown,
      }"
    >
      <div
        v-if="fallbackAdVisible && !video"
        class="d-flex w-100 justify-content-md-around"
        :style="maxWidth ? `max-width: ${maxWidth}` : ''"
      >
        <!-- The donate banner, and ONLY the donate banner. This block is reached from a
             single place: the app without cookies, which cannot run a real ad at all, so a
             banner here is genuine content rather than something filling a hole. Routing
             failed ad loads through it instead (8b8b18176) is what reserved 123px for
             nothing when the jobs list was also empty. -->
        <nuxt-link to="/adsoff" style="display: block; max-width: 100%">
          <img
            src="/donate/SupportFreegle_970x250px_20May20215.png"
            alt="Please donate to help keep Freegle running"
            style="width: 100%; height: auto; display: block"
          />
        </nuxt-link>
      </div>
      <div v-else>
        <div
          v-if="isVisible || video"
          :class="{
            boredWithJobs,
            jobs,
          }"
        >
          <div class="d-flex w-100 justify-content-md-around">
            <JobsDaSlot
              v-if="renderAd && !boredWithJobs"
              :min-width="minWidth"
              :max-width="maxWidth"
              :min-height="minHeight"
              :max-height="maxHeight"
              :hide-header="hideJobsHeader"
              :list-only="listOnly"
              :placement="placement"
              :class="{
                'text-center': maxWidth === '100vw',
              }"
              @rendered="rippleRendered"
              @borednow="setBored"
            />
            <OurPlaywireDa
              v-else-if="playWire"
              ref="playwiread"
              :ad-unit-path="adUnitPath"
              :min-width="minWidth"
              :max-width="maxWidth"
              :min-height="minHeight"
              :max-height="maxHeight"
              :div-id="divId"
              :render-ad="renderAd"
              :video="video"
              @rendered="rippleRendered"
            />
            <OurGoogleDa
              v-else-if="adSense"
              ref="googlead"
              :ad-unit-path="adUnitPath"
              :min-width="minWidth"
              :max-width="maxWidth"
              :min-height="minHeight"
              :max-height="maxHeight"
              :div-id="divId"
              :render-ad="renderAd"
              @rendered="rippleRendered"
            />
            <OurPrebidDa
              v-else
              ref="prebidad"
              :ad-unit-path="adUnitPath"
              :min-width="minWidth"
              :max-width="maxWidth"
              :min-height="minHeight"
              :max-height="maxHeight"
              :div-id="divId"
              :render-ad="renderAd"
              @rendered="rippleRendered"
            />
          </div>
        </div>
      </div>
    </div>
  </client-only>
</template>
<script setup>
import { ref, computed, onBeforeUnmount } from '#imports'
import { useConfigStore } from '~/stores/config'
import { useMiscStore } from '~/stores/misc'
import { useMe } from '~/composables/useMe'

const miscStore = useMiscStore()
const { me, recentDonor } = useMe()

const props = defineProps({
  adUnitPath: {
    type: String,
    required: true,
  },
  // Which slot this ad unit sits in, forwarded to JobsDaSlot so job clicks/
  // impressions are tagged per placement (sticky_footer_mobile/desktop,
  // sidebar_left/right, ...). Defaults to 'daslot' (legacy/untagged).
  placement: {
    type: String,
    required: false,
    default: 'daslot',
  },
  minWidth: {
    type: String,
    required: false,
    default: null,
  },
  maxWidth: {
    type: String,
    required: false,
    default: null,
  },
  minHeight: {
    type: String,
    required: false,
    default: null,
  },
  maxHeight: {
    type: String,
    required: false,
    default: null,
  },
  divId: {
    type: String,
    required: true,
  },
  inModal: {
    type: Boolean,
    default: false,
  },
  showLoggedOut: {
    type: Boolean,
    default: true,
  },
  jobs: {
    type: Boolean,
    default: true,
  },
  video: {
    type: Boolean,
    default: false,
  },
  hideJobsHeader: {
    type: Boolean,
    default: false,
  },
  listOnly: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['rendered', 'disabled'])

// We can run with Playwire, Ad Sense or with Prebid.  Playwire is the default.
const playWire = ref(true)
const adSense = ref(false)
const renderAd = ref(false)
const adShown = ref(true)
const boredWithJobs = computed(() => !props.jobs || miscStore.boredWithJobs)

function setBored() {
  // Using the store, but non-persisted, means that we'll show job ads on initial page load, but then other ads
  // thereafter, including after page transition.
  //
  // This means that if they're not interested in job ads we'll get more ad views.
  miscStore.boredWithJobs = true
}

let prebidRetry = 0
let tcDataRetry = 0
let visibleAndScriptsLoadedTimer = null
const isVisible = ref(false)
const fallbackAdVisible = ref(false)
let firstBecomeVisible = false

function visibilityChanged(visible) {
  // The DOM element may have become visible.  But that isn't the end of the story.
  //
  // We need to wait for CookieYes, then the TC data, then prebid being loaded.  This is triggered in nuxt.config.ts.
  // Check the status here rather than on component load, as it might not be available yet.
  visibleAndScriptsLoadedTimer = null

  if (process.client) {
    const runtimeConfig = useRuntimeConfig()

    if (
      runtimeConfig.public.ISAPP &&
      !runtimeConfig.public.USE_COOKIES &&
      !props.video
    ) {
      // App without cookies - show fallback donation ad unless recent donor (but not for video ads)
      console.log('Running in app with no cookies - using fallback ad')
      if (recentDonor.value) {
        console.log('Ads disabled in app as recent donor')
        emit('rendered', false)
      } else {
        fallbackAdVisible.value = true
        adShown.value = true
        emit('rendered', true)
      }
      return
    }

    if (!runtimeConfig.public.COOKIEYES) {
      // Not using CookieYes, e.g. in dev.
      console.log('No CookieYes in ad')
      isVisible.value = visible

      if (visible && !firstBecomeVisible) {
        // We might want to show the ad now, if we stay visible for a little while.
        firstBecomeVisible = true

        if (!checkStillVisibleTimer) {
          checkStillVisibleTimer = setTimeout(checkStillVisible, 100)
        }
      }
    } else if (!window.__tcfapi) {
      // CookieYes not yet loaded - retry.
      console.log('CookieYes not yet loaded in ad')
      visibleAndScriptsLoadedTimer = window.setTimeout(() => {
        visibilityChanged(visible)
      }, 100)
    } else {
      // CookieYes is loaded.  Now we have to wait until they've given consent.
      window.__tcfapi(
        'getTCData',
        2,
        (tcData, success) => {
          if (success && tcData && tcData.tcString) {
            // The user has responded to the cookie banner.
            console.log('TC data loaded and TC String set', tcData.tcString)

            if (!playWire.value && !adSense.value && !window.pbjs?.version) {
              // Prebid required but not loaded yet.
              prebidRetry++

              if (prebidRetry > 20) {
                // Give up. Probably blocked, so report no ad and let the band collapse.
                console.log('Give up on prebid load')
                adShown.value = false
                emit('rendered', false)
              } else {
                // Try again for prebid later.
                visibleAndScriptsLoadedTimer = setTimeout(() => {
                  visibilityChanged(visible)
                }, 100)
              }
            } else {
              // Prebid has loaded if required.  We might want to show the ad now, if we stay visible for a little while.
              // Video ads are always visible because they float.
              console.log('Prebid loaded or not required')
              isVisible.value = visible || props.video

              if (isVisible.value && !firstBecomeVisible) {
                if (!checkStillVisibleTimer) {
                  checkStillVisibleTimer = setTimeout(checkStillVisible, 100)
                }
              }
            }
          } else {
            // TC data not yet ready - the user hasn't yet responded to the cookie banner.
            // Try again later.
            console.log('TC data not yet available in ad')
            tcDataRetry++

            if (tcDataRetry > 50) {
              // Give up. Probably blocked, so report no ad and let the band collapse.
              console.log('Give up on TC data load')
              adShown.value = false
              emit('rendered', false)
            } else {
              visibleAndScriptsLoadedTimer = window.setTimeout(() => {
                visibilityChanged(visible)
              }, 100)
            }
          }
        },
        [1, 2, 3]
      )
    }
  }
}

// We want to wait until an ad has been viewable for 100ms.  That reduces the impact of fast scrolling or
// redirects.
let checkStillVisibleTimer = null

async function checkStillVisible() {
  // Check if the ad is still visible after this delay, and no modal is open.
  console.log(
    'Check if ad still visible',
    isVisible.value,
    props.inModal,
    document.body.classList.contains('modal-open')
  )

  if (
    isVisible.value &&
    (props.inModal || !document.body.classList.contains('modal-open'))
  ) {
    // Check if we are showing ads.
    const configStore = useConfigStore()
    const showingAds = await configStore.fetch('ads_enabled')

    const myEmail = me.value?.email

    const runtimeConfig = useRuntimeConfig()
    // USER_SITE is a URL - 'https://www.ilovefreegle.org' by default and in the deployed
    // env - so the old test, `myEmail.includes(userSite)` with a `replace('www.', '')`
    // variant, could never match: no email address contains 'https://'. Ad suppression for
    // our own accounts was therefore dead in production. It only looked right in tests,
    // where the fixture set USER_SITE to a bare 'ilovefreegle.org'.
    //
    // Compare the email's DOMAIN against the site's HOST instead, allowing subdomains so
    // someone@mail.ilovefreegle.org still counts.
    const host = String(runtimeConfig.public.USER_SITE ?? '')
      .replace(/^https?:\/\//, '')
      .replace(/^www\./, '')
      .replace(/\/.*$/, '')
      .toLowerCase()
    const emailDomain = myEmail ? myEmail.split('@').pop().toLowerCase() : ''
    const isSystemAccount = Boolean(
      host && emailDomain && (emailDomain === host || emailDomain.endsWith('.' + host))
    )

    if (isSystemAccount) {
      console.log('Ads disabled as system account')
      emit('rendered', false)
    } else if (recentDonor.value) {
      console.log('Ads disabled as recent donor')
      emit('rendered', false)
    } else if (showingAds?.length && parseInt(showingAds[0].value)) {
      renderAd.value = true
    } else {
      console.log(
        'Ads disabled in server config - showing fallback',
        showingAds
      )
      useMiscStore().adsDisabled = true
      adShown.value = false
      emit('rendered', false)
      emit('disabled')
    }
  } else {
    emit('rendered', false)
  }
}

function rippleRendered(rendered) {
  // Report the truth. 8b8b18176 replaced this with "show a donate banner and claim
  // rendered:true", which is why an unfilled slot left a 123px band behind: the caller
  // reserves space on a true, and LayoutCommon's collapse (.adNotShown, padding-bottom 0)
  // only fires on stickyAdRendered === 0. Nothing to show means collapse, as it did before.
  adShown.value = rendered
  emit('rendered', rendered)
}

// When the ad (or its fallback — Jobs list, donate banner) is rendered we
// must allow pointer events so the link/ad is clickable. When the slot is
// still an empty placeholder (adShown=false) we set `none` so clicks pass
// through to any content behind. The previous mapping was inverted, which
// blocked clicks on Jobs links and footer ads once an ad resolved.
const passClicks = computed(() => {
  return adShown.value ? 'auto' : 'none'
})

onBeforeUnmount(() => {
  try {
    if (visibleAndScriptsLoadedTimer) {
      clearTimeout(visibleAndScriptsLoadedTimer)
    }
  } catch (e) {
    console.log('Exception in onBeforeUnmount', e)
  }
})
</script>
<style scoped lang="scss">
.pointer {
  pointer-events: v-bind(passClicks);
}
</style>
