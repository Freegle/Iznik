<template>
  <div class="location-picker">
    <div class="location-picker__panel">
      <img
        src="/icon.png"
        alt="Freegle"
        class="location-picker__logo"
      />

      <h1 class="location-picker__title">
        Freegle
      </h1>

      <p class="location-picker__subtitle">
        Free stuff near you
      </p>

      <button
        class="location-picker__gps-btn"
        :disabled="locating"
        @click="useGps"
      >
        {{ locating ? 'Locating...' : 'Use my location' }}
      </button>

      <div class="location-picker__divider">
        <span>or</span>
      </div>

      <form
        class="location-picker__postcode-row"
        @submit.prevent="submitPostcode"
      >
        <input
          v-model="postcode"
          type="text"
          class="location-picker__input"
          placeholder="Enter postcode"
        />
        <button
          type="submit"
          class="location-picker__go-btn"
          :disabled="!postcode.trim()"
        >
          Go
        </button>
      </form>

      <p
        v-if="error"
        class="location-picker__error"
      >
        {{ error }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const emit = defineEmits(['located'])

const postcode = ref('')
const error = ref('')
const locating = ref(false)

function useGps() {
  if (!navigator.geolocation) {
    error.value = 'Geolocation is not supported by your browser'
    return
  }

  error.value = ''
  locating.value = true

  navigator.geolocation.getCurrentPosition(
    (position) => {
      locating.value = false
      emit('located', {
        lat: position.coords.latitude,
        lng: position.coords.longitude,
        type: 'gps',
      })
    },
    (err) => {
      locating.value = false
      if (err.code === err.PERMISSION_DENIED) {
        error.value = 'Location permission was denied'
      } else if (err.code === err.POSITION_UNAVAILABLE) {
        error.value = 'Location information is unavailable'
      } else if (err.code === err.TIMEOUT) {
        error.value = 'Location request timed out'
      } else {
        error.value = 'Unable to determine your location'
      }
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 }
  )
}

function submitPostcode() {
  const pc = postcode.value.trim()
  if (!pc) return

  error.value = ''
  emit('located', {
    postcode: pc,
    type: 'postcode',
  })
}
</script>

<style scoped lang="scss">
.location-picker {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: #ffffff;

  &__panel {
    width: 100%;
    max-width: 320px;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  &__logo {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    margin-bottom: 12px;
  }

  &__title {
    margin: 0 0 4px;
    font-size: 26px;
    font-weight: 700;
    color: var(--header-bg, #1d6607);
  }

  &__subtitle {
    margin: 0 0 28px;
    font-size: 15px;
    color: #666666;
  }

  &__gps-btn {
    width: 100%;
    padding: 14px 0;
    border: none;
    border-radius: 24px;
    background: var(--header-bg, #1d6607);
    color: #ffffff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;

    &:active {
      opacity: 0.85;
    }

    &:disabled {
      opacity: 0.6;
      cursor: default;
    }
  }

  &__divider {
    display: flex;
    align-items: center;
    width: 100%;
    margin: 20px 0;
    gap: 12px;

    &::before,
    &::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #dddddd;
    }

    span {
      font-size: 13px;
      color: #999999;
    }
  }

  &__postcode-row {
    display: flex;
    width: 100%;
    gap: 8px;
  }

  &__input {
    flex: 1;
    padding: 12px 14px;
    border: 1px solid #d0d0d0;
    border-radius: 10px;
    font-size: 15px;
    color: #333333;
    outline: none;

    &::placeholder {
      color: #aaaaaa;
    }

    &:focus {
      border-color: var(--header-bg, #1d6607);
    }
  }

  &__go-btn {
    padding: 12px 20px;
    border: none;
    border-radius: 10px;
    background: var(--header-bg, #1d6607);
    color: #ffffff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    flex-shrink: 0;

    &:active {
      opacity: 0.85;
    }

    &:disabled {
      opacity: 0.4;
      cursor: default;
    }
  }

  &__error {
    margin: 12px 0 0;
    font-size: 13px;
    color: #d32f2f;
    text-align: center;
  }
}
</style>
