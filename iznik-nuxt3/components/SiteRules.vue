<template>
  <div class="site-rules">
    <p>
      Freegle has one set of rules, the same everywhere. Posts are checked
      against them automatically when they go up, and other freeglers can
      <nuxt-link no-prefetch to="/help">report</nuxt-link> a post that slips
      through. If enough people agree, the post is taken down and the person who
      posted it is told why and how to fix it. Nobody has to wait for a
      volunteer.
    </p>
    <ol>
      <li id="rule-free">
        <strong>Everything is free.</strong> No selling, no swaps, no "suggested
        donations" and no loans. Items must be given away outright.
      </li>
      <li id="rule-legal">
        <strong>Everything is legal to give and to own.</strong> No copyright
        copies, no counterfeit goods, nothing you do not own.
      </li>
      <li id="rule-medicines">
        <strong>No medicines.</strong> No prescription or over-the-counter
        medicines, no animal medicines, no contact lenses or their solutions.
      </li>
      <li id="rule-animals">
        <strong>No live animals.</strong> Pond life, plants and seeds are fine.
      </li>
      <li id="rule-weapons">
        <strong>No weapons, and nothing unsafe by design.</strong> No firearms,
        air weapons, crossbows, knives designed as weapons, ammunition or sky
        lanterns. Ordinary kitchen and garden tools are fine.
      </li>
      <li id="rule-tobacco">
        <strong>No tobacco or vapes.</strong> No cigarettes, e-cigarettes,
        vaping devices or e-liquid.
      </li>
      <li id="rule-adult">
        <strong>No adult material.</strong>
      </li>
      <li id="rule-cash-value">
        <strong>No money, gift cards or vouchers.</strong>
      </li>
      <li id="rule-personal-details">
        <strong>Keep personal details out of posts.</strong> No phone numbers,
        email addresses or full addresses in the post itself. Share them in chat
        once you have agreed a handover.
      </li>
      <li id="rule-behaviour">
        <strong>Be decent to each other.</strong> Nothing offensive, no
        harassment. Turn up when you say you will, or say you cannot.
      </li>
    </ol>
    <p>
      If a post of yours is taken down, you will get a message saying which rule
      it broke. You can fix it and post again straight away.
    </p>
  </div>
</template>
<script setup>
// Experiment: one national rule set, replacing the ~33 per-community rule switches
// (weapons, firearms, knives, medicines, contact lenses, tobacco, vaping, alcohol, gas
// cylinders, tickets, car seats, pond life, copyright and the rest). Frozen at the
// majority setting across the 496 communities, per briefs/frozen-settings.md, with two
// overrides Edward made on 2026-09-20: tobacco/vapes stay banned despite a narrow
// majority for allowing them, and alcohol and event tickets are allowed and therefore
// not mentioned here at all (gas cylinders, car seats and a waste-carrier requirement
// were also majority-allowed, so they are not mentioned either).
</script>
<style scoped lang="scss">
.site-rules li {
  margin-bottom: 0.5rem;
}
</style>
