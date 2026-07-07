<template>
  <div
    v-if="location && list.length"
    class="jobs-slot"
    :style="{
      maxHeight: maxHeight,
      minHeight: minHeight,
      maxWidth: maxWidth,
      minWidth: minWidth,
    }"
  >
    <NoticeMessage v-if="blocked" variant="warning" class="d-none">
      <p>It looks like you're blocking job ads. Please consider donating:</p>
      <donation-button />
    </NoticeMessage>
    <div v-else>
      <div v-if="!hideHeader" class="jobs-slot-header">
        <v-icon icon="briefcase" class="jobs-slot-icon" />
        <span>Jobs near you</span>
        <nuxt-link to="/jobs" class="jobs-slot-more">
          See all <v-icon icon="chevron-right" />
        </nuxt-link>
      </div>
      <div
        class="jobs-slot-list"
        :class="{ 'jobs-slot-list--horizontal': !listOnly }"
      >
        <JobOne
          v-for="(job, index) in displayedJobs"
          :id="job.id"
          :key="'job-' + job.job_reference"
          :summary="true"
          bg-colour="dark green"
          :position="index"
          :list-length="displayedJobs.length"
          :context="placement"
          @clicked="onJobClicked"
        />
      </div>
    </div>
    <!-- Always mounted; shown on-demand when a job is clicked and the cap allows. -->
    <JobsFollowUpModal
      ref="followUpModal"
      :exclude-ids="displayedJobIds"
      :placement="'modal_more_jobs'"
    />
  </div>
</template>
<script setup>
import {
  computed,
  ref,
  onMounted,
  onBeforeUnmount,
  defineAsyncComponent,
} from 'vue'
import { useJobStore } from '~/stores/job'
import { useAuthStore } from '~/stores/auth'
import { useJobsFollowUpModal } from '~/composables/useJobsFollowUpModal'
const JobOne = defineAsyncComponent(() => import('./JobOne'))
const JobsFollowUpModal = defineAsyncComponent(() =>
  import('./JobsFollowUpModal')
)
const NoticeMessage = defineAsyncComponent(() => import('./NoticeMessage'))
const DonationButton = defineAsyncComponent(() => import('./DonationButton'))

const props = defineProps({
  // Which slot this instance is mounted in (sticky_footer_mobile/desktop,
  // sidebar_left/right, ...). Threaded onto each job's click/impression so
  // per-placement performance is measurable. Defaults to the legacy 'daslot'.
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
  hideHeader: {
    type: Boolean,
    default: false,
  },
  listOnly: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['rendered', 'borednow'])

const jobStore = useJobStore()
const authStore = useAuthStore()
const { shouldShowModal, recordShown } = useJobsFollowUpModal()

const followUpModal = ref(null)

const me = authStore.user
const lat = me?.lat
const lng = me?.lng

const location = computed(() => me?.settings?.mylocation?.name || null)

if (location.value && lat && lng) {
  try {
    await jobStore.fetch(lat, lng)
  } catch (e) {
    console.log('Jobs fetch failed', e)
  }
}

let refreshTimer = null
const AD_REFRESH_TIMEOUT = 31000

onMounted(() => {
  emit('rendered', true)
  refreshTimer = setTimeout(() => {
    // We only show the jobs for a while.  If people don't engage with them on initial page load they're not likely
    // to, so we might as well shift to showing other ads so that we get some revenue.
    emit('borednow')
  }, AD_REFRESH_TIMEOUT)
})

onBeforeUnmount(() => {
  if (refreshTimer) {
    clearTimeout(refreshTimer)
  }
})

const blocked = computed(() => {
  return jobStore.blocked
})

const list = computed(() => {
  // Return the list in a random order - we might have multiple ad slots per page.  By taking the top 20 we've
  // already selected a set which is a balance between close and well-paid.
  const list = jobStore?.list.slice(0, 20)
  for (let i = list.length - 1; i >= 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    const temp = list[i]
    list[i] = list[j]
    list[j] = temp
  }

  return list
})

const displayedJobs = computed(() => {
  return props.listOnly ? list.value.slice(0, 10) : list.value.slice(0, 20)
})

/* IDs of the jobs currently displayed in this slot, passed to the modal so
 * it can exclude them and show different ads. */
const displayedJobIds = computed(() => displayedJobs.value.map((j) => j.id))

/* Called when a JobOne inside this slot emits 'clicked'. Check the frequency
 * cap; if allowed, open the follow-up modal with the remaining jobs. */
function onJobClicked(clickedId) {
  if (!shouldShowModal()) {
    return
  }
  followUpModal.value?.show(clickedId, props.placement)
  recordShown()
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'assets/css/_color-vars.scss';

.jobs-slot {
  width: 100%;
  /* Muted (not pure white) so the job-ads block reads as distinct sponsored content and
     separates from the white nav bar that can sit directly above it on Browse. */
  background: $color-gray--lighter;
  border: 1px solid $gray-200;
  overflow-y: auto;
}

.jobs-slot-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 0.75rem;
  background: $gray-100;
  color: $gray-700;
  font-weight: 600;
  font-size: 0.85rem;
  border-bottom: 1px solid $gray-200;
}

.jobs-slot-icon {
  font-size: 0.9rem;
  color: $color-gray--normal;
}

.jobs-slot-more {
  display: flex;
  align-items: center;
  gap: 0.2rem;
  margin-left: auto;
  color: $gray-600;
  font-size: 0.75rem;
  font-weight: 400;
  text-decoration: none;
  transition: all var(--transition-fast);

  &:hover {
    color: $gray-800;
    text-decoration: none;
  }
}

.jobs-slot-list {
  :deep(.job-item) {
    margin-bottom: 0;
  }

  &--horizontal {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0;

    @media (min-width: 480px) {
      grid-template-columns: repeat(3, 1fr);
    }

    @media (min-width: 768px) {
      grid-template-columns: repeat(4, 1fr);
    }

    @media (min-width: 992px) {
      grid-template-columns: repeat(5, 1fr);
    }

    :deep(.job-summary) {
      border-bottom: 1px solid $gray-200;
      border-right: 1px solid $gray-200;
    }
  }
}
</style>
