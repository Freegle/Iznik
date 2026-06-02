<!doctype html>
<html ⚡4email data-css-strict>
<head>
  <meta charset="utf-8">
  <script async src="https://cdn.ampproject.org/v0.js"></script>
  <script async custom-element="amp-form" src="https://cdn.ampproject.org/v0/amp-form-0.1.js"></script>
  <script async custom-element="amp-accordion" src="https://cdn.ampproject.org/v0/amp-accordion-0.1.js"></script>
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

    /* Post card. flex-wrap means the image+content sit side by side on the
       first row and the per-post amp-accordion (Reply form) wraps to its
       own full-width second row underneath — so the Reply trigger never
       dangles next to the image. align-items:flex-start keeps the image
       top-aligned with the content (rather than vertically centring an
       awkward gap when the photo is shorter than the text). */
    .post-card {
      padding: 12px 16px;
      border-bottom: 1px solid #eeeeee;
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
    }
    .post-img-wrap {
      width: 200px;
      flex-shrink: 0;
    }
    .post-img-wrap amp-img {
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
    /* Avatar byline. Use inline-block + line-height matched to the
       avatar height (22px) so the amp-img (which renders as an
       inline-block custom element at its declared 22x22) sits on the
       text baseline without flex clipping it on the left edge in Gmail. */
    .post-byline {
      font-size: 12px;
      color: #888888;
      margin: 14px 0 8px 0; /* breathing room above the avatar row so it
                                doesn't sit hard up against the time/pin row */
      line-height: 22px;
    }
    /* Spacing above the distance/time row so the pin icon has air above
       it (was visually crammed against the description). */
    .post-time {
      margin-top: 10px;
    }
    .post-byline amp-img {
      border-radius: 50%;
      margin-right: 8px;
      vertical-align: middle;
    }
    .post-byline strong {
      color: #555555;
    }
    .post-first-posted {
      font-size: 11px;
      color: #999999;
      margin: 0 0 8px 0;
    }
    .post-actions a {
      color: #555555;
      text-decoration: none;
      font-size: 12px;
      margin-right: 12px;
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

    /* Per-post Reply accordion — full-width second row of the card.
       The accordion's section heading IS the visible Reply button; tapping
       it expands the form below. */
    .reply-acc {
      width: 100%;
      margin-top: 12px;
    }
    .reply-acc section {
      border: none;
    }
    .reply-toggle {
      margin: 0;
      list-style: none;
      cursor: pointer;
    }
    .reply-form-container {
      padding: 12px 0 0 0;
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

    @if($postCount !== 1)
    {{-- Greeting only on the multi-post (daily) digest. Immediate is
         always exactly one post so the "Here is 1 new post" preamble is
         silly — drop straight to the post. --}}
    <div class="greeting">
      <h2>Hi {{ $user->displayname ?? 'there' }},</h2>
      <p>Here are <strong>{{ $postCount }}</strong> new posts from your Freegle communities:</p>
    </div>
    @endif

    {{-- Shared amp-mustache templates referenced by id from every post's
         amp-form. AMP4Email disallows the shared-form / amp-bind approach
         (the validator rejects [action-xhr] on form), so every post has
         its OWN amp-form — sharing the success/error message templates is
         the trick that keeps 200-post digests under Gmail's size cap
         (saves ~300 bytes per post vs inlining the same template in each
         form's submit-success / submit-error wrappers). --}}
    {{-- @{{message}} is Blade's escape for amp-mustache: Blade preserves the
         literal {{message}} so the amp runtime can interpolate the success/
         error response body at form-submit time. Without the @, Blade tries
         to evaluate "message" as a PHP constant and throws at render. --}}
    <template type="amp-mustache" id="rsuccess">
      <div class="form-status"><div class="submit-success">@{{message}}</div></div>
    </template>
    <template type="amp-mustache" id="rerror">
      <div class="form-status"><div class="submit-error">@{{message}}</div></div>
    </template>

    {{-- Post cards --}}
    @foreach($posts as $index => $post)
    @php $isSingle = $postCount === 1; @endphp
    @if($isSingle)
    {{-- Single-post (immediate): item photo as hero — 600x400 cover-crop
         responsive on mobile. 16px side padding mirrors the MJML hero. --}}
    <div style="padding: 16px 16px 0 16px;">
      <a href="{{ $post['fallbackReplyUrl'] }}" style="display: block; line-height: 0;">
        <amp-img src="{{ $post['heroImageUrl'] }}" width="600" height="400" layout="responsive" alt="{{ $post['itemName'] }}"></amp-img>
      </a>
    </div>
    <div class="post-card" style="display: block; border-bottom: none; padding-bottom: 0;">
      <div class="post-content" style="padding-left: 0;">
    @else
    <div class="post-card">
      {{-- Multi-post thumbnail: square (240x240 server-side cover-crop,
           displayed 200x200) — the square aspect lands close to typical
           content height so the photo bottom aligns more naturally with
           the Reply button's bottom. amp-img layout="fixed" is the only
           amp-img layout that reliably works inside a flex item without
           an explicit parent height; layout="fill" requires the parent
           to have a non-flex computed height, which we don't have. --}}
      <div class="post-img-wrap">
        <amp-img layout="fixed" width="200" height="200" src="{{ $post['thumbImageUrl'] }}" alt="{{ $post['itemName'] }}"></amp-img>
      </div>
      <div class="post-content">
    @endif
        <p class="{{ $post['type'] === 'Offer' ? 'post-type-offer' : 'post-type-wanted' }}">{{ $post['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}</p>
        <p class="post-title"><a href="{{ $post['fallbackReplyUrl'] }}">{{ $post['itemName'] }}</a></p>
        @if($post['locationName'] ?? null)
        <p class="post-loc">{{ $post['locationName'] }}</p>
        @endif
        @if($post['messageText'])
        {{-- User-supplied description. Immediate (single-post) renders the
             full body — that's the email's only post, no need to truncate.
             Multi-post truncates so 200 cards fit Gmail's AMP size limit. --}}
        <p class="post-preview">{!! nl2br(e($isSingle ? $post['messageText'] : \Illuminate\Support\Str::limit($post['messageText'], 120))) !!}</p>
        @endif
        {{-- Distance + arrival on one line (matches the MJML card). --}}
        <p class="post-time">
          @if($post['distanceText'])<span style="margin-right: 10px;">&#x1F4CD; {{ $post['distanceText'] }}</span>@endif
          &#x1F552; <amp-timeago datetime="{{ $post['arrivalIso'] }}" locale="en" width="120" height="16" layout="fixed">{{ $post['arrivalFormatted'] }}</amp-timeago>
        </p>
        {{-- Avatar byline (V1 MultipleDigest parity). 22x22 inline-block
             avatar paired with the bold poster name. --}}
        <p class="post-byline">
          <amp-img src="{{ $post['posterAvatarUrl'] }}" width="22" height="22" layout="fixed" alt=""></amp-img>
          Posted by <strong>{{ \Illuminate\Support\Str::limit($post['posterName'], 30) }}</strong>
        </p>
        @if(!empty($post['firstPostedFormatted']))
        {{-- V1 single.html parity: only shown when the message has actually
             been reposted to this group. --}}
        <p class="post-first-posted">First posted {{ $post['firstPostedFormatted'] }}</p>
        @endif
        @if($isSingle)
        {{-- Secondary actions on the immediate hero (V1 single.html parity). --}}
        <p class="post-actions" style="margin-top: 12px;">
          <a href="{{ $browseUrl }}">Browse other posts</a>
          <a href="{{ $userSite }}/offer">Post something</a>
        </p>
        @endif
      </div>
      {{-- ─── Per-post reply form (collapsed) ─────────────────────────────
           Lives inside the same .post-card as its image+content (which makes
           the Reply trigger sit immediately below this post, not floating in
           a shared panel somewhere). amp-accordion keeps the form collapsed
           by default; the open header IS the Reply button. amp-mustache
           templates for success/error are referenced by id from the shared
           <template> blocks at the top of body so we don't pay the ~300
           bytes-per-post cost of inlining them in every form.
           ───────────────────────────────────────────────────────────────── --}}
      <amp-accordion class="reply-acc" disable-session-states>
        <section>
          <h4 class="reply-toggle reply-btn{{ $post['type'] === 'Offer' ? '' : ' wanted' }}">Reply</h4>
          <div class="reply-form-container">
            <form method="post" action-xhr="{{ $post['ampReplyUrl'] }}">
              <textarea class="reply-textarea" name="message" placeholder="Type your reply..." required minlength="1" maxlength="10000"></textarea>
              <button type="submit" class="reply-submit">Send Reply</button>
              <div submitting>
                <div class="form-status"><div class="submitting-msg">Sending your reply...</div></div>
              </div>
              <div submit-success template="rsuccess"></div>
              <div submit-error template="rerror"></div>
            </form>
            <p class="reply-fallback"><a href="{{ $post['fallbackReplyUrl'] }}">Or reply on the website</a></p>
          </div>
        </section>
      </amp-accordion>
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
