<template>
  <b-modal
    ref="modal"
    size="lg"
    hide-header
    hide-footer
    no-stacking
    modal-class="jobs-followup-modal"
    @show="onShow"
  >
    <template #default>
      <div class="modal-inner">
        <!-- Close button first in tab order so keyboard users can dismiss immediately -->
        <button class="close-btn" aria-label="Close" @click="hide">
          <v-icon icon="times" />
        </button>

        <h5 class="modal-heading">More jobs near you</h5>

        <!-- Search input – filters modalJobs client-side, zero API cost -->
        <div class="search-box">
          <v-icon icon="search" class="search-icon" />
          <input
            v-model="searchQuery"
            type="text"
            class="search-input"
            placeholder="Search jobs..."
            aria-label="Search jobs"
          />
          <button
            v-if="searchQuery"
            class="search-clear"
            aria-label="Clear search"
            @click="searchQuery = ''"
          >
            <v-icon icon="times-circle" />
          </button>
        </div>

        <div v-if="visibleJobs.length" class="job-list">
          <JobOne
            v-for="(job, index) in visibleJobs"
            :id="job.id"
            :key="'modal-job-' + job.id"
            :summary="true"
            :context="placement"
            :position="index"
            :list-length="visibleJobs.length"
          />
        </div>
        <p v-else class="no-results">
          No jobs found matching "{{ searchQuery }}".
        </p>
      </div>
    </template>
  </b-modal>
</template>

<script setup>
import { computed, ref, defineAsyncComponent } from 'vue'
import { useJobStore } from '~/stores/job'
import { action } from '~/composables/useClientLog'
import { useOurModal } from '~/composables/useOurModal'

const JobOne = defineAsyncComponent(() => import('./JobOne'))

const props = defineProps({
  /* Job ids already visible in the triggering slot; excluded from the modal. */
  excludeIds: {
    type: Array,
    required: false,
    default: () => [],
  },
  /* Placement label threaded onto each job click for revenue attribution. */
  placement: {
    type: String,
    required: false,
    default: 'modal_more_jobs',
  },
})

/* Always-mounted; open only when the parent calls show(). */
const { modal, show: showmodal, hide } = useOurModal({ autoShow: false })

const jobStore = useJobStore()

const searchQuery = ref('')
/* Stored when show() is called so onShow can include them in the analytics event. */
const triggeredByJobId = ref(null)
const triggeredByContext = ref(null)

/* Default pool: ranked API order, exclude already-visible jobs, cap at 8. */
const modalJobs = computed(() => {
  return jobStore.list
    .filter((j) => !props.excludeIds.includes(j.id))
    .slice(0, 8)
})

/* When a query is active, search across ALL non-excluded jobs (not just the
 * first 8) so the user can find jobs that didn't make the initial cut. */
const visibleJobs = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) {
    return modalJobs.value
  }

  return jobStore.list
    .filter((j) => {
      if (props.excludeIds.includes(j.id)) {
        return false
      }
      /* Case-insensitive substring match across title, location and category. */
      const haystack = [j.title, j.location, j.category]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return haystack.includes(q)
    })
    .slice(0, 8)
})

/* Open the modal. Caller passes the id and context of the job that was clicked
 * so they can be included in the analytics event. Resets the search box on
 * each open so the previous query doesn't carry over. */
function show(jobId, context) {
  triggeredByJobId.value = jobId ?? null
  triggeredByContext.value = context ?? null
  searchQuery.value = ''
  showmodal()
}

/* Fire once when the b-modal finishes its show transition. */
function onShow() {
  action('jobs_modal_open', {
    triggered_by_job_id: triggeredByJobId.value,
    triggered_by_context: triggeredByContext.value,
    modal_job_count: modalJobs.value.length,
  })
}

defineExpose({ show, hide })
</script>

<style lang="scss">
/*
 * jobs-followup-modal: not scoped so Bootstrap's modal-class hook picks it up.
 * All other styles are scoped inside the component.
 */
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

@include media-breakpoint-down(sm) {
  .jobs-followup-modal .modal-dialog {
    position: fixed;
    bottom: 0;
    margin: 0;
    width: 100%;
    max-width: 100%;
  }

  .jobs-followup-modal .modal-content {
    border-radius: 12px 12px 0 0;
  }
}
</style>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'assets/css/_color-vars.scss';

.modal-inner {
  position: relative;
  padding: 1rem;
}

.close-btn {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  background: none;
  border: none;
  cursor: pointer;
  color: $gray-500;
  font-size: 1.1rem;
  line-height: 1;
  padding: 0.25rem;

  &:hover {
    color: $gray-800;
  }
}

.modal-heading {
  font-size: 1rem;
  font-weight: 600;
  color: $gray-800;
  margin: 0 0 0.75rem 0;
  padding-right: 2rem;
}

/* Search box */
.search-box {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border: 1px solid $gray-300;
  border-radius: 4px;
  padding: 0.4rem 0.6rem;
  margin-bottom: 0.75rem;
  background: $white;

  &:focus-within {
    border-color: $color-green-background;
    box-shadow: 0 0 0 2px rgba(45, 80, 22, 0.15);
  }
}

.search-icon {
  color: $gray-400;
  font-size: 0.85rem;
  flex-shrink: 0;
}

.search-input {
  flex: 1;
  border: none;
  outline: none;
  font-size: 0.875rem;
  color: $gray-800;
  background: transparent;
  min-width: 0;

  &::placeholder {
    color: $gray-400;
  }
}

.search-clear {
  background: none;
  border: none;
  cursor: pointer;
  color: $gray-400;
  padding: 0;
  font-size: 0.85rem;
  line-height: 1;
  flex-shrink: 0;

  &:hover {
    color: $gray-600;
  }
}

.job-list {
  :deep(.job-item) {
    margin-bottom: 0;
  }
}

.no-results {
  text-align: center;
  color: $gray-500;
  font-size: 0.875rem;
  padding: 1rem 0;
  margin: 0;
}
</style>
