<template>
  <span
    title="Platform Status - click for more info"
    class="clickme"
    @click="clicked"
  >
    <span v-if="!tried" class="trying" />
    <span v-else-if="error" class="error" />
    <span v-else-if="warning && supportOrAdmin" class="warning" />
    <span v-else class="fine" />
    <b-modal
      v-if="showModal"
      id="statusmmodal"
      v-model="showModal"
      no-stacking
      size="lg"
      :title="'Platform Status: ' + headline"
    >
      <template #default>
        <NoticeMessage
          v-if="(warning || error) && supportOrAdmin"
          variant="warning"
          class="mb-2"
        >
          There is a problem. Each line below names the scheduled job whose work
          has not happened, and what is missing. Please alert
          geeks@ilovefreegle.org if this persists for more than an hour.
        </NoticeMessage>
        <NoticeMessage v-else-if="error" variant="warning" class="mb-2">
          There's a problem, and parts of the system may not be working. The
          Geeks will be on the case.
        </NoticeMessage>
        <NoticeMessage v-else-if="warning" variant="warning" class="mb-2">
          There's a problem, but the system should still be working. The Geeks
          will be on the case.
        </NoticeMessage>
        <NoticeMessage v-else variant="primary">
          Everything seems fine.
        </NoticeMessage>
        <div v-if="status && status.info">
          <div v-for="(stat, server) in status.info" :key="server">
            <div v-if="stat.warning" class="d-flex justify-content-between">
              <strong>{{ server }}</strong>
              <em>{{ stat.warningtext }}</em>
            </div>
            <div v-if="stat.error" class="d-flex justify-content-between">
              <strong>{{ server }}</strong>
              <em>{{ stat.errortext }}</em>
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <b-button variant="white" @click="showModal = false"> Close </b-button>
      </template>
    </b-modal>
  </span>
</template>
<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useNuxtApp } from '#app'
import { useMe } from '~/composables/useMe'

const { $api } = useNuxtApp()
const { supportOrAdmin } = useMe()

const status = ref(null)
const updated = ref(null)
const tried = ref(false)
const showModal = ref(false)
let timer = null

const outOfDate = computed(() => {
  // Check if we've managed to get it recently.
  return !updated.value || Date.now() - updated.value >= 1000 * 600
})

const error = computed(() => (status.value ? status.value.error : false))

const warning = computed(() => {
  return outOfDate.value || (status.value && status.value.warning)
})

const headline = computed(() => {
  if (outOfDate.value) {
    return 'Not sure'
  } else if (error.value) {
    return 'Error'
  } else if (warning.value) {
    return 'Warning'
  } else {
    return 'Fine'
  }
})

// A status we could not obtain is worth showing, and worth showing WITH a reason.
// The old code fell back to a bare warning flag, so the modal rendered "There is
// a problem" above an empty info block and told nobody anything.
function feedProblem(detail) {
  return {
    ret: 1,
    error: false,
    warning: true,
    info: {
      'Status feed': {
        warning: true,
        warningtext: detail || 'Platform status is currently unavailable.',
      },
    },
  }
}

async function checkStatus() {
  try {
    const fetched = await $api.status.fetch()

    // Stamp whenever the API answered, whatever it said. outOfDate means "we
    // cannot reach the status API", not "the API told us something we did not
    // like". Conflating the two pinned the headline to "Not sure" for a month
    // while the API was answering perfectly promptly, with ret 1.
    updated.value = Date.now()

    status.value = fetched.ret === 0 ? fetched : feedProblem(fetched.status)
  } catch (err) {
    console.warn('Status API error:', err)
    status.value = feedProblem('Cannot reach the status API.')
  }

  tried.value = true

  timer = setTimeout(checkStatus, 30000)
}

function clicked(e) {
  showModal.value = true
  e.preventDefault()
  e.stopPropagation()
}

onMounted(() => {
  checkStatus()
})

onBeforeUnmount(() => {
  if (timer) {
    clearTimeout(timer)
  }
})
</script>
<style scoped lang="scss">
.trying {
  border-radius: 50%;
  width: 20px;
  height: 20px;
  background-color: $color-gray--light;
  display: block;
}

.error {
  border-radius: 50%;
  width: 20px;
  height: 20px;
  background-color: $color-red;
  display: block;
}

.warning {
  border-radius: 50%;
  width: 20px;
  height: 20px;
  background-color: $color-orange--dark;
  display: block;
}

.fine {
  border-radius: 50%;
  width: 20px;
  height: 20px;
  background-color: $color-green-background;
  display: block;
}
</style>
