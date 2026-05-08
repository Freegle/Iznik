<template>
  <div class="national-slide" :style="slideStyle">
    <!-- Logo -->
    <div class="ns-logo">
      <svg viewBox="0 0 40 40" fill="none" width="36" height="36">
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

    <div class="ns-title">Social &amp; economic impact</div>
    <div class="ns-year-badge">{{ year }}</div>

    <div class="ns-truck">🚛</div>

    <div class="ns-tonnes-n">{{ formattedWeight }}</div>
    <div class="ns-tonnes-label">
      <span class="ns-tonnes-big">tonnes</span>
      saved<br />from disposal
    </div>

    <div class="ns-check-stat">
      <div class="ns-check-icon">✅</div>
      <div class="ns-check-big">8 out of 10</div>
      <div class="ns-check-label">
        items listed on Freegle<br />find new homes
      </div>
    </div>

    <div class="ns-communities">
      <div class="ns-comm-icons">👥👥👥👥👥</div>
      <div class="ns-comm-n">{{ groupCount.toLocaleString() }}</div>
      <div class="ns-comm-label">Freegle communities</div>
    </div>

    <div class="ns-notepad">
      <div class="ns-notepad-head">Items freegled:</div>
      <div class="ns-notepad-big">{{ totalGifts.toLocaleString() }}</div>
    </div>

    <div class="ns-volunteers">
      <div class="ns-vol-icons">👥👥👥</div>
      <div class="ns-vol-n">11,000+</div>
      <div class="ns-vol-label">
        Freegle volunteers<br />&amp; micro-volunteers
      </div>
    </div>

    <div class="ns-freeglers-n">{{ formattedMembers }}</div>
    <div class="ns-freeglers-label">freeglers</div>

    <div class="ns-co2-icon">☁️</div>
    <div class="ns-co2-n">{{ formattedCO2 }}</div>
    <div class="ns-co2-label">tonnes CO₂eq<br />saved*</div>

    <div class="ns-person">🧍‍♀️</div>

    <div class="ns-established">
      Freegle<br />established
      <span class="ns-established-year">2009</span>
    </div>

    <div class="ns-roi">
      <span class="ns-roi-amount">£10</span> Freegle funding<br />
      is equal to<br />
      <span class="ns-roi-amount">£100</span> of impact<br />
      in communities
    </div>

    <div class="ns-benefit">
      <div class="ns-benefit-icon">💷</div>
      <div class="ns-benefit-n">{{ formattedBenefit }}</div>
      <div class="ns-benefit-d">
        economic benefit<br />to communities*
      </div>
    </div>

    <div class="ns-footer">
      www.ilovefreegle.org
      <span class="ns-footer-wrap"
        >*calculations made using the WRAP benefits of reuse tool</span
      >
    </div>
  </div>
</template>

<script setup>
import { computed } from '#imports'

const props = defineProps({
  year: { type: Number, required: true },
  totalWeight: { type: Number, required: true },
  totalBenefit: { type: Number, required: true },
  totalCO2: { type: Number, required: true },
  totalGifts: { type: Number, required: true },
  totalMembers: { type: Number, required: true },
  groupCount: { type: Number, required: true },
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

const formattedMembers = computed(() => {
  const m = props.totalMembers / 1_000_000
  if (m >= 1) return `${m.toFixed(1)} million`
  const k = props.totalMembers / 1_000
  if (k >= 1) return `${Math.round(k).toLocaleString()}k`
  return props.totalMembers.toLocaleString()
})

const formattedBenefit = computed(() => {
  const m = props.totalBenefit / 1_000_000
  if (m >= 1) return `£${m.toFixed(1)} million`
  const k = props.totalBenefit / 1_000
  if (k >= 1) return `£${Math.round(k).toLocaleString()}k`
  return `£${Math.round(props.totalBenefit).toLocaleString()}`
})
</script>

<style scoped>
/* ── SLIDE CONTAINER ── */
.national-slide {
  position: relative;
  background: #79c61d;
  overflow: hidden;
  border-radius: 6px;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
  font-family: Arial, sans-serif;
}

/* ── LOGO (top-left dark green square) ── */
.ns-logo {
  position: absolute;
  left: 1%;
  top: 1.5%;
  width: 52px;
  height: 52px;
  background: #1d5c22;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ── TITLE ── */
.ns-title {
  position: absolute;
  left: 8%;
  top: 2%;
  font-size: 32px;
  font-weight: 900;
  color: #111;
  line-height: 1.1;
  font-family: 'Arial Black', Arial, sans-serif;
}

/* ── YEAR BADGE ── */
.ns-year-badge {
  position: absolute;
  right: 1%;
  top: 1.5%;
  background: #e87722;
  color: white;
  border-radius: 8px;
  padding: 4px 14px;
  font-size: 22px;
  font-weight: 900;
  font-family: 'Arial Black', Arial, sans-serif;
}

/* ── TRUCK ── */
.ns-truck {
  position: absolute;
  left: 0.5%;
  top: 14%;
  font-size: 55px;
  line-height: 1;
}

/* ── TONNES ── */
.ns-tonnes-n {
  position: absolute;
  left: 0.5%;
  top: 25%;
  font-size: 40px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.ns-tonnes-label {
  position: absolute;
  left: 0.5%;
  top: 36%;
  color: white;
  font-size: 13px;
  font-weight: 700;
  line-height: 1.3;
}

.ns-tonnes-big {
  font-size: 17px;
  font-weight: 900;
  display: block;
}

/* ── CHECK STAT ── */
.ns-check-stat {
  position: absolute;
  left: 16%;
  top: 13%;
  text-align: center;
  width: 18%;
}

.ns-check-icon {
  font-size: 28px;
}

.ns-check-big {
  font-size: 22px;
  font-weight: 900;
  color: white;
  font-family: 'Arial Black', Arial, sans-serif;
}

.ns-check-label {
  font-size: 10px;
  font-weight: 700;
  color: #1d5c22;
  line-height: 1.3;
}

/* ── COMMUNITIES ── */
.ns-communities {
  position: absolute;
  left: 37%;
  top: 11%;
  text-align: center;
}

.ns-comm-icons {
  font-size: 20px;
  letter-spacing: -2px;
}

.ns-comm-n {
  font-size: 48px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.ns-comm-label {
  font-size: 12px;
  font-weight: 700;
  color: #1d5c22;
}

/* ── NOTEPAD ── */
.ns-notepad {
  position: absolute;
  left: 16%;
  top: 34%;
  width: 20%;
  background: #f8f4e0;
  border-radius: 4px;
  padding: 10px 12px;
  box-shadow: 2px 3px 8px rgba(0, 0, 0, 0.15);
}

.ns-notepad-head {
  font-size: 9px;
  font-weight: 700;
  color: #555;
  margin-bottom: 4px;
}

.ns-notepad-big {
  font-size: 24px;
  font-weight: 900;
  color: #111;
  font-family: 'Arial Black', Arial, sans-serif;
  line-height: 1.1;
}

/* ── VOLUNTEERS ── */
.ns-volunteers {
  position: absolute;
  left: 43%;
  top: 30%;
  text-align: center;
}

.ns-vol-icons {
  font-size: 22px;
  letter-spacing: -2px;
}

.ns-vol-n {
  font-size: 36px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.ns-vol-label {
  font-size: 10px;
  font-weight: 700;
  color: #1d5c22;
  line-height: 1.3;
}

/* ── FREEGLERS ── */
.ns-freeglers-n {
  position: absolute;
  right: 1%;
  top: 11%;
  text-align: right;
  font-size: 32px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.ns-freeglers-label {
  position: absolute;
  right: 1%;
  top: 20%;
  text-align: right;
  font-size: 11px;
  font-weight: 700;
  color: #1d5c22;
}

/* ── CO2 ── */
.ns-co2-icon {
  position: absolute;
  left: 0.5%;
  top: 62%;
  font-size: 40px;
  line-height: 1;
}

.ns-co2-n {
  position: absolute;
  left: 0.5%;
  top: 73%;
  font-size: 32px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.ns-co2-label {
  position: absolute;
  left: 0.5%;
  top: 82%;
  font-size: 10px;
  font-weight: 700;
  color: white;
}

/* ── PERSON ── */
.ns-person {
  position: absolute;
  left: 14%;
  bottom: 7%;
  font-size: 90px;
  line-height: 1;
  opacity: 0.88;
}

/* ── ESTABLISHED ── */
.ns-established {
  position: absolute;
  left: 30%;
  bottom: 24%;
  background: white;
  border-radius: 12px;
  padding: 6px 14px;
  font-size: 11px;
  font-weight: 700;
  color: #1d5c22;
  text-align: center;
  line-height: 1.3;
}

.ns-established-year {
  font-size: 22px;
  font-weight: 900;
  font-family: 'Arial Black', Arial, sans-serif;
  display: block;
}

/* ── ROI ── */
.ns-roi {
  position: absolute;
  left: 30%;
  bottom: 8%;
  width: 22%;
  font-size: 11px;
  color: #1d5c22;
  text-align: center;
  line-height: 1.5;
}

.ns-roi-amount {
  font-size: 18px;
  font-weight: 900;
  color: white;
  font-family: 'Arial Black', Arial, sans-serif;
}

/* ── ECONOMIC BENEFIT ── */
.ns-benefit {
  position: absolute;
  right: 1%;
  bottom: 14%;
  text-align: right;
}

.ns-benefit-icon {
  font-size: 28px;
}

.ns-benefit-n {
  font-size: 28px;
  font-weight: 900;
  color: white;
  line-height: 1;
  font-family: 'Arial Black', Arial, sans-serif;
}

.ns-benefit-d {
  font-size: 11px;
  font-weight: 700;
  color: white;
}

/* ── FOOTER ── */
.ns-footer {
  position: absolute;
  bottom: 1.5%;
  left: 0;
  right: 0;
  text-align: center;
  font-size: 10px;
  font-weight: 700;
  color: #1a4a1a;
}

.ns-footer-wrap {
  font-size: 9px;
  color: rgba(0, 0, 0, 0.4);
  display: block;
}

@media print {
  .national-slide {
    box-shadow: none;
    border-radius: 0;
  }
}
</style>
