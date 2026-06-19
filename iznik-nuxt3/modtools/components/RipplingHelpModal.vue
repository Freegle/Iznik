<template>
  <div>
    <b-modal
      id="ripplingHelpModal"
      ref="modal"
      title="How does this work?"
      size="lg"
      no-stacking
    >
      <template #default>
        <p>
          This tool lets you explore how far a Freegle post reaches — the
          catchment area around an item, shaped by real travel time on the road
          network.
        </p>

        <RipplingExplanation />

        <h5>Using the map</h5>
        <ul>
          <li>
            <strong>Click the map</strong> or <strong>search</strong> for a
            location to set an offer point and see its catchment.
          </li>
          <li>
            Choose a travel mode (walk, cycle, or drive) and adjust the travel
            time to explore different reach sizes.
          </li>
          <li>
            The <strong>Fairness adjustment</strong> slider widens the reach
            into more deprived areas, so the coloured patches on the map show
            who gets the extra reach.
          </li>
          <li>
            Press <strong>Animate ripple</strong> to watch the catchment expand
            over time, showing how a real post would gradually reach more people
            if it goes uncollected.
          </li>
        </ul>
      </template>
      <template #footer>
        <b-button variant="white" @click="hide"> Close </b-button>
      </template>
    </b-modal>
  </div>
</template>

<script setup>
import { useOurModal } from '~/composables/useOurModal'

const { modal, show, hide } = useOurModal({ autoShow: false })

defineExpose({ show, hide })
</script>
