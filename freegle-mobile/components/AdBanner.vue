<template>
  <div class="ad-banner">
    <a
      v-if="currentJob"
      :href="currentJob.clickurl || '#'"
      target="_blank"
      rel="noopener"
      class="ad-banner__job"
      @click="logClick"
    >
      <span class="ad-banner__label">Job</span>
      <span class="ad-banner__title">{{ currentJob.title }}</span>
      <span class="ad-banner__company">{{ currentJob.company }}</span>
    </a>
    <div v-else class="ad-banner__placeholder">
      <span class="ad-banner__empty-label">Ad</span>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useJobStore } from '~/stores/job'

const jobStore = useJobStore()
const currentJob = ref(null)
const jobIndex = ref(0)

onMounted(async () => {
  try {
    const jobs = await jobStore.fetch(51.5, -1.5)
    if (jobs?.length) {
      currentJob.value = jobs[0]
      setInterval(() => {
        jobIndex.value = (jobIndex.value + 1) % jobs.length
        currentJob.value = jobs[jobIndex.value]
      }, 15000)
    }
  } catch (e) {
    /* ad blocked or no jobs */
  }
})

function logClick() {
  if (currentJob.value) {
    jobStore.log({ id: currentJob.value.id, action: 'click' })
  }
}
</script>

<style scoped lang="scss">
.ad-banner {
  height: var(--mobile-ad-height, 50px);
  border-top: 1px solid #e8e8e8;
  background: #fafafa;
  flex-shrink: 0;
  display: flex;
  align-items: center;

  &__job {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 12px;
    width: 100%;
    height: 100%;
    text-decoration: none;
    color: inherit;
    overflow: hidden;
  }

  &__label {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #fff;
    background: #2563eb;
    padding: 2px 5px;
    border-radius: 3px;
    flex-shrink: 0;
  }

  &__title {
    font-size: 13px;
    font-weight: 600;
    color: #333;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
  }

  &__company {
    font-size: 11px;
    color: #888;
    white-space: nowrap;
    flex-shrink: 0;
  }

  &__placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
  }

  &__empty-label {
    font-size: 12px;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
}
</style>
