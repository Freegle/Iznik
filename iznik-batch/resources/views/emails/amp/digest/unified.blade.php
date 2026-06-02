<!doctype html>
<html ⚡4email data-css-strict>
<head>
  <meta charset="utf-8">
  <script async src="https://cdn.ampproject.org/v0.js"></script>
  <script async custom-element="amp-form" src="https://cdn.ampproject.org/v0/amp-form-0.1.js"></script>
  <script async custom-element="amp-bind" src="https://cdn.ampproject.org/v0/amp-bind-0.1.js"></script>
  <script async custom-element="amp-timeago" src="https://cdn.ampproject.org/v0/amp-timeago-0.1.js"></script>
  <script async custom-template="amp-mustache" src="https://cdn.ampproject.org/v0/amp-mustache-0.2.js"></script>
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
      width: 160px;
      flex-shrink: 0;
      border-radius: 4px;
    }
    .post-content {
      padding-left: 14px;
      flex: 1;
      min-width: 0; /* allow content to shrink */
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
    /* Avatar byline: flex so the amp-img (which renders as a block-level
       custom element with its own wrapper) lines up vertically centred
       with the "Posted by …" text. Without flex the amp-img sat above
       the baseline and got clipped on the left margin in Gmail. */
    .post-byline {
      font-size: 12px;
      color: #888888;
      margin: 6px 0 8px 0;
      display: flex;
      align-items: center;
    }
    .post-byline amp-img {
      border-radius: 50%;
      margin-right: 8px;
      flex-shrink: 0;
    }
    .post-byline-text {
      flex: 1;
      min-width: 0;
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

    /* Shared reply panel (single form for all posts, bound to amp-state).
       Hidden by default; tapped Reply buttons set reply.open=true and
       scroll the panel into view. */
    .reply-panel {
      padding: 16px 20px;
      background-color: #f8f8f8;
      border-top: 1px solid #dddddd;
      border-bottom: 1px solid #dddddd;
      margin: 8px 0;
    }
    .reply-panel-title {
      font-size: 14px;
      color: #333333;
      margin: 0 0 10px 0;
    }
    .reply-panel-title strong {
      color: #338808;
    }
    .reply-textarea {
      width: 100%;
      min-height: 80px;
      padding: 10px;
      border: 1px solid #ced4da;
      border-radius: 4px;
      font-size: 14px;
      font-family: inherit;
      resize: vertical;
      box-sizing: border-box;
    }
    .reply-textarea:focus {
      outline: none;
      border-color: #338808;
    }
    .reply-submit {
      background-color: #338808;
      color: #ffffff;
      font-size: 14px;
      font-weight: bold;
      padding: 10px 22px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      margin-top: 8px;
    }
    .reply-fallback {
      font-size: 12px;
      color: #666666;
      margin: 8px 0 0 0;
    }
    .reply-fallback a {
      color: #338808;
      text-decoration: none;
    }
    .form-status {
      margin-top: 10px;
    }
    .submit-success {
      background-color: #e8f5e0;
      border: 1px solid #338808;
      color: #2a6d07;
      padding: 12px;
      border-radius: 4px;
    }
    .submit-error {
      background-color: #f2dede;
      border: 1px solid #d9534f;
      color: #a94442;
      padding: 12px;
      border-radius: 4px;
    }
    .submitting-msg {
      background-color: #ffffff;
      border: 1px solid #338808;
      color: #333333;
      padding: 12px;
      border-radius: 4px;
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

    {{-- Shared reply state: amp-state holds the active post's id/title/token,
         and the SINGLE reply form at the bottom binds its action-xhr URL and
         visibility off this state. Each post's Reply button just sets the
         state via on="tap:AMP.setState(...)". This replaces the per-post
         amp-accordion+amp-form (~2 KB each) that was blowing the AMP payload
         past Gmail's 102 KB clipped threshold — 200 posts now fit easily. --}}
    <amp-state id="reply">
      <script type="application/json">{"mid":0,"title":"","token":"","exp":0,"isOffer":false,"open":false}</script>
    </amp-state>

    {{-- Post cards --}}
    @foreach($posts as $index => $post)
    @php
        $isSingle = $postCount === 1;
        $tapState = json_encode([
            'reply' => [
                'mid'    => (int) $post['message']->id,
                'title'  => $post['itemName'],
                'token'  => $post['ampReplyToken'] ?? '',
                'uid'    => (int) $post['ampReplyUid'] ?? 0,
                'exp'    => (int) ($post['ampReplyExp'] ?? 0),
                'isOffer'=> $post['type'] === 'Offer',
                'open'   => true,
            ],
        ], JSON_UNESCAPED_SLASHES);
    @endphp
    @if($isSingle)
    {{-- Single-post (immediate): item photo as hero — 600x400 cover-crop
         responsive on mobile. 16px side padding mirrors the MJML hero
         (flush-edge bleed looked harsh in Gmail). --}}
    <div style="padding: 16px 16px 0 16px;">
      <a href="{{ $post['fallbackReplyUrl'] }}" style="display: block; line-height: 0;">
        <amp-img src="{{ $post['heroImageUrl'] }}" width="600" height="400" layout="responsive" alt="{{ $post['itemName'] }}"></amp-img>
      </a>
    </div>
    <div class="post-card" style="display: block; border-bottom: none; padding-bottom: 0;">
      <div class="post-content" style="padding-left: 0;">
    @else
    <div class="post-card">
      {{-- Larger thumbnail (160x120) to match the MJML multi-post crop,
           but using the smaller-bandwidth displayImageUrl (the 240x180
           thumb served by the delivery proxy). Fixed width/height keeps
           card heights uniform across the digest. --}}
      <amp-img class="post-image" src="{{ $post['thumbImageUrl'] }}" width="160" height="120" layout="fixed" alt="{{ $post['itemName'] }}"></amp-img>
      <div class="post-content">
    @endif
        <p class="{{ $post['type'] === 'Offer' ? 'post-type-offer' : 'post-type-wanted' }}">{{ $post['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}</p>
        <p class="post-title"><a href="{{ $post['fallbackReplyUrl'] }}">{{ $post['itemName'] }}</a></p>
        @if($post['locationName'] ?? null)
        <p class="post-loc">{{ $post['locationName'] }}</p>
        @endif
        @if($post['messageText'])
        {{-- User-supplied description: bigger and darker than location /
             byline metadata so the content stands out. nl2br after
             escaping so user paragraph breaks survive the single-<p>
             truncation. AMP allows <br> inside <p>. --}}
        <p class="post-preview">{!! nl2br(e(\Illuminate\Support\Str::limit($post['messageText'], 120))) !!}</p>
        @endif
        {{-- Distance + arrival on one line (matches the MJML card). --}}
        <p class="post-time">
          @if($post['distanceText'])<span style="margin-right: 10px;">&#x1F4CD; {{ $post['distanceText'] }}</span>@endif
          &#x1F552; <amp-timeago datetime="{{ $post['arrivalIso'] }}" locale="en" width="120" height="16" layout="fixed">{{ $post['arrivalFormatted'] }}</amp-timeago>
        </p>
        {{-- Avatar byline with flex alignment so amp-img sits vertically
             centred with the "Posted by …" text rather than clipping off
             the left edge. --}}
        <p class="post-byline">
          <amp-img src="{{ $post['posterAvatarUrl'] }}" width="22" height="22" layout="fixed" alt=""></amp-img>
          <span class="post-byline-text">Posted by <strong>{{ \Illuminate\Support\Str::limit($post['posterName'], 30) }}</strong></span>
        </p>
        {{-- Reply: opens the shared in-email form below by setting amp-state.
             on="tap:AMP.setState(...)" populates the active post; the form
             scrolls into view via the chained AMP.scrollTo action. --}}
        <button
          class="reply-btn{{ $post['type'] === 'Offer' ? '' : ' wanted' }}"
          on='tap:AMP.setState({{ $tapState }}),AMP.scrollTo(id="reply-panel",position="center")'
        >Reply</button>
      </div>
    </div>
    @endforeach

    {{-- ─── Shared reply panel ──────────────────────────────────────────
         Single form for ALL posts; appears once a Reply button is tapped.
         [hidden] is bound to reply.open so the panel stays out of the way
         until a user opts to reply. [action-xhr] is built from the active
         post's id + per-message HMAC token (so the backend can verify the
         reply belongs to this digest+user+message). Hidden inputs are
         bound to the same state via amp-bind so amp-form posts the right
         metadata even though the form itself is shared.
         ─────────────────────────────────────────────────────────────────── --}}
    <div id="reply-panel" class="reply-panel" hidden [hidden]="!reply.open">
      <p class="reply-panel-title">Reply to <strong [text]="reply.title">…</strong></p>
      <form method="post"
            action-xhr="https://example.com/placeholder"
            [action-xhr]="'{{ rtrim(config('freegle.amp.api_base', $userSite), '/') }}/amp/digest/' + reply.mid + '/reply?rt=' + reply.token + '&uid=' + reply.uid + '&exp=' + reply.exp"
            target="_top">
        <textarea class="reply-textarea" name="message" placeholder="Type your reply..." required minlength="1" maxlength="10000"></textarea>
        <button type="submit" class="reply-submit">Send Reply</button>
        <div submitting>
          <div class="form-status"><div class="submitting-msg">Sending your reply...</div></div>
        </div>
        <div submit-success>
          <template type="amp-mustache">
            <div class="form-status"><div class="submit-success">@{{message}}</div></div>
          </template>
        </div>
        <div submit-error>
          <template type="amp-mustache">
            <div class="form-status"><div class="submit-error">@{{message}}</div></div>
          </template>
        </div>
      </form>
      <p class="reply-fallback">Trouble sending? <a [href]="'{{ $userSite }}/message/' + reply.mid + '?reply=1'" href="{{ $userSite }}">Reply on the website</a> instead.</p>
    </div>

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
