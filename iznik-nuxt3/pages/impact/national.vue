<template>
  <div class="impact-page">
    <div class="impact-controls no-print">
      <label>
        Year:
        <select v-model.number="year">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
      </label>
      <b-button variant="primary" size="sm" @click="reload">Update</b-button>
    </div>

    <div v-if="loading" class="impact-loading">
      <Spinner :size="40" />
      <p>Crunching the numbers…</p>
    </div>

    <div v-else class="impact-slide-wrap">
      <NationalSlide
        :year="year"
        :total-weight="totalWeight"
        :total-benefit="totalBenefit"
        :total-c-o2="totalCO2"
        :total-gifts="totalGifts"
        :total-members="totalMembers"
        :group-count="groupCount"
        :scale="slideScale"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, useRoute, useHead, useRuntimeConfig } from '#imports'
import Spinner from '~/components/Spinner.vue'
import NationalSlide from '~/components/impact/NationalSlide.vue'
import { useNationalImpact } from '~/composables/useNationalImpact'
import { buildHead } from '~/composables/useBuildHead'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()

useHead(
  buildHead(
    route,
    runtimeConfig,
    'National Impact',
    'Freegle national impact report'
  )
)

const currentYear = new Date().getFullYear()
const year = ref(currentYear - 1)

const yearOptions = computed(() => {
  const opts = []
  for (let y = currentYear - 1; y >= 2015; y--) {
    opts.push(y)
  }
  return opts
})

const { loading, totalWeight, totalBenefit, totalCO2, totalGifts, totalMembers, groupCount, fetchStats } =
  useNationalImpact(year)

const slideScale = computed(() => {
  if (process.server) return 0.45
  const available = Math.min(window.innerWidth - 40, 960)
  return Math.min(0.45, available / 2000)
})

async function reload() {
  await fetchStats()
}

onMounted(async () => {
  await fetchStats()
})
</script>

<style scoped>
.impact-page {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 20px;
  background: #555;
  min-height: 100vh;
}

.impact-controls {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 14px;
  background: rgba(0, 0, 0, 0.25);
  border-radius: 8px;
  padding: 6px 14px;
  font-size: 12px;
  color: #eee;
}

.impact-controls select {
  background: white;
  border: none;
  border-radius: 4px;
  padding: 2px 7px;
  font-size: 12px;
  color: #333;
}

.impact-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-top: 40px;
  color: #eee;
}

@media print {
  .no-print {
    display: none !important;
  }

  .impact-page {
    padding: 0;
    background: none;
  }
}
</style>
