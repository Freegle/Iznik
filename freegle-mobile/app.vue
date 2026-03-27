<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>

<script setup>
import { useMessageStore } from '~/stores/message'
import { useGroupStore } from '~/stores/group'
import { useUserStore } from '~/stores/user'
import { useChatStore } from '~/stores/chat'
import { useAuthStore } from '~/stores/auth'
import { useJobStore } from '~/stores/job'
import { useMiscStore } from '~/stores/misc'

const runtimeConfig = useRuntimeConfig()

const messageStore = useMessageStore()
const groupStore = useGroupStore()
const userStore = useUserStore()
const chatStore = useChatStore()
const authStore = useAuthStore()
const jobStore = useJobStore()
const miscStore = useMiscStore()

miscStore.init(runtimeConfig)
messageStore.init(runtimeConfig)
groupStore.init(runtimeConfig)
userStore.init(runtimeConfig)
chatStore.init(runtimeConfig)
authStore.init(runtimeConfig)
jobStore.init(runtimeConfig)
</script>
