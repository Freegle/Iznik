<template>
  <div
    class="sheet-backdrop"
    :data-testid="testid"
    @click.self="$emit('close')"
  >
    <div
      class="sheet"
      role="dialog"
      aria-modal="true"
      :aria-label="label || title"
      :style="{ maxHeight: height }"
    >
      <div class="sheet-handle" aria-hidden="true" />
      <div class="sheet-header">
        <div class="sheet-heading">
          <div class="sheet-title">{{ title }}</div>
          <div v-if="$slots.subtitle" class="sheet-sub">
            <slot name="subtitle" />
          </div>
        </div>
        <button
          type="button"
          class="sheet-close"
          aria-label="Close"
          :data-testid="testid + '-close'"
          @click="$emit('close')"
        >
          ✕
        </button>
      </div>
      <div v-if="$slots.tools" class="sheet-tools">
        <slot name="tools" />
      </div>
      <div class="sheet-body" :data-testid="testid + '-body'">
        <slot />
      </div>
      <div v-if="$slots.footer" class="sheet-footer">
        <slot name="footer" />
      </div>
    </div>
  </div>
</template>
<script setup>
// A sheet that rises over the chat, as WhatsApp opens for a poll or an attachment:
// backdrop, handle, title and close, a body that scrolls inside its own bounds, and an
// optional footer. The chat behind it never grows. Tapping the backdrop closes it.
defineProps({
  title: { type: String, required: true },
  label: { type: String, required: false, default: '' },
  testid: { type: String, required: false, default: 'sheet' },
  height: { type: String, required: false, default: '92%' },
})
defineEmits(['close'])
</script>
<style scoped lang="scss">
.sheet-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: flex-end;
  z-index: 1050;
}

.sheet {
  background: #f5f7f8;
  width: 100%;
  border-radius: 16px 16px 0 0;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.sheet-handle {
  width: 36px;
  height: 4px;
  border-radius: 2px;
  background: #c9d0d6;
  margin: 0.5rem auto 0;
}

.sheet-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 0.5rem 0.9rem 0.4rem;
}

.sheet-title {
  font-weight: 700;
  font-size: 1.05rem;
}

.sheet-sub {
  font-size: 0.85rem;
  color: #5a6470;
}

.sheet-close {
  border: 0;
  background: transparent;
  font-size: 1.1rem;
  padding: 0.2rem 0.4rem;
}

.sheet-tools {
  padding: 0 0.9rem 0.5rem;
}

.sheet-body {
  overflow-y: auto;
  flex: 1 1 auto;
  min-height: 0;
  padding-bottom: 0.5rem;
}

.sheet-footer {
  padding: 0.6rem 0.9rem 1rem;
  border-top: 1px solid #e0e4e8;
  background: #fff;
}
</style>
