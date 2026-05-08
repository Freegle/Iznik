<template>
  <div class="top-slide" :style="slideStyle">
    <!-- Logo -->
    <div class="ts-logo">
      <svg viewBox="0 0 40 40" fill="none" width="30" height="30">
        <circle
          cx="20"
          cy="20"
          r="15"
          stroke="white"
          stroke-width="3.5"
          fill="none"
        />
        <path
          d="M20 7 C28 7 33 13 33 20"
          stroke="white"
          stroke-width="3"
          stroke-linecap="round"
        />
        <path
          d="M30 16 L33 20 L36.5 16.5"
          stroke="white"
          stroke-width="2.5"
          stroke-linecap="round"
          stroke-linejoin="round"
          fill="none"
        />
        <path
          d="M20 33 C12 33 7 27 7 20"
          stroke="white"
          stroke-width="3"
          stroke-linecap="round"
        />
        <path
          d="M10 24 L7 20 L3.5 23.5"
          stroke="white"
          stroke-width="2.5"
          stroke-linecap="round"
          stroke-linejoin="round"
          fill="none"
        />
      </svg>
    </div>

    <div class="ts-year-badge">{{ year }}</div>
    <div class="ts-stars">⭐⭐⭐⭐⭐</div>

    <div class="ts-title">UK REUSE SUPERSTARS</div>
    <div class="ts-subtitle">
      Freegle's top {{ communities.length }} communities for {{ year }}
    </div>

    <div class="ts-leaderboard">
      <div
        v-for="(community, index) in communities"
        :key="community.name"
        class="ts-entry"
        :class="`rank-${index + 1}`"
      >
        <div class="ts-rank">{{ index + 1 }}</div>
        <div class="ts-medal">{{ medalFor(index) }}</div>
        <div class="ts-community">{{ community.name }}</div>
        <div>
          <div class="ts-tonnes">{{ formattedTonnes(community.weightKg) }}</div>
          <div class="ts-tonnes-label">tonnes reused</div>
        </div>
      </div>
    </div>

    <div class="ts-hashtag">#ChooseToReuse</div>
    <div class="ts-footer">www.ilovefreegle.org</div>
  </div>
</template>

<script setup>
import { computed } from '#imports'

const props = defineProps({
  year: { type: Number, required: true },
  communities: { type: Array, required: true },
  scale: { type: Number, default: 0.45 },
})

const slideStyle = computed(() => ({
  width: `${Math.round(2000 * props.scale)}px`,
  height: `${Math.round(1414 * props.scale)}px`,
}))

const MEDALS = ['🥇', '🥈', '🥉', '🏅', '🏅', '🏅', '🏅', '🏅', '🏅', '🏅']

function medalFor(index) {
  return MEDALS[index] ?? '🏅'
}

function formattedTonnes(weightKg) {
  const tonnes = weightKg / 1000
  if (tonnes < 10) return tonnes.toFixed(1)
  return Math.round(tonnes).toLocaleString()
}
</script>

<style scoped>
.top-slide {
  position: relative;
  background: #0d2d14;
  overflow: hidden;
  border-radius: 6px;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.6);
  font-family: Arial, sans-serif;
}

/* Gold star burst decoration */
.top-slide::before {
  content: '';
  position: absolute;
  top: -80px;
  left: -80px;
  width: 320px;
  height: 320px;
  background: radial-gradient(
    circle,
    rgba(255, 200, 0, 0.12) 0%,
    transparent 70%
  );
  border-radius: 50%;
}

.top-slide::after {
  content: '';
  position: absolute;
  bottom: -80px;
  right: -80px;
  width: 400px;
  height: 400px;
  background: radial-gradient(
    circle,
    rgba(121, 198, 29, 0.15) 0%,
    transparent 70%
  );
  border-radius: 50%;
}

/* ── LOGO ── */
.ts-logo {
  position: absolute;
  left: 2%;
  top: 4%;
  width: 44px;
  height: 44px;
  background: #79c61d;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ── YEAR BADGE ── */
.ts-year-badge {
  position: absolute;
  right: 2%;
  top: 4%;
  background: #e87722;
  color: white;
  border-radius: 8px;
  padding: 4px 14px;
  font-size: 18px;
  font-weight: 900;
  font-family: 'Arial Black', Arial, sans-serif;
}

/* ── STARS ── */
.ts-stars {
  position: absolute;
  left: 50%;
  top: 3%;
  transform: translateX(-50%);
  font-size: 22px;
  letter-spacing: 4px;
}

/* ── TITLE ── */
.ts-title {
  position: absolute;
  left: 0;
  right: 0;
  top: 10%;
  text-align: center;
  font-size: 52px;
  font-weight: 900;
  color: #ffd700;
  font-family: 'Arial Black', Arial, sans-serif;
  letter-spacing: 2px;
  text-shadow: 0 3px 12px rgba(0, 0, 0, 0.5);
  line-height: 1;
}

/* ── SUBTITLE ── */
.ts-subtitle {
  position: absolute;
  left: 0;
  right: 0;
  top: 25%;
  text-align: center;
  font-size: 16px;
  font-weight: 700;
  color: #79c61d;
  letter-spacing: 1px;
}

/* ── LEADERBOARD ── */
.ts-leaderboard {
  position: absolute;
  left: 8%;
  right: 8%;
  top: 33%;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.ts-entry {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 8px 14px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.06);
}

.ts-entry.rank-1 {
  background: rgba(255, 215, 0, 0.15);
  border: 1px solid rgba(255, 215, 0, 0.4);
}

.ts-entry.rank-2 {
  background: rgba(192, 192, 192, 0.12);
  border: 1px solid rgba(192, 192, 192, 0.3);
}

.ts-entry.rank-3 {
  background: rgba(205, 127, 50, 0.12);
  border: 1px solid rgba(205, 127, 50, 0.3);
}

.ts-rank {
  font-size: 26px;
  font-weight: 900;
  font-family: 'Arial Black', Arial, sans-serif;
  width: 36px;
  text-align: center;
  flex-shrink: 0;
}

.rank-1 .ts-rank {
  color: #ffd700;
}

.rank-2 .ts-rank {
  color: #c0c0c0;
}

.rank-3 .ts-rank {
  color: #cd7f32;
}

.rank-4 .ts-rank,
.rank-5 .ts-rank,
.rank-6 .ts-rank,
.rank-7 .ts-rank,
.rank-8 .ts-rank,
.rank-9 .ts-rank,
.rank-10 .ts-rank {
  color: #79c61d;
}

.ts-medal {
  font-size: 20px;
  flex-shrink: 0;
  width: 24px;
}

.ts-community {
  flex: 1;
  font-size: 17px;
  font-weight: 700;
  color: white;
  font-family: 'Arial Black', Arial, sans-serif;
}

.ts-tonnes {
  font-size: 28px;
  font-weight: 900;
  font-family: 'Arial Black', Arial, sans-serif;
  color: #79c61d;
  text-align: right;
}

.ts-tonnes-label {
  font-size: 9px;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.5);
  text-align: right;
  line-height: 1.3;
}

/* ── HASHTAG ── */
.ts-hashtag {
  position: absolute;
  bottom: 7%;
  left: 0;
  right: 0;
  text-align: center;
  font-size: 18px;
  font-weight: 900;
  color: #79c61d;
  font-family: 'Arial Black', Arial, sans-serif;
  letter-spacing: 1px;
}

/* ── FOOTER ── */
.ts-footer {
  position: absolute;
  bottom: 2%;
  left: 0;
  right: 0;
  text-align: center;
  font-size: 10px;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.4);
}

@media print {
  .top-slide {
    box-shadow: none;
    border-radius: 0;
  }
}
</style>
