<template>
  <!-- Only rendered inside the native Capacitor app -->
  <div v-if="mobileStore.isApp" class="settings-section">
    <div class="section-header">
      <v-icon icon="mobile-alt" class="section-icon" />
      <h2>App Notifications</h2>
    </div>

    <div class="section-content">
      <div class="option-row">
        <div class="option-info">
          <span class="option-label">Daily notification of new posts</span>
          <span class="option-desc">
            A daily push with new offers and wanteds near you
          </span>
        </div>
        <OurToggle
          v-model="dailyPostsPushLocal"
          :width="100"
          :sync="true"
          :labels="{ checked: 'On', unchecked: 'Off' }"
          :color="toggleColor"
          @change="changeDailyPostsPush"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useAuthStore } from '~/stores/auth'
import { useMobileStore } from '~/stores/mobile'
import OurToggle from '~/components/OurToggle'
import { useMe } from '~/composables/useMe'

const emit = defineEmits(['update'])

const authStore = useAuthStore()
const mobileStore = useMobileStore()

const { me } = useMe()

// Brand color for toggle switches (matches other settings sections)
const toggleColor = '#61AE24'

// Local reactive state — defaults to true when the key is absent (matches
// the default in auth.js notifications object).
const dailyPostsPushLocal = ref(true)

const changeDailyPostsPush = async (e) => {
  const settings = me.value.settings
  if (!settings.notifications) {
    settings.notifications = {}
  }
  settings.notifications.dailypostspush = e
  await authStore.saveAndGet({ settings })
  emit('update')
}

// Sync local ref whenever the auth user data is refreshed.
watch(
  () => me.value,
  (newVal) => {
    if (newVal) {
      // Fall back to true (the feature default) when the key is absent.
      dailyPostsPushLocal.value =
        newVal.settings?.notifications?.dailypostspush ?? true
    }
  },
  { immediate: true }
)
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.settings-section {
  background: white;
  border-radius: var(--radius-lg, 0.75rem);
  box-shadow: var(--shadow-md);
  margin-bottom: 1rem;
  overflow: hidden;
}

.section-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);

  h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: $color-green-background;
  }

  .section-icon {
    color: $color-green-background;
  }
}

.section-content {
  padding: 1rem 1.25rem;
}

.option-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 0;

  &:first-child {
    padding-top: 0;
  }

  &:last-child {
    padding-bottom: 0;
  }
}

.option-info {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.option-label {
  font-weight: 500;
  font-size: 0.95rem;
}

.option-desc {
  font-size: 0.8rem;
  color: var(--color-gray-600);
}
</style>
