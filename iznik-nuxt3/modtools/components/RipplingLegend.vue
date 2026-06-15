<template>
  <div v-if="mode === 'inbound'" class="rpl-legend rpl-legend-inbound">
    <h4>Legend</h4>
    <div class="rpl-leg-item">
      <div style="width:14px;height:14px;border-radius:50%;background:#cc0000;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.4);flex-shrink:0" />
      Your location
    </div>
    <div class="rpl-leg-item">
      <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
        <span style="width:9px;height:9px;border-radius:50%;background:#27ae60;border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4)" />
        <span>1</span>
      </span>
      Active home-group · number = digest position
    </div>
    <div class="rpl-leg-item">
      <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
        <span style="width:9px;height:9px;border-radius:50%;background:#1f77b4;border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4)" />
        <span>2</span>
      </span>
      Active rippled in · from a neighbouring group
    </div>
    <div class="rpl-leg-item">
      <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
        <span style="width:9px;height:9px;border-radius:50%;background:#27ae60;border:1.5px solid #fff;opacity:0.45" />
        <span style="opacity:0.6">3</span>
      </span>
      Faded = already taken / promised
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background:rgba(125,60,152,0.07);border:1.5px dashed #7d3c98" />
      Home-group area
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background:none;border:2.5px solid #cc0000" />
      Maximum reach
    </div>
    <div class="rpl-leg-item">
      <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
        <span style="width:9px;height:9px;border-radius:50%;background:#f39c12;border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4)" />
      </span>
      Promised — still in flight
    </div>
    <div class="rpl-leg-item">
      <span style="display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;color:#222">
        <span style="width:9px;height:9px;border-radius:50%;background:#888;border:1.5px solid #fff;box-shadow:0 0 1px rgba(0,0,0,0.4)" />
      </span>
      Completed since last digest
    </div>
  </div>

  <div v-else class="rpl-legend">
    <h4>Legend</h4>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background:none;border:2.5px solid #cc0000" />
      Travel time boundary
    </div>
    <div style="font-size:10px;color:#888;margin:3px 0 2px">
      Deprivation (outside boundary):
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background:#d73027;opacity:0.75" />
      Q1 — most deprived
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background:#fc8d59;opacity:0.75" />
      Q2
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background:#fee08b;opacity:0.75;border:1px solid #ccc" />
      Q3
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background:#91cf60;opacity:0.75" />
      Q4
    </div>
    <div class="rpl-leg-item">
      <div class="rpl-leg-swatch" style="background:#1a9850;opacity:0.75" />
      Q5 — least deprived
    </div>
    <div class="rpl-leg-item" style="margin-top:5px;padding-top:5px;border-top:1px solid #eee">
      <div style="width:10px;height:10px;border-radius:50%;background:#e8380d;flex-shrink:0" />
      Active Freegler
    </div>
    <div class="rpl-leg-item" style="margin-top:5px;padding-top:5px;border-top:1px solid #eee">
      <div class="rpl-leg-swatch" style="background:none;border:2px solid #27ae60" />
      Freegle group
    </div>
    <div class="rpl-leg-item">
      <span style="color:#e07000;font-size:13px;margin-right:2px">⚡</span>
      Cross-posting begins
    </div>
  </div>
</template>

<script setup>
defineProps({
  mode: {
    type: String,
    required: true,
    validator: (v) => v === 'outbound' || v === 'inbound',
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
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 3px 0;
}
.rpl-leg-swatch {
  width: 14px;
  height: 10px;
  border-radius: 2px;
  flex-shrink: 0;
}
</style>
