<template>
  <div>
    <b-button v-if="!banned" variant="white" @click="ban">
      <v-icon icon="trash-alt" /> Ban
    </b-button>
    <b-button v-if="!spam" variant="white" @click="spamReport">
      <v-icon icon="ban" /> Report Spammer
    </b-button>
    <b-button v-if="supportOrAdmin" variant="white" @click="spamSafelist">
      <v-icon icon="check" /> Safelist
    </b-button>
    <b-button variant="white" @click="addAComment">
      <v-icon icon="tag" /> Add note
    </b-button>
    <ModBanMemberConfirmModal
      v-if="banConfirm"
      ref="banConfirmRef"
      :userid="userid"
      @confirm="banConfirmed"
    />
    <ModCommentAddModal
      v-if="showAddCommentModal"
      :userid="userid"
      @added="commentadded"
      @hidden="showAddCommentModal = false"
    />
    <ModSpammerReport
      v-if="showSpamModal"
      ref="spamConfirmRef"
      :userid="userid"
      :safelist="safelist"
    />
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import { useUserStore } from '~/stores/user'
import { useMemberStore } from '~/modtools/stores/member'
import { useSpammerStore } from '~/modtools/stores/spammer'
import { useMe } from '~/composables/useMe'
import { useModMe } from '~/modtools/composables/useModMe'

const props = defineProps({
  userid: {
    type: Number,
    required: true,
  },
  banned: {
    type: Boolean,
    required: false,
    default: false,
  },
  spammerid: {
    type: Number,
    required: false,
    default: null,
  },
})

const emit = defineEmits(['commentadded'])

const { $api } = useNuxtApp()
const { checkWork } = useModMe()
const memberStore = useMemberStore()
const userStore = useUserStore()
const spammerStore = useSpammerStore()
const { me, supportOrAdmin } = useMe()

const spam = computed(() => spammerStore.byId(props.spammerid))

const banConfirmRef = ref(null)
const spamConfirmRef = ref(null)

const banConfirm = ref(false)
const showAddCommentModal = ref(false)
const user = ref(null)
const showSpamModal = ref(false)
const safelist = ref(false)

async function fetchUser() {
  await userStore.fetch(props.userid, true)
  user.value = userStore.byId(props.userid)
}

async function ban() {
  if (!user.value) {
    await fetchUser()
  }

  banConfirm.value = true
  banConfirmRef.value?.show()
}

async function banConfirmed(reason) {
  await memberStore.ban(props.userid, reason)
  await $api.comment.add({
    userid: props.userid,
    user1: 'Banned by ' + me.value.displayname + ' reason: ' + reason,
    flag: true,
  })
  checkWork(true)
}

async function addAComment() {
  if (!user.value) {
    await fetchUser()
  }

  showAddCommentModal.value = true
}

async function commentadded() {
  await userStore.fetch(props.userid)

  emit('commentadded')
}

async function spamReport() {
  if (!user.value) {
    await fetchUser()
  }

  safelist.value = false
  showSpamModal.value = true
  spamConfirmRef.value?.show()
}

async function spamSafelist() {
  if (!user.value) {
    await fetchUser()
  }

  safelist.value = true
  showSpamModal.value = true
  spamConfirmRef.value?.show()
}
</script>
