<script setup lang="ts">
import { testConfigs, type TestType } from '~/types/test'
import { useTestStore } from '~/stores/tests'

const testStore = useTestStore()

const testTypes: TestType[] = ['go', 'spatial', 'routing', 'php', 'laravel', 'vitest', 'playwright']

onMounted(() => {
  // Refresh test statuses on mount
  for (const type of testTypes) {
    testStore.refreshTestStatus(type)
  }
})
</script>

<template>
  <div>
    <h2 class="h5 mb-4">Test Suites</h2>

    <div class="row row-cols-1 row-cols-lg-2 g-4">
      <div v-for="type in testTypes" :key="type" class="col">
        <TestingTestRunner :test-type="type" />
      </div>
    </div>
  </div>
</template>
