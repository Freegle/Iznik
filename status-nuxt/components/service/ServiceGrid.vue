<script setup lang="ts">
import type { ServiceConfig } from '~/types/service'

defineProps<{
  services: ServiceConfig[]
  title?: string
}>()
</script>

<template>
  <div>
    <h2 v-if="title" class="section-heading h5 mb-3">{{ title }}</h2>
    <div class="services-list">
      <ServiceCard v-for="service in services" :key="service.id" :service="service" />
    </div>
    <div v-if="services.length === 0" class="text-muted text-center py-4">
      No services in this category
    </div>
  </div>
</template>
