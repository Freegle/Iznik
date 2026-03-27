<template>
  <Transition name="sheet">
    <div
      v-if="visible"
      class="profile-sheet"
      @click.self="$emit('close')"
    >
      <div class="profile-sheet__panel">
        <div class="profile-sheet__avatar">
          <img
            v-if="user?.avatar"
            :src="user.avatar"
            alt=""
            class="profile-sheet__avatar-img"
          />
          <div
            v-else
            class="profile-sheet__avatar-placeholder"
          />
        </div>

        <h2 class="profile-sheet__name">
          {{ user?.displayName || 'Freegle member' }}
        </h2>

        <p class="profile-sheet__about">
          {{ user?.aboutMe || 'Freegle member' }}
        </p>

        <button
          class="profile-sheet__close"
          @click="$emit('close')"
        >
          Close
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  user: {
    type: Object,
    default: () => ({}),
  },
})

defineEmits(['close'])
</script>

<style scoped lang="scss">
.profile-sheet {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: flex-end;
  background: rgba(0, 0, 0, 0.4);

  &__panel {
    width: 100%;
    padding: 24px 16px 32px;
    border-radius: 16px 16px 0 0;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  &__avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    overflow: hidden;
    margin-bottom: 12px;
    flex-shrink: 0;
  }

  &__avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  &__avatar-placeholder {
    width: 100%;
    height: 100%;
    background: #d0d0d0;
    border-radius: 50%;
  }

  &__name {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 600;
    color: #222222;
    text-align: center;
  }

  &__about {
    margin: 0 0 20px;
    font-size: 14px;
    color: #666666;
    text-align: center;
    line-height: 1.4;
  }

  &__close {
    padding: 10px 32px;
    border: 1px solid #d0d0d0;
    border-radius: 20px;
    background: #ffffff;
    color: #333333;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;

    &:active {
      background: #f5f5f5;
    }
  }
}

.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.25s ease;

  .profile-sheet__panel {
    transition: transform 0.25s ease;
  }
}

.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;

  .profile-sheet__panel {
    transform: translateY(100%);
  }
}
</style>
