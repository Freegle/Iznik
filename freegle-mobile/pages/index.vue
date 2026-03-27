<template>
  <OnboardingWizard v-if="showOnboarding" @done="completeOnboarding" />
  <LocationPicker v-else @located="onLocated" />
</template>

<script setup>
import { ref, onMounted } from 'vue'
import OnboardingWizard from '~/components/OnboardingWizard.vue'
import LocationPicker from '~/components/LocationPicker.vue'

const router = useRouter()
const showOnboarding = ref(false)

onMounted(() => {
  // Show onboarding on first ever visit
  if (!localStorage.getItem('freegle-mobile-onboarded')) {
    showOnboarding.value = true
  }

  // If already located, go straight to feed
  if (localStorage.getItem('freegle-mobile-location')) {
    router.push('/feed')
  }
})

function completeOnboarding() {
  localStorage.setItem('freegle-mobile-onboarded', '1')
  showOnboarding.value = false
}

function onLocated(location) {
  localStorage.setItem('freegle-mobile-location', JSON.stringify(location))
  router.push('/feed')
}
</script>
