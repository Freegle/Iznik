<template>
  <div class="test-send border border-2 rounded p-3 mb-3">
    <h3 class="h5">{{ title }}</h3>
    <p class="mb-2"><slot /></p>
    <label :for="inputId" class="fw-bold">
      Send one test, to just this address:
    </label>
    <div class="d-flex flex-wrap gap-2 align-items-start">
      <b-form-input
        :id="inputId"
        :model-value="email"
        type="email"
        placeholder="One email address"
        style="max-width: 350px"
        @update:model-value="$emit('update:email', $event)"
      />
      <b-button
        variant="white"
        :disabled="testing || disabled"
        @click="$emit('send')"
      >
        <v-icon v-if="testing" icon="sync" class="fa-spin" />
        <v-icon v-else icon="envelope" />
        Send one test to this address only
      </b-button>
    </div>
    <p v-if="tested" class="text-success fw-bold mt-2 mb-0">
      One test sent, to {{ testedTo }} only. Nothing has gone to members. Check
      it arrived and looks right.
    </p>
    <p v-else-if="stale" class="text-danger fw-bold mt-2 mb-0">
      You've changed the ADMIN since the test, so please send another.
    </p>
    <p v-if="error" class="text-danger fw-bold mt-2 mb-0">
      {{ error }}
    </p>
  </div>
</template>
<script setup>
// The box for sending one test of an ADMIN to one address. The sending itself is in
// useAdminTestSend; this only shows it.
defineProps({
  title: { type: String, required: true },
  inputId: { type: String, required: true },
  email: { type: String, default: '' },
  testing: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  tested: { type: Boolean, default: false },
  testedTo: { type: String, default: null },
  stale: { type: Boolean, default: false },
  error: { type: String, default: null },
})

defineEmits(['update:email', 'send'])
</script>
