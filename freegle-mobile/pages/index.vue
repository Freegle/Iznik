<template>
  <LocationPicker @located="onLocated" />
</template>

<script setup>
import LocationPicker from '~/components/LocationPicker.vue'

const router = useRouter()

function onLocated(location) {
  localStorage.setItem('freegle-mobile-location', JSON.stringify(location))
  router.push('/feed')
}
</script>
