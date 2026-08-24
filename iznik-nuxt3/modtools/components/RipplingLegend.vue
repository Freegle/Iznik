<template>
  <!-- Catchment tab before a group is picked: there is nothing on the map, so a key
       is worse than no key. It used to render its heading and a blue "Group area"
       swatch over an empty map, which read as "the group area is the blue thing" and
       sent a moderator looking for a blue outline that was never drawn
       (Discourse 9808/728). Say what to do instead. -->
  <div v-if="mode === 'catchment' && !bands.length" class="rpl-legend">
    <h4>No group selected</h4>
    <div
      style="font-size: 11px; color: #555; max-width: 190px; line-height: 1.4"
    >
      Type a group name in the Group box to see its area and the catchment
      around it.
    </div>
  </div>

  <!-- Catchment tab: heatmap key. Colours + minute labels come from the actual bands
       drawn on the map (passed in via `bands`), so the key always matches. -->
  <div v-else-if="mode === 'catchment'" class="rpl-legend">
    <h4>Ripples in within</h4>
    <div v-for="b in bands" :key="b.label" class="rpl-leg-item">
      <div
        class="rpl-leg-swatch"
        :style="{ background: b.color, opacity: 0.85 }"
      />
      <span
        >{{ b.label }}<br v-if="b.sub" /><small v-if="b.sub" style="color: #888"
          >reached {{ b.sub }} after posting</small
        ></span
      >
    </div>
    <div
      class="rpl-leg-item"
      style="margin-top: 5px; padding-top: 5px; border-top: 1px solid #eee"
    >
      <div
        class="rpl-leg-swatch"
        style="background: none; border: 2px solid #005bb5"
      />
      Group area
    </div>
  </div>

  <!-- Inbound direction: the same reach map read the other way round. Posts made
       inside the line can reach the pin; nothing here is about a digest any more, so
       the numbered post pins and their lifecycle colours have gone with it. -->
  <div v-else-if="mode === 'inbound'" class="rpl-legend">
    <h4>Legend</h4>
    <div class="rpl-leg-item">
      <div
        style="
          width: 10px;
          height: 10px;
          border-radius: 50%;
          background: #fff;
          border: 3px solid #e8380d;
          flex-shrink: 0;
        "
      />
      This place
    </div>
    <div class="rpl-leg-item">
      <div
        class="rpl-leg-swatch"
        style="background: none; border: 2.5px solid #cc0000"
      />
      Posts made inside this line can reach you
    </div>
    <div
      class="rpl-leg-item"
      style="margin-top: 5px; padding-top: 5px; border-top: 1px solid #eee"
    >
      <div
        style="
          width: 10px;
          height: 10px;
          border-radius: 50%;
          background: #e8380d;
          flex-shrink: 0;
        "
      />
      Active Freegler
    </div>
    <div class="rpl-leg-item">
      <div
        class="rpl-leg-swatch"
        style="background: none; border: 2px solid #27ae60"
      />
      Freegle group you would see posts from
    </div>
  </div>

  <!-- Minimal legend for the per-post reach modal: only what that static view draws
       (no deprivation quintiles, which aren't shown there). -->
  <div v-else-if="minimal" class="rpl-legend">
    <h4>Legend</h4>
    <div class="rpl-leg-item">
      <div
        class="rpl-leg-swatch"
        style="background: none; border: 2.5px solid #cc0000"
      />
      Current reach boundary
    </div>
    <div class="rpl-leg-item">
      <div
        style="
          width: 10px;
          height: 10px;
          border-radius: 50%;
          background: #e8380d;
          flex-shrink: 0;
        "
      />
      Active Freegler
    </div>
    <div class="rpl-leg-item">
      <div
        class="rpl-leg-swatch"
        style="background: none; border: 2px solid #27ae60"
      />
      Freegle group
    </div>
    <div class="rpl-leg-item">
      <span style="color: #e07000; font-size: 13px; margin-right: 2px">⚡</span>
      Cross-posting begins
    </div>
  </div>

  <div v-else class="rpl-legend">
    <h4>Legend</h4>
    <div class="rpl-leg-item">
      <div
        class="rpl-leg-swatch"
        style="background: none; border: 2.5px solid #cc0000"
      />
      Travel time boundary
    </div>
    <div style="font-size: 10px; color: #888; margin: 3px 0 2px">
      Deprivation, blue outline (outside boundary):
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background: #d73027; opacity: 0.75" />
      Q1 — most deprived
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background: #fc8d59; opacity: 0.75" />
      Q2
    </div>
    <div class="rpl-leg-item">
      <div
        class="rpl-leg-swatch"
        style="background: #fee08b; opacity: 0.75; border: 1px solid #ccc"
      />
      Q3
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background: #91cf60; opacity: 0.75" />
      Q4
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background: #1a9850; opacity: 0.75" />
      Q5 — least deprived
    </div>
    <div
      class="rpl-leg-item"
      style="margin-top: 5px; padding-top: 5px; border-top: 1px solid #eee"
    >
      <div
        style="
          width: 10px;
          height: 10px;
          border-radius: 50%;
          background: #e8380d;
          flex-shrink: 0;
        "
      />
      Active Freegler
    </div>
    <div
      class="rpl-leg-item"
      style="margin-top: 5px; padding-top: 5px; border-top: 1px solid #eee"
    >
      <div
        class="rpl-leg-swatch"
        style="background: none; border: 2px solid #27ae60"
      />
      Freegle group
    </div>
    <div class="rpl-leg-item">
      <span style="color: #e07000; font-size: 13px; margin-right: 2px">⚡</span>
      Cross-posting begins
    </div>
  </div>
</template>

<script setup>
defineProps({
  mode: {
    type: String,
    required: true,
    validator: (v) => v === 'outbound' || v === 'inbound' || v === 'catchment',
  },
  // Minimal legend for the per-post reach modal: only what that static view draws.
  minimal: {
    type: Boolean,
    default: false,
  },
  // Catchment heatmap key: [{ color, label }] per drive-time band (catchment mode only).
  bands: {
    type: Array,
    default: () => [],
  },
})
</script>

<style>
.rpl-legend {
  position: absolute;
  bottom: 10px;
  left: 10px;
  background: rgba(255, 255, 255, 0.92);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 11px;
  z-index: 1000;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.rpl-legend h4 {
  margin: 0 0 6px;
  font-size: 11px;
  color: #555;
}
.rpl-leg-item {
  display: grid;
  grid-template-columns: 16px 1fr;
  align-items: center;
  gap: 6px;
  margin: 3px 0;
}
.rpl-leg-item > :first-child {
  justify-self: center;
}
.rpl-leg-swatch {
  width: 14px;
  height: 10px;
  border-radius: 2px;
  flex-shrink: 0;
}
</style>
