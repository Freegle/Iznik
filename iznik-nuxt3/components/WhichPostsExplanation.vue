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
      On the default view, you'll only see posts that have already reached your
      area, so you can always reply to what's in front of you. If you widen the
      distance, change the sort, or choose to see different posts, you might
      come across one that hasn't reached you quite yet - you'll be able to
      reply as soon as it does.
    </p>
  </div>
</template>
<script setup></script>
