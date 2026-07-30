<template>
  <b-row>
    <b-col cols="12" xl="6" offset-xl="3" class="bottom verytop">
      <NoticeMessage
        v-if="me && me.bouncing"
        variant="danger"
        class="mb-3 text-center"
      >
        <v-icon icon="exclamation-triangle" />
        <template v-if="bouncingEmails.length">
          We can't send to your
          {{ bouncingEmails.length > 1 ? 'email addresses' : 'email address' }}
          <strong>{{ bouncingEmails.join(', ') }}</strong
          >. Please go to
          <nuxt-link no-prefetch to="/settings">Settings</nuxt-link>
          to fix or retry.
        </template>
        <template v-else>
          We can't send to your email address. Please go to
          <nuxt-link no-prefetch to="/settings">Settings</nuxt-link>
          to fix or retry.
        </template>
      </NoticeMessage>
    </b-col>
  </b-row>
</template>
<script setup>
import { useMe } from '~/composables/useMe'

const NoticeMessage = defineAsyncComponent(
  () => import('~/components/NoticeMessage')
)

const { me } = useMe()

// The specific address(es) that bounced, so we can name them in the banner.
// users_emails.bounced carries a timestamp for permanently-bounced addresses;
// internal (ourdomain) addresses are never shown to the member.
const bouncingEmails = computed(() => {
  const emails = me.value?.emails || []
  return emails.filter((e) => e.bounced && !e.ourdomain).map((e) => e.email)
})
</script>
<style scoped>
.bottom {
  position: fixed;
  bottom: 0;
}
</style>
