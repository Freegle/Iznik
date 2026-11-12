<template>
  <span :class="'share-network-' + network" @click="share"><slot /></span>
</template>
<script setup>
import { onBeforeUnmount } from 'vue'
import { SHARE_LINKS, shareLink } from '~/composables/useShareLink'

// A share link to Facebook, X, WhatsApp or email. It replaces the vue-social-sharing
// component, which kept the window it opened in reactive component data: once that window
// moved to another origin, Vue reading `__v_isRef` off it threw a SecurityError from the
// library's own timer (Sentry 7372971419, 54 events in 14 days). The window here lives in a plain
// variable that Vue never sees.

const props = defineProps({
  network: {
    type: String,
    required: true,
    validator: (value) => Object.keys(SHARE_LINKS).includes(value),
  },
  url: {
    type: String,
    default: '',
  },
  title: {
    type: String,
    default: '',
  },
  description: {
    type: String,
    default: '',
  },
  hashtags: {
    type: String,
    default: '',
  },
})

// Opened when the link is used, and closed when the sharing window is. The listener decides what
// each means (the micro-volunteering task is done once the window closes).
const emit = defineEmits(['open', 'close'])

let popup = null
let timer = null

onBeforeUnmount(() => clearInterval(timer))

function share() {
  const link = shareLink(props)

  if (props.network === 'email') {
    window.location.href = link
    emit('open')
    return
  }

  clearInterval(timer)
  popup = window.open(
    link,
    'sharer',
    'status=no,height=436,width=626,resizable=yes,toolbar=no,menubar=no,scrollbars=no,location=no,directories=no'
  )
  emit('open')

  if (popup) {
    timer = setInterval(() => {
      let closed = true

      try {
        closed = !popup || popup.closed
      } catch (e) {
        // A window that has moved to another origin can refuse to answer; treat it as still open.
        closed = false
      }

      if (closed) {
        clearInterval(timer)
        popup = null
        emit('close')
      }
    }, 500)
  }
}
</script>
