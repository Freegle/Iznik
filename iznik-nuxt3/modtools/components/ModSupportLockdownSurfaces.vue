<template>
  <div>
    <div
      v-for="s in surfaceOrder"
      :key="s.key"
      class="d-flex align-items-center gap-2 mb-2"
      :data-testid="'lockdown-surface-' + s.key"
    >
      <b-form-checkbox
        switch
        :model-value="!!surfaces[s.key]"
        :data-testid="'lockdown-surface-toggle-' + s.key"
        @update:model-value="(v) => onToggle(s.key, v)"
      >
        {{ s.label }} - {{ surfaces[s.key] ? 'held' : 'running' }}
      </b-form-checkbox>

      <b-badge
        :variant="surfaces[s.key] ? 'warning' : 'secondary'"
        :data-testid="'lockdown-surface-action-' + s.key"
      >
        {{ actionFor(s) }}
      </b-badge>

      <b-badge v-if="countFor(s.key) !== null" variant="secondary">
        {{ countFor(s.key) }} held
      </b-badge>

      <b-form-select
        v-if="s.key === 'chat' && surfaces.chat"
        :model-value="surfaces.chat_mode"
        :options="chatModeOptions"
        size="sm"
        style="width: 220px"
        data-testid="lockdown-chat-mode"
        @update:model-value="onChatMode"
      />
    </div>
  </div>
</template>

<script setup>
// plans/active/2026-09-27-lockdown-switch.md section 10.9: lifting order is
// people before content, content before mail, downloads last - "mods" is
// first because moderators need to be back in their queues before the
// queues fill (step 4), "export" is last (step 9). Held counts (10.9's "each
// with its held count from stats beside it") only exist for the three kinds
// the batch triages - chat, posts, chitchat (lockdown_holds.kind) - so mods,
// events, push, email and export never show one.
const props = defineProps({
  surfaces: {
    type: Object,
    default: () => ({}),
  },
  heldCounts: {
    type: Object,
    default: () => ({}),
  },
})

const emit = defineEmits(['toggle-surface', 'set-chat-mode'])

const surfaceOrder = [
  { key: 'mods', label: 'Moderators' },
  { key: 'chat', label: 'Chat' },
  { key: 'posts', label: 'Posts' },
  { key: 'chitchat', label: 'ChitChat' },
  { key: 'events', label: 'Events, noticeboards, stories' },
  { key: 'push', label: 'Push' },
  // plans/active/2026-09-27-lockdown-switch.md section 11.7: member email is
  // not generated while held and resumes from the watermarks on lift. The
  // row itself is named for the surface, like every other row - "held"/
  // "running" already says which state it is in - and only the lift action
  // carries the special wording, because that is the one that needs it.
  { key: 'email', label: 'Email', liftAction: 'Resume email' },
  { key: 'export', label: 'Export / downloads' },
]

const kindForSurface = {
  chat: 'chat',
  posts: 'post',
  chitchat: 'chitchat',
}

const chatModeOptions = [
  { value: 'hard', text: 'Hard - nothing processed, nobody reads anything' },
  { value: 'soft', text: 'Soft - triaged every minute' },
]

function countFor(key) {
  const kind = kindForSurface[key]
  if (!kind) return null
  return props.heldCounts?.[kind]?.count ?? 0
}

// The action a press on this row's switch would now take: held rows lift
// (email's lift resumes the mail queue, so it says that instead), running
// rows hold.
function actionFor(s) {
  return props.surfaces[s.key] ? s.liftAction || 'Lift' : 'Hold'
}

function onToggle(key, value) {
  emit('toggle-surface', key, value)
}

function onChatMode(value) {
  emit('set-chat-mode', value)
}
</script>
