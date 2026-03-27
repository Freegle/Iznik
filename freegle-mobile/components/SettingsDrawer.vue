<template>
  <Transition name="drawer">
    <div v-if="visible" class="settings-drawer">
      <header class="settings-drawer__header">
        <button class="settings-drawer__back" aria-label="Go back" @click="$emit('close')">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 11H7.83L13.42 5.41L12 4L4 12L12 20L13.41 18.59L7.83 13H20V11Z" />
          </svg>
        </button>
        <h1 class="settings-drawer__title">Settings</h1>
      </header>

      <nav class="settings-drawer__list">
        <button
          v-for="item in menuItems"
          :key="item.section"
          class="settings-drawer__item"
          @click="$emit('navigate', item.section)"
        >
          <span class="settings-drawer__icon">{{ item.icon }}</span>
          <div class="settings-drawer__item-text">
            <span class="settings-drawer__item-label">{{ item.label }}</span>
            <span v-if="item.hint" class="settings-drawer__item-hint">{{ item.hint }}</span>
          </div>
          <svg class="settings-drawer__chevron" width="16" height="16" viewBox="0 0 24 24" fill="#ccc">
            <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
          </svg>
        </button>
      </nav>

      <div class="settings-drawer__footer">
        <p>Freegle — don't throw it away, give it away!</p>
      </div>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  visible: { type: Boolean, default: false },
})

defineEmits(['close', 'navigate'])

const menuItems = [
  { section: 'notifications', label: 'Notifications', hint: 'Push, email, quiet hours', icon: '🔔' },
  { section: 'location', label: 'Change Location', hint: 'Update your area', icon: '📍' },
  { section: 'address-book', label: 'Address Book', hint: 'Saved addresses for collection', icon: '📖' },
  { section: 'export', label: 'My Data', hint: 'Download or delete your data', icon: '📦' },
  { section: 'help', label: 'Help', hint: null, icon: '❓' },
  { section: 'about', label: 'About Freegle', hint: null, icon: '💚' },
]
</script>

<style scoped lang="scss">
.settings-drawer {
  position: fixed;
  inset: 0;
  z-index: 300;
  display: flex;
  flex-direction: column;
  background: #fafafa;

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

    &:active { background: rgba(255, 255, 255, 0.15); }
  }

  &__title {
    margin: 0;
    font-size: 17px;
    font-weight: 600;
  }

  &__list {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
  }

  &__item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 14px 16px;
    border: none;
    background: white;
    margin-bottom: 1px;
    text-align: left;
    cursor: pointer;

    &:active { background: #f5f5f5; }
  }

  &__icon {
    font-size: 20px;
    flex-shrink: 0;
    width: 28px;
    text-align: center;
  }

  &__item-text {
    flex: 1;
    min-width: 0;
  }

  &__item-label {
    display: block;
    font-size: 15px;
    font-weight: 500;
    color: #333;
  }

  &__item-hint {
    display: block;
    font-size: 12px;
    color: #999;
    margin-top: 1px;
  }

  &__chevron {
    flex-shrink: 0;
  }

  &__footer {
    padding: 16px;
    text-align: center;

    p {
      font-size: 12px;
      color: #aaa;
      margin: 0;
      font-style: italic;
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
