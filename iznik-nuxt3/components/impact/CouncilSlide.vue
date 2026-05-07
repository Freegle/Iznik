<template>
  <div class="impact-slide" :style="slideStyle">
    <!-- Header -->
    <div class="impact-header">
      <div class="impact-logo">
        <svg viewBox="0 0 40 40" fill="none" width="28" height="28">
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
      <div class="impact-header-titles">
        <div class="impact-title1">Freegle – social &amp; economic impacts</div>
        <div class="impact-title2">{{ authorityName }}</div>
      </div>
      <div class="impact-year-badge">{{ yearLabel }}</div>
    </div>

    <!-- Truck -->
    <div class="impact-truck">🚛</div>

    <!-- Tonnes -->
    <div class="impact-tonnes-n">{{ formattedWeight }}</div>
    <div class="impact-tonnes-label">
      <span class="impact-tonnes-big">tonnes</span>
      saved<br />from disposal
    </div>

    <!-- CO2 -->
    <div class="impact-co2-icon">☁️</div>
    <div class="impact-co2-n">{{ formattedCO2 }}</div>
    <div class="impact-co2-label">tonnes CO₂eq<br />saved*</div>

    <!-- Items badge -->
    <div class="impact-items-badge">
      <div class="impact-items-n">{{ totalGifts.toLocaleString() }}</div>
      <div class="impact-items-d">items freegled</div>
    </div>

    <!-- Person illustration -->
    <div class="impact-person">🧍‍♀️</div>

    <!-- Testimonial -->
    <div v-if="testimonials.length" class="impact-testimonial">
      <div class="impact-t-head">Freeglers say:</div>
      <div class="impact-dot-row">
        <span
          v-for="(_, i) in 5"
          :key="i"
          class="impact-dot"
          :class="i < testimonials.length ? 'dot-green' : 'dot-grey'"
        />
      </div>
      <blockquote v-for="t in testimonials" :key="t.quote" class="impact-quote">
        "{{ t.quote }}"
        <div v-if="t.attribution" class="impact-attrib">
          From {{ t.attribution }}
        </div>
      </blockquote>
    </div>

    <!-- Photo -->
    <div v-if="photoUrl" class="impact-photo">
      <img
        :src="photoUrl"
        alt=""
        style="width: 100%; height: 100%; object-fit: cover"
      />
    </div>
    <div v-else class="impact-photo impact-photo-placeholder">📷</div>

    <!-- Freeglers -->
    <div class="impact-freeglers-n">{{ totalMembers.toLocaleString() }}</div>
    <div class="impact-freeglers-label">freeglers</div>

    <!-- Communities -->
    <div class="impact-communities">
      <div class="impact-comm-icons">👥👥👥👥👥</div>
      <div class="impact-comm-n">{{ groupCount }}</div>
      <div class="impact-comm-d">
        {{ groupCount === 1 ? 'Freegle community' : 'Freegle communities' }}
      </div>
      <div v-if="partialGroupCount > 0" class="impact-comm-sub">
        + {{ partialGroupCount }} others part-serving this area
      </div>
    </div>

    <!-- Economic benefit -->
    <div class="impact-benefit">
      <div class="impact-benefit-icon">💷</div>
      <div class="impact-benefit-n">£{{ formattedBenefit }}</div>
      <div class="impact-benefit-d">economic benefit*</div>
      <div class="impact-benefit-wrap">*WRAP benefits of reuse tool</div>
    </div>

    <!-- Footer -->
    <div class="impact-footer">www.ilovefreegle.org</div>
  </div>
</template>

<script setup>
import { computed } from '#imports'

const props = defineProps({
  authorityName: { type: String, required: true },
  yearLabel: { type: String, required: true },
  totalWeight: { type: Number, required: true },
  totalBenefit: { type: Number, required: true },
  totalCO2: { type: Number, required: true },
  totalGifts: { type: Number, required: true },
  totalMembers: { type: Number, required: true },
  groupCount: { type: Number, required: true },
  partialGroupCount: { type: Number, default: 0 },
  testimonials: { type: Array, default: () => [] },
  photoUrl: { type: String, default: null },
  scale: { type: Number, default: 0.45 },
})

const slideStyle = computed(() => ({
  width: `${Math.round(2000 * props.scale)}px`,
  height: `${Math.round(1414 * props.scale)}px`,
}))

const formattedWeight = computed(() => {
  if (props.totalWeight < 10) return props.totalWeight.toFixed(1)
  return Math.round(props.totalWeight).toLocaleString()
})

const formattedCO2 = computed(() => {
  if (props.totalCO2 < 10) return props.totalCO2.toFixed(1)
  return Math.round(props.totalCO2).toLocaleString()
})

const formattedBenefit = computed(() =>
  Math.round(props.totalBenefit).toLocaleString()
)
</script>

<style scoped>
.impact-slide {
  position: relative;
  background: #79c61d;
  overflow: hidden;
  border-radius: 6px;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
  font-family: Arial, sans-serif;
}

/* ── HEADER ── */
.impact-header {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 9.9%;
  background: white;
  display: flex;
  align-items: center;
  padding: 0 7px;
  gap: 8px;
}

.impact-logo {
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  background: #1d5c22;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.impact-header-titles {
  flex: 1;
}

.impact-title1 {
  font-size: 14px;
  font-weight: 900;
  color: #111;
  line-height: 1.2;
  font-family: 'Arial Black', Arial, sans-serif;
}

.impact-title2 {
  font-size: 11px;
  font-weight: 700;
  color: #111;
  margin-top: 1px;
}

.impact-year-badge {
  background: #e87722;
  color: white;
  border-radius: 8px;
  padding: 4px 10px;
  font-size: 14px;
  font-weight: 900;
  font-family: 'Arial Black', Arial, sans-serif;
  flex-shrink: 0;
  line-height: 1.3;
  text-align: center;
  white-space: pre-line;
}

/* ── CONTENT ELEMENTS (% of 900×636 slide) ── */
.impact-truck {
  position: absolute;
  left: 0.75%;
  top: 11%;
  font-size: 60px;
  line-height: 1;
}

.impact-tonnes-n {
  position: absolute;
  left: 0.75%;
  top: 32%;
  font-size: 44px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.impact-tonnes-label {
  position: absolute;
  left: 0.75%;
  top: 44%;
  line-height: 1.3;
  color: white;
  font-size: 11px;
  font-weight: 700;
}

.impact-tonnes-big {
  font-size: 16px;
  font-weight: 900;
  display: block;
}

.impact-co2-icon {
  position: absolute;
  left: 0.75%;
  top: 66%;
  font-size: 40px;
  line-height: 1;
}

.impact-co2-n {
  position: absolute;
  left: 0.75%;
  top: 76%;
  font-size: 34px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.impact-co2-label {
  position: absolute;
  left: 0.75%;
  top: 84%;
  font-size: 11px;
  font-weight: 700;
  color: white;
  line-height: 1.3;
}

.impact-items-badge {
  position: absolute;
  left: 18%;
  top: 44%;
  background: #222;
  border-radius: 10px;
  padding: 10px 14px;
  text-align: center;
  color: white;
}

.impact-items-n {
  font-size: 38px;
  font-weight: 900;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.impact-items-d {
  font-size: 11px;
  font-weight: 600;
}

.impact-person {
  position: absolute;
  left: 18%;
  bottom: 7%;
  font-size: 80px;
  line-height: 1;
  opacity: 0.9;
}

.impact-testimonial {
  position: absolute;
  left: 27.5%;
  top: 12%;
  width: 32%;
  background: white;
  border-radius: 8px;
  padding: 10px 12px;
}

.impact-t-head {
  font-size: 9px;
  font-weight: 900;
  color: #1d5c22;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-bottom: 6px;
}

.impact-dot-row {
  display: flex;
  gap: 3px;
  margin-bottom: 6px;
}

.impact-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  display: inline-block;
}

.dot-green {
  background: #79c61d;
}
.dot-grey {
  background: #ccc;
}

.impact-quote {
  font-size: 9.5px;
  color: #333;
  font-style: italic;
  line-height: 1.5;
  margin-bottom: 6px;
  font-family: Georgia, serif;
  border: none;
  padding: 0;
}

.impact-quote + .impact-quote {
  border-top: 1px solid #eee;
  padding-top: 6px;
  margin-top: 0;
}

.impact-attrib {
  font-size: 9px;
  color: #888;
  margin-top: 4px;
}

.impact-photo {
  position: absolute;
  right: 11.5%;
  top: 12%;
  width: 16%;
  height: 17%;
  background: rgba(0, 0, 0, 0.12);
  border-radius: 6px;
  overflow: hidden;
}

.impact-photo-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 36px;
}

.impact-freeglers-n {
  position: absolute;
  right: 1%;
  top: 41%;
  text-align: right;
  font-size: 38px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.impact-freeglers-label {
  position: absolute;
  right: 1%;
  top: 50%;
  text-align: right;
  font-size: 11px;
  font-weight: 700;
  color: white;
}

.impact-communities {
  position: absolute;
  left: 55%;
  bottom: 11%;
  text-align: center;
}

.impact-comm-icons {
  font-size: 18px;
  letter-spacing: -1px;
}

.impact-comm-n {
  font-size: 26px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.impact-comm-d {
  font-size: 10px;
  font-weight: 700;
  color: white;
}

.impact-comm-sub {
  font-size: 9px;
  color: rgba(255, 255, 255, 0.75);
  margin-top: 1px;
}

.impact-benefit {
  position: absolute;
  right: 1%;
  bottom: 8%;
  text-align: right;
}

.impact-benefit-icon {
  font-size: 22px;
}

.impact-benefit-n {
  font-size: 28px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.impact-benefit-d {
  font-size: 9px;
  font-weight: 700;
  color: white;
}
.impact-benefit-wrap {
  font-size: 8px;
  color: rgba(255, 255, 255, 0.6);
}

.impact-footer {
  position: absolute;
  bottom: 1.5%;
  left: 0;
  right: 0;
  text-align: center;
  font-size: 10px;
  font-weight: 700;
  color: #1a4a1a;
}

/* ── PRINT: hide only the filter bar, not the slide ── */
@media print {
  .impact-slide {
    box-shadow: none;
    border-radius: 0;
  }
}
</style>
