<template>
  <Transition name="drawer">
    <div
      v-if="visible"
      class="settings-drawer"
    >
      <header class="settings-drawer__header">
        <button
          class="settings-drawer__back"
          aria-label="Go back"
          @click="$emit('close')"
        >
          <svg
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              d="M20 11H7.83L13.42 5.41L12 4L4 12L12 20L13.41 18.59L7.83 13H20V11Z"
              fill="currentColor"
            />
          </svg>
        </button>
        <h1 class="settings-drawer__title">
          Settings
        </h1>
      </header>

      <nav class="settings-drawer__list">
        <button
          v-for="item in menuItems"
          :key="item.section"
          class="settings-drawer__item"
          @click="$emit('navigate', item.section)"
        >
          {{ item.label }}
        </button>
      </nav>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  visible: { type: Boolean, default: false },
})

defineEmits(['close', 'navigate'])

const menuItems = [
  { section: 'address-book', label: 'Address Book' },
  { section: 'notifications', label: 'Notification Preferences' },
  { section: 'location', label: 'Change Location' },
  { section: 'export', label: 'Export My Data' },
  { section: 'help', label: 'Help' },
  { section: 'about', label: 'About Freegle' },
]
</script>

<style scoped lang="scss">
.settings-drawer {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  flex-direction: column;
  background: #ffffff;

  &__header {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 48px;
    padding: 0 12px;
    background: var(--header-bg, #1d6607);
    color: #ffffff;
    flex-shrink: 0;
  }

  &__back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: #ffffff;
    cursor: pointer;

    &:active {
      background: rgba(255, 255, 255, 0.15);
    }
  }

  &__title {
    margin: 0;
    font-size: 17px;
    font-weight: 600;
  }

  &__list {
    flex: 1;
    overflow-y: auto;
  }

  &__item {
    display: block;
    width: 100%;
    padding: 14px 16px;
    border: none;
    border-bottom: 1px solid #eeeeee;
    background: #ffffff;
    color: #333333;
    font-size: 15px;
    font-weight: 500;
    text-align: left;
    cursor: pointer;

    &:active {
      background: #f5f5f5;
    }
  }
}

.drawer-enter-active,
.drawer-leave-active {
  transition: transform 0.25s ease;
}

.drawer-enter-from,
.drawer-leave-to {
  transform: translateX(100%);
}
</style>
