<template>
  <div>
    <!--
      Shared "which posts do I see?" explainer. Rendered both from the /help chat flow
      (helpTree['which-posts'] in HelpChatFlow.vue) and from the "How does this work?"
      modal on the browse page filters (WhichPostsModal.vue via PostFilters.vue), so the
      two stay consistent - edit the copy here, not in either caller.
    -->
    <h5>Which posts do I see?</h5>
    <p>The posts you see on Browse depend on a few filters above the map:</p>
    <ul>
      <li>
        <strong>Show posts from</strong> - "Nearby" shows posts that have
        reached your area, or you can pick one of your communities to see just
        its posts.
      </li>
      <li><strong>Show these posts</strong> - OFFERs, WANTEDs, or both.</li>
      <li>
        <strong>Sort by</strong> - "New to you" shows what you haven't seen yet
        first, "Newest posted" shows the most recent, and "Closest" puts the
        nearest posts first.
      </li>
      <li>
        The <strong>distance slider</strong> lets you limit how far away posts
        can come from - drag it towards "Nearer" to see only closer posts, or
        leave it at "Further" to see everything that reaches you.
      </li>
    </ul>
    <p>
      Within those filters, we show the most relevant posts first - a mixture of
      how close they are, how new they are, and how much interest they've
      already had - with anything you haven't seen yet shown ahead of posts you
      have.
    </p>
    <h5>Can I always reply?</h5>
    <p>
      Yes. Most posts you see have already reached your area. If you come across
      one that hasn't quite reached you yet - because you've widened the
      distance, changed the sort, or chosen a different view - go ahead and
      reply anyway. We'll hold your reply and pass it on to the owner the moment
      the post reaches you.
    </p>
    <h5>Why don't I see posts that are too far away?</h5>
    <p>
      You decide how far away posts can be, using the
      <strong>distance slider</strong>
      - both here above the map and in your
      <nuxt-link no-prefetch to="/settings">settings</nuxt-link> under
      <strong>Feed</strong>. Anything further away than that won't show up on
      Browse or in the emails we send you. Drag towards "Further" to cast a
      wider net, or "Nearer" to keep things local.
    </p>
    <h5>Why is my post shown to only some people nearby?</h5>
    <p>
      When your post reaches a neighbouring community, it's shown to the
      freeglers there who are closest to you and who've chosen to see posts from
      that far away - not necessarily everyone in that community. That keeps it
      relevant, so it reaches the people most likely to want your item.
    </p>
  </div>
</template>
<script setup></script>
