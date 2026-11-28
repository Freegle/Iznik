<template>
  <NoticeMessage>
    <div v-if="refused">
      <p>{{ refused }}</p>
    </div>
    <div v-else-if="showConfirm">
      <p>We've sent you a verification mail.</p>
      <p>Don't forget to check your spam folder!</p>
      <p>
        Once you've merged the accounts, you can come back here to continue your
        post.
      </p>
    </div>
    <div v-else>
      <p>
        {{ theirs }} belongs to
        <span v-if="me?.email">
          a different account from the one you're using right now ({{
            me.email
          }})
        </span>
        <span v-else>an existing account</span>. But don't worry! You probably
        have two accounts, and we can merge them together.
      </p>
      <p>
        Click this button and we'll send an email to {{ theirs }} to confirm
        that you want to merge the accounts.
      </p>
      <b-button size="lg" class="mb-2" variant="white" @click="requestMerge"
        >Request merge</b-button
      >
    </div>
  </NoticeMessage>
</template>
<script setup>
import { ref, computed } from 'vue'
import NoticeMessage from './NoticeMessage'
import { useAuthStore } from '~/stores/auth'

const props = defineProps({
  theirs: {
    type: String,
    required: true,
  },
})

const authStore = useAuthStore()
const me = computed(() => authStore.user)
const showConfirm = ref(false)
// Why the server refused the address, e.g. a TrashNothing one (ret 12), which is never merged.
const refused = ref(null)

async function requestMerge() {
  const data = await authStore.saveEmail(props.theirs)

  console.log('Merge data', data)

  if (data?.ret === 12) {
    refused.value = data.status
    return
  }

  showConfirm.value = true
}
</script>
