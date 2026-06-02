<!doctype html>
<html ⚡4email data-css-strict>
<head>
  <meta charset="utf-8">
  <script async src="https://cdn.ampproject.org/v0.js"></script>
  <script async custom-element="amp-timeago" src="https://cdn.ampproject.org/v0/amp-timeago-0.1.js"></script>
  <style amp4email-boilerplate>body{visibility:hidden}</style>
  <style amp-custom>
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      margin: 0;
      padding: 0;
      background-color: #ffffff;
      color: #333333;
    }
    .container {
      max-width: 600px;
      margin: 0 auto;
    }

    /* Header */
    .header {
      background-color: #338808;
      padding: 16px 20px;
      text-align: center;
    }

    /* Greeting */
    .greeting {
      padding: 20px 20px 10px 20px;
    }
    .greeting h2 {
      font-size: 16px;
      color: #333333;
      margin: 0 0 4px 0;
    }
    .greeting p {
      font-size: 14px;
      color: #555555;
      margin: 0;
    }

    /* Post cards */
    .post-card {
      padding: 12px 16px;
      border-bottom: 1px solid #eeeeee;
      display: flex;
      align-items: flex-start;
    }
    .post-image {
      width: 80px;
      flex-shrink: 0;
      border-radius: 4px;
    }
    .post-content {
      padding-left: 14px;
      flex: 1;
    }
    .post-type-offer {
      font-size: 11px;
      font-weight: bold;
      color: #338808;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin: 0 0 2px 0;
    }
    .post-type-wanted {
      font-size: 11px;
      font-weight: bold;
      color: #00A1CB;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin: 0 0 2px 0;
    }
    .post-title {
      font-size: 16px;
      font-weight: 600;
      margin: 0 0 4px 0;
    }
    .post-title a {
      color: #212529;
      text-decoration: none;
    }
    /* User-supplied description: larger and darker than location/time
       metadata so it reads as the actual content, but regular weight
       so it stays clearly secondary to the bold title. */
    .post-preview {
      font-size: 14px;
      color: #333333;
      margin: 0 0 6px 0;
      line-height: 1.5;
    }
    .post-loc {
      font-size: 12px;
      color: #777777;
      margin: 0 0 4px 0;
    }
    .post-time {
      font-size: 12px;
      color: #888888;
      margin: 0 0 4px 0;
    }
    .post-byline {
      font-size: 12px;
      color: #888888;
      margin: 6px 0 8px 0;
    }
    .post-byline img {
      width: 22px;
      height: 22px;
      border-radius: 50%;
      vertical-align: middle;
      margin-right: 6px;
    }
    .post-byline strong {
      color: #555555;
    }

    /* Per-card Reply button — opens the shared reply panel at the bottom.
       Same visual weight as the accordion's reply-toggle had. */
    .reply-btn {
      display: inline-block;
      background-color: #338808;
      color: #ffffff;
      font-size: 13px;
      font-weight: bold;
      padding: 8px 22px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      text-decoration: none;
    }
    .reply-btn.wanted {
      background-color: #00A1CB;
    }

    /* Browse button */
    .browse-section {
      padding: 25px 20px;
      text-align: center;
    }
    .browse-button {
      display: inline-block;
      background-color: #338808;
      color: #ffffff;
      font-size: 16px;
      font-weight: bold;
      padding: 14px 40px;
      text-decoration: none;
      border-radius: 4px;
    }

    /* Footer */
    .footer {
      background-color: #f5f5f5;
      padding: 20px;
      text-align: center;
    }
    .footer-text {
      font-size: 12px;
      color: #666666;
      line-height: 1.6;
      margin: 0 0 10px 0;
    }
    .footer-links {
      font-size: 12px;
      margin: 0 0 15px 0;
    }
    .footer-links a {
      color: #338808;
      text-decoration: none;
    }
    .footer-divider {
      border-top: 1px solid #dddddd;
      margin: 15px 40px;
    }
    .footer-charity {
      font-size: 11px;
      color: #666666;
      line-height: 1.5;
      margin: 0;
    }
  </style>
</head>
<body>
  <div class="container">
    {{-- Header --}}
    <div class="header">
      {{-- logo_url is the square Freegle icon (icon.png). The old 120x40 box
           forced a 3:1 ratio and squashed it flat — render it square. --}}
      <amp-img
        src="{{ config('freegle.branding.logo_url') }}"
        width="48"
        height="48"
        alt="{{ config('freegle.branding.name', 'Freegle') }}"
        layout="fixed"
      ></amp-img>
    </div>

    {{-- Greeting --}}
    <div class="greeting">
      <h2>Hi {{ $user->displayname ?? 'there' }},</h2>
      <p>Here {{ $postCount === 1 ? 'is' : 'are' }} <strong>{{ $postCount }}</strong> new post{{ $postCount === 1 ? '' : 's' }} from your Freegle communities:</p>
    </div>

    {{-- Post cards. The per-post amp-accordion+form was retired because it
         added ~1.5 KB of duplicated markup per post — at 200 posts that
         pushed the AMP payload over Gmail's 102 KB clipped threshold.
         Reply is now a styled link that opens the post page (which auto-
         expands the reply compose via ?reply=1). The in-email reply form
         can come back later via a single shared form bound to amp-state
         if/when we want it. --}}
    @foreach($posts as $index => $post)
    @if($postCount === 1)
    {{-- Single-post (immediate) digest: the item photo is the hero — full
         width and clickable through to the post. heroImageUrl is a 600x400
         cover-crop so the height is bounded (a tall portrait can't dominate),
         and amp-img layout="responsive" scales it full-width on mobile.
         The 16px side padding mirrors the MJML hero — flush-edge bleed
         looked harsh in Gmail. --}}
    <div style="padding: 16px 16px 0 16px;">
      <a href="{{ $post['fallbackReplyUrl'] }}" style="display: block; line-height: 0;">
        <amp-img src="{{ $post['heroImageUrl'] }}" width="600" height="400" layout="responsive" alt="{{ $post['itemName'] }}"></amp-img>
      </a>
    </div>
    <div class="post-card" style="display: block; border-bottom: none; padding-bottom: 0;">
      <div class="post-content" style="padding-left: 0;">
    @else
    <div class="post-card">
      <amp-img class="post-image" src="{{ $post['displayImageUrl'] }}" width="80" height="80" layout="fixed" alt="{{ $post['itemName'] }}"></amp-img>
      <div class="post-content">
    @endif
        <p class="{{ $post['type'] === 'Offer' ? 'post-type-offer' : 'post-type-wanted' }}">{{ $post['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}</p>
        <p class="post-title"><a href="{{ $post['fallbackReplyUrl'] }}">{{ $post['itemName'] }}</a></p>
        @if($post['locationName'] ?? null)
        <p class="post-loc">{{ $post['locationName'] }}</p>
        @endif
        @if($post['messageText'])
        {{-- User-supplied description: bigger and darker than location/byline
             metadata so the content stands out; styled via .post-preview.
             nl2br after escaping so user paragraph breaks survive the
             single-<p> truncation. AMP allows <br> inside <p>. --}}
        <p class="post-preview">{!! nl2br(e(\Illuminate\Support\Str::limit($post['messageText'], 120))) !!}</p>
        @endif
        <p class="post-time">
          <amp-timeago datetime="{{ $post['arrivalIso'] }}" locale="en" width="160" height="20" layout="fixed">{{ $post['arrivalFormatted'] }}</amp-timeago>
        </p>
        {{-- Avatar byline (V1 MultipleDigest parity). The 22px circular
             avatar pairs with the bold poster name; both immediate and daily
             carry this same byline. --}}
        <p class="post-byline">
          <amp-img src="{{ $post['posterAvatarUrl'] }}" width="22" height="22" layout="fixed" alt=""></amp-img>
          Posted by <strong>{{ \Illuminate\Support\Str::limit($post['posterName'], 30) }}</strong>
        </p>
        <a class="reply-btn{{ $post['type'] === 'Offer' ? '' : ' wanted' }}" href="{{ $post['fallbackReplyUrl'] }}">Reply</a>
      </div>
    </div>
    @endforeach

    {{-- Browse All Posts --}}
    <div class="browse-section">
      <a href="{{ $browseUrl }}" class="browse-button">Browse All Posts</a>
    </div>

    {{-- Footer --}}
    <div class="footer">
      <p class="footer-text">This email was sent with AMP to {{ $user->email_preferred }}</p>
      <p class="footer-links">
        <a href="{{ $settingsUrl }}">Change your email settings</a> &bull;
        <a href="{{ $unsubscribeUrl ?? $userSite . '/unsubscribe' }}">Unsubscribe</a>
      </p>
      <div class="footer-divider"></div>
      <p class="footer-charity">
        {{ $siteName ?? config('freegle.branding.name', 'Freegle') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br>
        Registered address: {{ config('freegle.branding.registered_address') }}
      </p>
    </div>
  </div>
</body>
</html>
