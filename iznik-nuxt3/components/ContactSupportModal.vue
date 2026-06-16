<template>
  <b-modal
    ref="modal"
    scrollable
    title="Contact us to delete your account"
    modal-class="contact-support-modal"
    footer-class="contact-support-modal__footer"
  >
    <template #default>
      <div v-if="!emailSent && !showMailto">
        <p>Let's try to find your account. Enter your email:</p>
        <EmailValidator
          v-model:email="email"
          v-model:valid="emailValid"
          label=""
          class="contact-support-modal__email mb-2"
        />
        <NoticeMessage v-if="errored" variant="warning" class="mt-2">
          Something went wrong. Please try again or use the contact button
          below.
        </NoticeMessage>
      </div>
      <NoticeMessage v-else-if="emailSent" variant="primary">
        We've sent you a confirmation email. Please check your inbox and spam
        folder.
      </NoticeMessage>
      <div v-else-if="showMailto">
        <p>We couldn't find an account for that email address.</p>
        <p>Please contact our Support Volunteers to delete your account:</p>
      </div>
    </template>
    <template #footer>
      <b-button v-if="emailSent" variant="primary" @click="hide">
        Close
      </b-button>
      <template v-else-if="showMailto">
        <b-button variant="white" @click="hide"> Cancel </b-button>
        <a :href="mailtoHref" class="btn btn-primary" @click="hide">
          <v-icon icon="envelope" />
          <span class="ms-1">Contact us to delete your account</span>
        </a>
      </template>
      <template v-else>
        <b-button variant="white" @click="hide"> Cancel </b-button>
        <SpinButton
          icon-name="envelope"
          variant="primary"
          label="Send confirmation email"
          @handle="submit"
        />
      </template>
    </template>
  </b-modal>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '~/stores/auth'
import { useOurModal } from '~/composables/useOurModal'
import EmailValidator from '~/components/EmailValidator.vue'
import SpinButton from '~/components/SpinButton.vue'
import NoticeMessage from '~/components/NoticeMessage.vue'

const authStore = useAuthStore()
// Always mounted on the /unsubscribe page, so it must NOT auto-show on mount —
// it opens only when the user clicks "Contact us" (parent calls show()).
const { modal, show: showmodal, hide } = useOurModal({ autoShow: false })

const email = ref('')
const emailValid = ref(false)
const emailSent = ref(false)
const showMailto = ref(false)
const errored = ref(false)

const mailtoHref = computed(() => {
  const subject = encodeURIComponent('Delete my Freegle account')
  const triedEmail = email.value
    ? `\n\nThe email I tried was: ${email.value}`
    : ''
  const body = encodeURIComponent(
    `I would like to delete my Freegle account.${triedEmail}`
  )
  return `mailto:support@ilovefreegle.org?subject=${subject}&body=${body}`
})

async function submit(callback) {
  if (!emailValid.value || !email.value) {
    callback && callback()
    return
  }
  errored.value = false
  try {
    const ret = await authStore.unsubscribe(email.value.trim())
    if (ret.worked) {
      emailSent.value = true
    } else if (ret.unknown) {
      showMailto.value = true
    } else {
      // Network/API error — let them escalate to support.
      showMailto.value = true
    }
  } catch {
    errored.value = true
    showMailto.value = true
  }
  callback && callback()
}

function show() {
  email.value = ''
  emailValid.value = false
  emailSent.value = false
  showMailto.value = false
  errored.value = false
  showmodal()
}

defineExpose({ show })
</script>
<style lang="scss">
@import 'assets/css/_color-vars.scss';

.contact-support-modal__email input.email,
.contact-support-modal__email .form-control {
  border: 2px solid $color-success;
}

.contact-support-modal__footer {
  display: flex;
  justify-content: space-between;
}
</style>
