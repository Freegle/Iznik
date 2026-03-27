<template>
  <div class="onboarding">
    <div class="onboarding__slides" :style="{ transform: `translateX(-${step * 100}%)` }">
      <!-- Slide 1: Welcome -->
      <div class="onboarding__slide onboarding__slide--welcome">
        <img src="/icon.png" alt="Freegle" class="onboarding__logo" />
        <h1>Welcome to Freegle</h1>
        <p class="onboarding__tagline">Give and get stuff for free in your community</p>
        <div class="onboarding__illustration">
          <div class="onboarding__emoji-row">
            <span>🛋️</span><span>📚</span><span>🚲</span><span>🌱</span><span>🧸</span>
          </div>
        </div>
        <p class="onboarding__detail">
          Your neighbours are offering things they no longer need. Furniture, books, toys, garden tools — all free, all local.
        </p>
      </div>

      <!-- Slide 2: How it works -->
      <div class="onboarding__slide onboarding__slide--how">
        <div class="onboarding__step-visual">
          <div class="onboarding__step-item">
            <span class="onboarding__step-num">1</span>
            <span class="onboarding__step-text">See what's free near you</span>
          </div>
          <div class="onboarding__step-item">
            <span class="onboarding__step-num">2</span>
            <span class="onboarding__step-text">Reply to grab something</span>
          </div>
          <div class="onboarding__step-item">
            <span class="onboarding__step-num">3</span>
            <span class="onboarding__step-text">Or offer your own stuff</span>
          </div>
        </div>
        <p class="onboarding__detail">
          It's like a community group chat, but for giving things away. No selling, no swapping — just neighbours helping each other.
        </p>
      </div>

      <!-- Slide 3: Community -->
      <div class="onboarding__slide onboarding__slide--community">
        <div class="onboarding__stats">
          <div class="onboarding__stat">
            <span class="onboarding__stat-num">38M+</span>
            <span class="onboarding__stat-label">items freegled</span>
          </div>
          <div class="onboarding__stat">
            <span class="onboarding__stat-num">8M+</span>
            <span class="onboarding__stat-label">members</span>
          </div>
        </div>
        <h2>Join your local community</h2>
        <p class="onboarding__detail">
          Every item reused is one less in landfill. You're making a difference just by being here.
        </p>
      </div>
    </div>

    <!-- Dots + button -->
    <div class="onboarding__nav">
      <div class="onboarding__dots">
        <span v-for="i in 3" :key="i" class="onboarding__dot" :class="{ active: step === i - 1 }"></span>
      </div>
      <button v-if="step < 2" class="onboarding__next" @click="step++">
        Next
      </button>
      <button v-else class="onboarding__next onboarding__next--go" @click="$emit('done')">
        Get started
      </button>
    </div>

    <button v-if="step < 2" class="onboarding__skip" @click="$emit('done')">
      Skip
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue'

defineEmits(['done'])

const step = ref(0)
</script>

<style scoped lang="scss">
.onboarding {
  position: fixed;
  inset: 0;
  z-index: 500;
  background: white;
  display: flex;
  flex-direction: column;
  overflow: hidden;

  &__slides {
    flex: 1;
    display: flex;
    transition: transform 0.4s ease;
  }

  &__slide {
    min-width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2rem 1.5rem;
    text-align: center;
  }

  &__slide--welcome { background: linear-gradient(180deg, #f5faf0 0%, white 100%); }
  &__slide--how { background: linear-gradient(180deg, #f0f4fc 0%, white 100%); }
  &__slide--community { background: linear-gradient(180deg, #fef9e7 0%, white 100%); }

  &__logo {
    width: 72px;
    height: 72px;
    border-radius: 16px;
    margin-bottom: 1rem;
  }

  h1 {
    font-size: 1.6rem;
    font-weight: 700;
    color: #338808;
    margin: 0 0 0.5rem;
  }

  h2 {
    font-size: 1.3rem;
    font-weight: 700;
    color: #333;
    margin: 1rem 0 0.5rem;
  }

  &__tagline {
    font-size: 1rem;
    color: #666;
    margin: 0 0 1.5rem;
  }

  &__illustration {
    margin-bottom: 1.5rem;
  }

  &__emoji-row {
    display: flex;
    gap: 12px;
    font-size: 2rem;
  }

  &__detail {
    font-size: 0.9rem;
    color: #777;
    line-height: 1.5;
    max-width: 300px;
  }

  &__step-visual {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-bottom: 1.5rem;
    text-align: left;
  }

  &__step-item {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  &__step-num {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #338808;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
  }

  &__step-text {
    font-size: 15px;
    color: #333;
    font-weight: 500;
  }

  &__stats {
    display: flex;
    gap: 2rem;
    margin-bottom: 1rem;
  }

  &__stat {
    text-align: center;
  }

  &__stat-num {
    display: block;
    font-size: 1.8rem;
    font-weight: 800;
    color: #338808;
  }

  &__stat-label {
    font-size: 0.8rem;
    color: #999;
  }

  &__nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.5rem 2rem;
  }

  &__dots {
    display: flex;
    gap: 8px;
  }

  &__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ddd;
    transition: all 0.2s;

    &.active {
      background: #338808;
      width: 20px;
      border-radius: 4px;
    }
  }

  &__next {
    padding: 10px 24px;
    border: none;
    border-radius: 20px;
    background: #338808;
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;

    &--go {
      padding: 12px 32px;
      font-size: 16px;
    }
  }

  &__skip {
    position: absolute;
    top: 16px;
    right: 16px;
    background: none;
    border: none;
    color: #999;
    font-size: 14px;
    cursor: pointer;
  }
}
</style>
