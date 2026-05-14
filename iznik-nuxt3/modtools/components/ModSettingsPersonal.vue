<template>
  <div class="scrollinplace">
    <b-form-group label="Your visible name">
      <b-form-text class="mb-2">
        This is your name as displayed publicly to other users, including in the
        <em>$myname</em> substitution string.
      </b-form-text>
      <b-input-group>
        <b-form-input v-model="me.displayname" placeholder="Your name" />
        <slot name="append">
          <SpinButton
            variant="white"
            icon-name="save"
            label="Save"
            @handle="saveName"
          />
        </slot>
      </b-input-group>
    </b-form-group>
    <b-form-group label="Your email address">
      <EmailConfirmModal
        v-if="showEmailConfirmModal"
        @hidden="showEmailConfirmModal = false"
      />
      <b-form-text class="mb-2">
        Anything we mail to you, we'll mail to this email address.
      </b-form-text>
      <b-input-group id="input-email">
        <b-form-input
          v-model="me.email"
          placeholder="Your email"
          label="Your email address"
          type="email"
        />
        <slot name="append">
          <SpinButton
            variant="white"
            icon-name="save"
            label="Save"
            @handle="saveEmail"
          />
        </slot>
      </b-input-group>
    </b-form-group>
    <b-form-group label="Moderation Notifications (Active)">
      <b-form-text class="mb-2">
        For groups that you're an active mod on, we will mail you when there is
        moderation work to do which has been outstanding for more than a certain
        time. You can control the frequency or disable this here. We only mail
        you between 8am and 10pm.
      </b-form-text>
      <b-form-select
        v-model="modnotifs"
        :options="modNotifOptions"
        class="mb-2 fw-bold"
      />
    </b-form-group>
    <b-form-group label="Moderation Notifications (Backup)">
      <b-form-text class="mb-2">
        This is for groups where you're a backup mod. You'd usually set this to
        a higher value than the previous setting so that the active mods will
        get notified first.
      </b-form-text>
      <b-form-select
        v-model="backupmodnotifs"
        :options="modNotifOptions"
        class="mb-2 fw-bold"
      />
    </b-form-group>
    <b-form-group label="Play Beep">
      <b-form-text class="mb-2">
        Play beep when new ModTools work arrives.
      </b-form-text>
      <OurToggle
        v-model="beep"
        class="mt-2"
        :height="30"
        :width="150"
        :font-size="14"
        :sync="true"
        :labels="{ checked: 'Play beep', unchecked: 'Stay quiet' }"
        variant="modgreen"
      />
    </b-form-group>
    <b-form-group label="Show me as a volunteer?">
      <b-form-text class="mb-2">
        We can show members who the volunteers on a group are, to make it seem
        more friendly. You can choose whether we show you.
      </b-form-text>
      <OurToggle
        v-model="showme"
        class="mt-2"
        :height="30"
        :width="150"
        :font-size="14"
        :sync="true"
        :labels="{ checked: 'Show me', unchecked: 'Hide me' }"
        variant="modgreen"
      />
    </b-form-group>
    <b-form-group label="Email me about ChitChat?">
      <b-form-text class="mb-2">
        Your members may post in the ChitChat, perhaps to introduce themselves,
        or perhaps because they have problems. Replying to these posts helps
        them and makes Freegle friendlier. We only mail you about communities
        you are an active mod on.
      </b-form-text>
      <OurToggle
        v-model="modnotifnewsfeed"
        class="mt-2"
        :height="30"
        :width="150"
        :font-size="14"
        :sync="true"
        :labels="{ checked: 'Mail me', unchecked: 'Don\'t mail' }"
        variant="modgreen"
      />
    </b-form-group>
    <b-form-group label="Enter send vs newline">
      <b-form-text class="mb-2">
        On ModTools, normally enter/return adds a new line. This the opposite
        way round from on the Freegle site, where sending the message is the
        best compromise option given the limitations of what is technically
        possible for websites when used from mobiles. If you prefer enter to
        send on ModTools too, you can change the setting on this device.
      </b-form-text>
      <OurToggle
        v-model="enterAddsNewLine"
        class="mt-2"
        :height="30"
        :width="150"
        :font-size="14"
        :sync="true"
        :labels="{ checked: 'Send message', unchecked: 'Insert new line' }"
        variant="modgreen"
      />
    </b-form-group>
    <div v-if="mobileStore.isApp" class="mt-3">
      <h5>Push Notifications (App Debug)</h5>
      <b-form-group label="Current FCM token">
        <b-form-text class="mb-1">
          The push notification token registered with this device.
        </b-form-text>
        <div class="font-monospace small text-break border rounded p-2 bg-light">
          {{ mobileStore.mobilePushId || 'None (not yet registered)' }}
        </div>
      </b-form-group>
      <b-form-group label="Token sent to server">
        <div class="font-monospace small text-break border rounded p-2 bg-light">
          {{ mobileStore.acceptedMobilePushId || 'None (not yet sent)' }}
        </div>
      </b-form-group>
      <b-form-group label="Token matches">
        <span
          :class="
            mobileStore.mobilePushId &&
            mobileStore.mobilePushId === mobileStore.acceptedMobilePushId
              ? 'text-success'
              : 'text-danger'
          "
        >
          {{
            mobileStore.mobilePushId &&
            mobileStore.mobilePushId === mobileStore.acceptedMobilePushId
              ? 'Yes — server has current token'
              : 'No — server has stale or no token'
          }}
        </span>
      </b-form-group>
      <SpinButton
        variant="primary"
        icon-name="bell"
        label="Send token to server now"
        @handle="resendPushToken"
      />
      <div v-if="pushResult" class="mt-2 small" :class="pushResultClass">
        {{ pushResult }}
      </div>
    </div>
    <h5 class="mt-3">Useful Links</h5>
    <p>
      <ExternalLink
        href="https://wiki.ilovefreegle.org/Freegle_Volunteer_Agreement"
        >Freegle Volunteer Agreement</ExternalLink
      >
      &middot;
      <ExternalLink href="https://wiki.ilovefreegle.org/Trash_Nothing"
        >Trash Nothing</ExternalLink
      >
    </p>
    <h5 class="mt-3">Unsubscribe</h5>
    <p>
      If you want to completely leave ModTools and Freegle and remove all your
      data, click here.
    </p>
    <b-button variant="primary" to="/unsubscribe"> Unsubscribe </b-button>
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '~/stores/auth'
import { useMiscStore } from '@/stores/misc'
import { useMobileStore } from '~/stores/mobile'
import { useMe } from '~/composables/useMe'

const authStore = useAuthStore()
const miscStore = useMiscStore()
const mobileStore = useMobileStore()
const { me } = useMe()

const pushResult = ref(null)
const pushResultClass = ref('')

async function resendPushToken(callback) {
  pushResult.value = null
  try {
    if (!mobileStore.mobilePushId) {
      pushResult.value = 'No token available — push notifications may not be enabled.'
      pushResultClass.value = 'text-danger'
      callback()
      return
    }
    // Force resend by clearing the accepted token.
    mobileStore.acceptedMobilePushId = null
    await authStore.savePushId()
    pushResult.value = 'Sent OK — server now has the current token.'
    pushResultClass.value = 'text-success'
  } catch (e) {
    pushResult.value = 'Error: ' + (e?.message || String(e))
    pushResultClass.value = 'text-danger'
  }
  callback()
}

const showEmailConfirmModal = ref(false)

const modNotifOptions = [
  { text: 'Daily', value: 24 },
  { text: 'After 12 hours', value: 12 },
  { text: 'After 4 hours', value: 4 },
  { text: 'After 2 hours', value: 2 },
  { text: 'After 1 hour', value: 1 },
  { text: 'Immediately', value: 0 },
  { text: 'Never', value: -1 },
]

const beep = computed({
  get() {
    return Object.keys(me.value.settings).includes('playbeep')
      ? Boolean(me.value.settings.playbeep)
      : true
  },
  set(newval) {
    saveSetting('playbeep', newval)
  },
})

const showme = computed({
  get() {
    return Object.keys(me.value.settings).includes('showmod')
      ? Boolean(me.value.settings.showmod)
      : true
  },
  set(newval) {
    saveSetting('showmod', newval)
  },
})

const modnotifnewsfeed = computed({
  get() {
    return Object.keys(me.value.settings).includes('modnotifnewsfeed')
      ? Boolean(me.value.settings.modnotifnewsfeed)
      : true
  },
  set(newval) {
    saveSetting('modnotifnewsfeed', newval)
  },
})

const modnotifs = computed({
  get() {
    return Object.keys(me.value.settings).includes('modnotifs')
      ? parseInt(me.value.settings.modnotifs)
      : 4
  },
  set(newval) {
    saveSetting('modnotifs', newval)
  },
})

const backupmodnotifs = computed({
  get() {
    return Object.keys(me.value.settings).includes('backupmodnotifs')
      ? parseInt(me.value.settings.backupmodnotifs)
      : 12
  },
  set(newval) {
    saveSetting('backupmodnotifs', newval)
  },
})

const enterAddsNewLine = computed({
  get() {
    return miscStore?.get('enternewlinemt')
  },
  set(newval) {
    miscStore.set({
      key: 'enternewlinemt',
      value: newval,
    })
  },
})

async function saveName(callback) {
  await authStore.saveAndGet({
    displayname: me.value.displayname,
  })
  callback()
}

async function saveEmail(callback) {
  if (me.value.email) {
    const data = await authStore.saveEmail(me.value.email)

    if (data && data.ret === 10) {
      showEmailConfirmModal.value = true
    }
  }
  callback()
}

async function saveSetting(name, val) {
  const settings = me.value.settings
  settings[name] = val
  await authStore.saveAndGet({
    settings,
  })
}
</script>
<style scoped lang="scss">
//@import 'color-vars';

input,
select {
  max-width: 300px;
}
</style>
