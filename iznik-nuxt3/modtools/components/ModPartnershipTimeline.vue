<template>
  <div v-if="rows.length" class="timeline">
    <div class="axis-row">
      <div class="label" />
      <div class="track">
        <span
          v-for="y in years"
          :key="'y-' + y.year"
          class="year"
          :style="{ left: y.left + '%' }"
        >
          {{ y.year }}
        </span>
      </div>
    </div>
    <div v-for="row in rows" :key="'tl-' + row.authorityid" class="tl-row">
      <div class="label text-truncate" :title="row.name">{{ row.name }}</div>
      <div class="track">
        <span
          v-for="y in years"
          :key="'g-' + y.year"
          class="gridline"
          :style="{ left: y.left + '%' }"
        />
        <span class="today" :style="{ left: todayLeft + '%' }" />
        <button
          v-for="d in row.deals"
          :key="'bar-' + d.id"
          type="button"
          class="bar"
          :class="'status-' + d.status"
          :style="{ left: d.left + '%', width: d.width + '%' }"
          :title="d.title"
          @click="$emit('select', d.id)"
        />
        <span
          v-for="d in row.asks"
          :key="'ask-' + d.id"
          class="ask"
          :style="{ left: d.left + '%' }"
          :title="'Ask about renewal by ' + formatDate(d.date)"
        />
      </div>
    </div>
    <div class="legend small text-muted mt-2">
      <span><span class="swatch status-Confirmed" /> Confirmed or paid</span>
      <span><span class="swatch status-Overdue" /> Overdue</span>
      <span
        ><span class="swatch status-InPrinciple" /> Agreed in principle</span
      >
      <span><span class="swatch status-Quoted" /> Quoted</span>
      <span><span class="ask-key" /> Time to ask about renewal</span>
      <span><span class="today-key" /> Today</span>
    </div>
  </div>
</template>
<script setup>
import { computed } from 'vue'
import {
  dealLength,
  formatDate,
  formatMoney,
  renewalAskDate,
  statusInfo,
} from '~/modtools/composables/usePartnershipFormat'

// One row per council, one bar per deal, so gaps in a council's sponsorship and the point
// to ask about renewal are visible at a glance.
const props = defineProps({
  partnerships: {
    type: Array,
    required: true,
  },
})

defineEmits(['select'])

const DAY = 24 * 60 * 60 * 1000

// From the start of the year two years back to the end of the latest deal, so old history
// doesn't squash the deals that matter now.
const range = computed(() => {
  const now = new Date()
  const start = new Date(now.getFullYear() - 2, 0, 1)
  let end = new Date(now.getFullYear() + 1, 11, 31)

  props.partnerships.forEach((p) => {
    const e = new Date(p.enddate)
    if (e > end) {
      end = new Date(e.getFullYear(), 11, 31)
    }
  })

  return { start, end, span: end - start }
})

function pos(date) {
  const d = new Date(date)
  const pct = ((d - range.value.start) / range.value.span) * 100
  return Math.min(100, Math.max(0, pct))
}

const years = computed(() => {
  const ret = []
  for (
    let y = range.value.start.getFullYear();
    y <= range.value.end.getFullYear();
    y++
  ) {
    ret.push({ year: y, left: pos(new Date(y, 0, 1)) })
  }
  return ret
})

const todayLeft = computed(() => pos(new Date()))

const rows = computed(() => {
  const byCouncil = new Map()

  props.partnerships
    .filter((p) => new Date(p.enddate) >= range.value.start)
    .forEach((p) => {
      if (!byCouncil.has(p.authorityid)) {
        byCouncil.set(p.authorityid, {
          authorityid: p.authorityid,
          name: p.name,
          deals: [],
          asks: [],
        })
      }

      const row = byCouncil.get(p.authorityid)
      const left = pos(p.startdate)
      const right = pos(new Date(new Date(p.enddate).getTime() + DAY))

      row.deals.push({
        id: p.id,
        status: p.status,
        left,
        width: Math.max(0.5, right - left),
        title:
          p.name +
          ': ' +
          statusInfo(p.status).text +
          ', ' +
          formatDate(p.startdate) +
          ' to ' +
          formatDate(p.enddate) +
          ' (' +
          dealLength(p.startdate, p.enddate) +
          '), £' +
          formatMoney(p.amount),
      })

      const ask = renewalAskDate(p.enddate)
      row.asks.push({ id: p.id, date: ask, left: pos(ask) })
    })

  return [...byCouncil.values()].sort((a, b) => a.name.localeCompare(b.name))
})
</script>
<style scoped lang="scss">
.timeline {
  max-width: 900px;
}

.axis-row,
.tl-row {
  display: flex;
  align-items: center;
}

.tl-row {
  height: 1.75rem;
}

.label {
  flex: 0 0 11rem;
  padding-right: 0.5rem;
  font-size: 0.875rem;
}

.track {
  position: relative;
  flex: 1 1 auto;
  height: 100%;
}

.axis-row .track {
  height: 1.25rem;
}

.year {
  position: absolute;
  font-size: 0.75rem;
  color: $color-gray--dark;
  transform: translateX(-50%);
}

.gridline {
  position: absolute;
  top: 0;
  bottom: 0;
  border-left: 1px dashed $color-gray--light;
}

.today {
  position: absolute;
  top: 0;
  bottom: 0;
  border-left: 2px solid $color-blue--bright;
  z-index: 2;
}

.bar {
  position: absolute;
  top: 0.35rem;
  height: 1.05rem;
  border: none;
  border-radius: 0.2rem;
  padding: 0;
  cursor: pointer;
}

.status-Confirmed,
.status-Paid {
  background-color: $color-green--dark;
}

.status-Overdue {
  background-color: $color-red;
}

.status-InPrinciple {
  background-color: $color-orange--dark;
}

.status-Quoted {
  background-color: $color-gray--light;
}

.ask {
  position: absolute;
  top: 0.15rem;
  height: 1.45rem;
  border-left: 3px solid $color-gray--darker;
  z-index: 1;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.swatch {
  display: inline-block;
  width: 1rem;
  height: 0.6rem;
  border-radius: 0.15rem;
  vertical-align: middle;
}

.ask-key {
  display: inline-block;
  height: 0.9rem;
  border-left: 3px solid $color-gray--darker;
  vertical-align: middle;
}

.today-key {
  display: inline-block;
  height: 0.9rem;
  border-left: 2px solid $color-blue--bright;
  vertical-align: middle;
}

@media (max-width: 576px) {
  .label {
    flex-basis: 6rem;
  }
}
</style>
