<template>
  <table class="min-w-full divide-y divide-gray-200">
    <thead class="bg-gray-50">
      <tr>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Component Name
        </th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Category
        </th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Usage Count
        </th>
      </tr>
    </thead>
    <tbody class="bg-white divide-y divide-gray-200">
      <tr v-for="component in components" :key="component.id" class="hover:bg-gray-50">
        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
          {{ component.name }}
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
          <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold" :class="getCategoryBadgeClass(component.category)">
            {{ formatCategory(component.category) }}
          </span>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
          {{ component.usageCount }}
        </td>
      </tr>
      <tr v-if="components.length === 0">
        <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">
          No components in this category
        </td>
      </tr>
    </tbody>
  </table>
</template>

<script setup lang="ts">
defineProps<{
  components: Array<{
    id: number
    name: string
    category: string
    usageCount: number
  }>
}>()

const formatCategory = (category: string) => {
  return category
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

const getCategoryBadgeClass = (category: string) => {
  switch (category) {
    case 'primary_eee':
      return 'bg-blue-100 text-blue-800'
    case 'supplementary_eee':
      return 'bg-amber-100 text-amber-800'
    case 'non_electrical':
      return 'bg-gray-100 text-gray-800'
    default:
      return 'bg-gray-100 text-gray-800'
  }
}
</script>
