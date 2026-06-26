{{-- Shared multi-post daily card (AMP4Email).

     Used by BOTH the live "Post cards" loop (multi-post branch) and the greyed
     "Came and went" loop so the two can never drift apart. The came-and-went
     variant is the exact same .post-card grid — square thumb + head + rest —
     just (a) without the Reply button ($showReply=false) and (b) greyed via
     the .cag-muted modifier ($muted=true).

     AMP4Email forbids inline styles/JS, so the muted look is class-based: the
     .cag-muted class (in <style amp-custom>) recolours the title/meta/byline to
     the greyed #777/#999 palette the old came-and-went used.

     Required vars:
       $post  — the prepared card array
     Optional flags:
       $showReply — bool, default true. When false, omit the Reply accordion.
       $muted     — bool, default false. When true, grey the card.

     For muted cards the meta line reads "Taken/Received · <date>" via
     $post['metaText'] instead of the live distance · arrival pairing. --}}
@php
    $showReply = $showReply ?? true;
    $muted = $muted ?? false;

    // Meta line, precomputed to avoid an inline @else@if (Blade leaves the
    // nested @if literal but compiles its @endif -> "unexpected endif").
    $metaHtml = $muted
        ? e($post['metaText'])
        : (($post['distanceText'] ? '<span>&#x1F4CD; ' . e($post['distanceText']) . '</span><span class="sep">·</span>' : '') . '<span>&#x1F552; ' . e($post['arrivalFormatted']) . '</span>');
@endphp
<div id="msg-{{ $post['message']->id }}" class="post-card{{ $muted ? ' cag-muted' : '' }}">
  {{-- Multi-post thumbnail. The grid above gives the image cell a
       non-relative computed height (matches the content cell), and
       amp-img layout="fill" + object-fit:cover stretches the photo
       to fill that cell — so the photo's bottom edge always lines
       up with the bottom of the content column. --}}
  <div class="post-img-wrap">
    {{-- The anchor uses class="post-img-link" (position:absolute, all edges
         at 0) to fill the .post-img-wrap cell so the whole photo is
         clickable, not just a zero-height inline box. --}}
    <a href="{{ $post['viewUrl'] }}" class="post-img-link">
      <amp-img layout="fill" src="{{ $post['thumbImageUrl'] }}" alt="{{ $post['itemName'] }}"></amp-img>
    </a>
  </div>
  <div class="post-head">
    {{-- OFFER / WANTED pill. Wrapped in a <p> so it gets its own
         line + bottom margin, but the visible chip is a <span> —
         p with `display: inline-block` stretched full-width in the
         AMP4Email environment (something in the runtime/spec
         cascade pinned p to block), span with display: inline-block
         reliably hugs its text. --}}
    <p class="post-type-row"><span class="{{ $post['type'] === 'Offer' ? 'post-type-offer' : 'post-type-wanted' }}">{{ $post['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}</span></p>
    <p class="post-title">
      <a href="{{ $post['viewUrl'] }}">{{ $post['itemName'] }}</a>
      @if($post['locationName'] ?? null)
      {{-- Location sits directly under the title to match the HTML
           variant's pill+title block — no pin emoji here either; the
           pin lives next to the distance in the meta row below. --}}
      <br><span class="post-location">{{ $post['locationName'] }}</span>
      @endif
    </p>
  </div>
  <div class="post-rest">
    @if($post['messageText'])
    {{-- User-supplied description. Multi-post truncates for AMP size. --}}
    <p class="post-preview">{!! nl2br(e(\Illuminate\Support\Str::limit($post['messageText'], 120))) !!}</p>
    @endif
    {{-- Meta row. Live cards: 📍 distance · 🕒 time. Muted (came and
         went) cards: "Taken/Received · <date>" instead. --}}
    <p class="post-meta">
      {!! $metaHtml !!}
    </p>
    @if(!$muted)
    {{-- Avatar byline (V1 MultipleDigest parity). Sits on its own
         row below the metadata strip so the avatar pairs cleanly
         with the bold poster name rather than crowding the time/pin. --}}
    <p class="post-byline">
      <amp-img src="{{ $post['posterAvatarUrl'] }}" width="22" height="22" layout="fixed" alt=""></amp-img>
      Posted by <strong>{{ \Illuminate\Support\Str::limit($post['posterName'], 30) }}</strong>@if(!empty($post['groupName'])) on <a href="{{ $post['groupUrl'] }}" class="group-link">{{ $post['groupName'] }}</a>@endif
    </p>
    @if(!empty($post['firstPostedFormatted']))
    {{-- V1 single.html parity: only shown when the message has actually
         been reposted to this group. --}}
    <p class="post-first-posted">First posted {{ $post['firstPostedFormatted'] }}</p>
    @endif
    @endif
    @if($showReply && empty($post['isOwnPost']))
    {{-- Per-post Reply trigger. Instead of a whole <amp-form> per post (one
         form × 70 posts blows past Gmail's ~102 KB AMP cap → AMP rejected,
         HTML fallback), this is a tiny tap button (~80 bytes) that selects
         this message into <amp-state id="r"> and opens the ONE shared reply
         panel (<amp-sidebar id="replyPanel"> in unified.blade.php). The
         sidebar's hidden inputs read the title/token/expiry for this msgid
         out of <amp-state id="d"> via [value] amp-bind. Suppressed for the
         recipient's own posts (you can't reply to yourself). --}}
    <button class="reply-btn{{ $post['type'] === 'Offer' ? '' : ' wanted' }}"
            on="tap:AMP.setState({r:{m:{{ $post['message']->id }}}}),replyPanel.open">Reply</button>
    @endif
  </div>
</div>
