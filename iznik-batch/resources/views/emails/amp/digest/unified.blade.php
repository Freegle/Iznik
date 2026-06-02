<!doctype html>
<html ⚡4email data-css-strict>
<head>
  <meta charset="utf-8">
  <script async src="https://cdn.ampproject.org/v0.js"></script>
  <script async custom-element="amp-form" src="https://cdn.ampproject.org/v0/amp-form-0.1.js"></script>
  <script async custom-element="amp-accordion" src="https://cdn.ampproject.org/v0/amp-accordion-0.1.js"></script>
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

    /* Post card uses CSS Grid so the photo and content columns share the
       same height — the image grid cell auto-stretches to the content
       cell's height, and the amp-img inside it (layout="fill") fills
       that height. End result: the photo's bottom edge always lines up
       with the bottom of the content (Reply accordion included), no
       dangle and no whitespace gap. */
    .post-card {
      padding: 12px 16px;
      border-bottom: 1px solid #eeeeee;
      display: grid;
      grid-template-columns: 200px 1fr;
      gap: 14px;
    }
    .post-img-wrap {
      position: relative; /* required so amp-img layout="fill" fills it */
      min-height: 200px;
      border-radius: 4px;
      overflow: hidden;
    }
    /* AMP4Email disallows the object-fit attribute on <amp-img>; do it
       via CSS on the inner <img> the amp-img runtime renders. */
    .post-img-wrap amp-img img { object-fit: cover; }
    .post-content {
      min-width: 0; /* allow content to shrink */
    }
    /* OFFER / WANTED pill — matches the MJML chip shape (white text on
       a green or blue background pill) instead of plain coloured text,
       so the AMP and HTML versions look the same at a glance. */
    .post-type-offer, .post-type-wanted {
      display: inline-block;
      font-size: 12px;
      font-weight: 700;
      color: #ffffff;
      padding: 2px 9px;
      border-radius: 3px;
      margin: 0 0 6px 0;
      letter-spacing: 0.3px;
    }
    .post-type-offer { background-color: #338808; }
    .post-type-wanted { background-color: #00A1CB; }
    .post-type-row { margin: 0 0 6px 0; }
    .post-title {
      font-size: 16px;
      font-weight: 600;
      margin: 0 0 4px 0;
    }
    .post-title a {
      color: #212529;
      text-decoration: none;
    }
    /* User-supplied description: 14px / #333 / regular so it reads as
       content but stays secondary to the title. Clamped to two lines
       via max-height + overflow hidden — AMP4Email's CSS-strict mode
       disallows the -webkit-line-clamp shorthand, so we approximate
       it with two-line max-height (font-size:14px × line-height:1.5
       × 2 lines = 42px). The PHP-side Str::limit truncates the input
       string so a runaway description can't blow past the clamp. */
    .post-preview {
      font-size: 14px;
      color: #333333;
      margin: 0 0 6px 0;
      line-height: 1.5;
      max-height: 42px;
      overflow: hidden;
    }
    /* One-line metadata row: location · distance · time. The three pieces
       sit on the same line separated by middle-dots so they read as one
       coherent strip instead of three stacked dimming lines that the
       eye had to triage. */
    .post-meta {
      font-size: 12px;
      color: #888888;
      margin: 6px 0 0 0;
    }
    .post-meta .sep {
      margin: 0 6px;
      color: #cccccc;
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
    .post-byline amp-img {
      border-radius: 50%;
      margin-right: 8px;
      vertical-align: middle;
    }
    .post-byline strong {
      color: #555555;
    }
    .post-byline .group-link {
      color: #555555;
      text-decoration: underline;
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

    /* Per-post Reply accordion. Lives inside the right grid cell, anchored
       to the bottom of the content column via margin-top:auto on a flex
       column — so the Reply trigger sits flush with the photo's bottom
       edge no matter how short the description is. */
    .post-content { display: flex; flex-direction: column; }
    .reply-acc {
      margin-top: auto; /* push to the bottom of the content column */
      padding-top: 12px;
    }
    .reply-acc section { border: none; }
    /* The accordion's <h4> heading IS the visible Reply trigger, but
       amp-runtime forces display:block !important on every direct child
       of <section> — which would stretch the h4 across the whole column
       and we can't beat !important without using !important ourselves
       (AMP4Email's CSS-strict mode disallows that on user CSS).
       Workaround: keep the h4 transparent and put the visible button
       styling on an inner .reply-chip span. The span is a *grandchild*
       of section so the runtime's display:block !important doesn't reach
       it — inline-block hugs its text + padding as intended. */
    /* Need 0,1,3 specificity (one class + three elements) to beat the
       runtime's `:where(amp-accordion > section) > :first-child` rule
       (0,1,0 — :where contributes nothing) which paints a 1px #dfdfdf
       border and #efefef background across the entire heading. Without
       this the chip sits inside a faint full-width box. */
    amp-accordion > section > h4.reply-toggle {
      margin: 0;
      padding: 0;
      list-style: none;
      cursor: pointer;
      background: transparent;
      border: 0;
      font-weight: normal;
    }
    .reply-chip {
      display: inline-block;
      background-color: #338808;
      color: #ffffff;
      padding: 9px 26px;
      border-radius: 4px;
      font-size: 13px;
      font-weight: 700;
      text-align: center;
    }
    .reply-chip.wanted { background-color: #00A1CB; }
    /* Hide the Reply chip once the user opens the accordion. We can hide
       the span (display: none works on grandchildren), and collapse the
       h4 to zero height so the form below sits where the chip was. The
       h4 itself can't be display:none because the runtime force-applied
       display:block!important, but height:0 + overflow:hidden + zero
       margin/padding visually collapses it to nothing. */
    amp-accordion > section[expanded] > h4.reply-toggle > .reply-chip {
      display: none;
    }
    amp-accordion > section[expanded] > h4.reply-toggle {
      height: 0;
      margin: 0;
      padding: 0;
      overflow: hidden;
    }
    .reply-form-container { padding: 12px 0 0 0; }
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

    /* Job listings block (V1 single.html parity — mirrors the MJML jobs block) */
    .jobs-section {
      background-color: #F7F6EC;
      padding: 16px 20px;
      border-top: 1px solid #e9ecef;
    }
    .jobs-title {
      font-size: 16px;
      font-weight: bold;
      color: #333333;
      text-align: center;
      margin: 0 0 10px 0;
    }
    .job-row {
      margin: 0 0 8px 0;
    }
    .job-thumb {
      width: 40px;
      height: 40px;
      border-radius: 4px;
      margin-right: 8px;
      vertical-align: middle;
    }
    .job-link {
      color: #338808;
      font-weight: bold;
      text-decoration: none;
      font-size: 14px;
      line-height: 1.25;
    }
    .job-location {
      color: #666666;
      font-size: 12px;
      line-height: 1.3;
    }
    .jobs-note {
      font-size: 12px;
      color: #666666;
      line-height: 1.4;
      margin: 8px 0 0 0;
    }
    .jobs-buttons {
      margin-top: 12px;
      text-align: center;
    }
    .jobs-button {
      display: inline-block;
      background-color: #338808;
      color: #ffffff;
      text-decoration: none;
      font-size: 14px;
      padding: 10px 25px;
      border-radius: 5px;
      margin: 4px;
    }

    /* Sponsors (V1 parity — mirrors the MJML sponsors block) */
    .sponsors-section {
      background-color: #ffffff;
      padding: 10px 20px;
    }
    .sponsors-divider {
      border-top: 1px solid #eeeeee;
      margin: 0 0 5px 0;
    }
    .sponsors-label {
      font-size: 12px;
      color: #888888;
      font-style: italic;
      margin: 0 0 8px 0;
    }
    .sponsor-row {
      margin: 0 0 10px 0;
    }
    .sponsor-thumb {
      width: 60px;
      height: 60px;
      border-radius: 5px;
      margin-right: 10px;
      vertical-align: middle;
    }
    .sponsor-name {
      color: #338808;
      text-decoration: none;
      font-weight: bold;
      font-size: 13px;
    }
    .sponsor-tagline {
      font-size: 11px;
      color: #666666;
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
      <p>Here are <strong>{{ $postCount }}</strong> new posts from your Freegle communities.</p>
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
      {{-- Multi-post thumbnail. The grid above gives the image cell a
           non-relative computed height (matches the content cell), and
           amp-img layout="fill" + object-fit:cover stretches the photo
           to fill that cell — so the photo's bottom edge always lines
           up with the bottom of the content column. --}}
      <div class="post-img-wrap">
        <amp-img layout="fill" src="{{ $post['thumbImageUrl'] }}" alt="{{ $post['itemName'] }}"></amp-img>
      </div>
      <div class="post-content">
    @endif
        {{-- OFFER / WANTED pill. Wrapped in a <p> so it gets its own
             line + bottom margin, but the visible chip is a <span> —
             p with `display: inline-block` stretched full-width in the
             AMP4Email environment (something in the runtime/spec
             cascade pinned p to block), span with display: inline-block
             reliably hugs its text. --}}
        <p class="post-type-row"><span class="{{ $post['type'] === 'Offer' ? 'post-type-offer' : 'post-type-wanted' }}">{{ $post['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}</span></p>
        <p class="post-title"><a href="{{ $post['fallbackReplyUrl'] }}">{{ $post['itemName'] }}</a></p>
        @if($post['messageText'])
        {{-- User-supplied description. Immediate renders the full body
             (it's the only post); multi-post truncates for AMP size. --}}
        <p class="post-preview">{!! nl2br(e($isSingle ? $post['messageText'] : \Illuminate\Support\Str::limit($post['messageText'], 120))) !!}</p>
        @endif
        {{-- Location · distance · time as one clean strip — the previous
             three stacked lines (post-loc, post-time, post-byline) read
             as a messy column of dimming text and the location-pin emoji
             sat directly above the avatar. One line with middle-dot
             separators reads as a single coherent metadata footer. --}}
        <p class="post-meta">
          @if($post['locationName'] ?? null)<span>{{ $post['locationName'] }}</span>@endif
          @if($post['distanceText'])@if($post['locationName'] ?? null)<span class="sep">·</span>@endif<span>&#x1F4CD; {{ $post['distanceText'] }}</span>@endif
          <span class="sep">·</span><span>&#x1F552; {{ $post['arrivalFormatted'] }}</span>
        </p>
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
        @if($isSingle)
        {{-- Secondary actions on the immediate hero (V1 single.html parity). --}}
        <p class="post-actions" style="margin-top: 12px;">
          <a href="{{ $browseUrl }}">Browse other posts</a>
          <a href="{{ $userSite }}/offer">Post something</a>
        </p>
        @endif
        {{-- Per-post reply form lives INSIDE .post-content (the right
             grid cell) so the card's overall height = image + content +
             accordion stack. The grid then auto-stretches the image cell
             to that same height, which is what makes the photo bottom
             edge align with the bottom of the Reply accordion / form. --}}
        <amp-accordion class="reply-acc">
          <section>
            <h4 class="reply-toggle"><span class="reply-chip{{ $post['type'] === 'Offer' ? '' : ' wanted' }}">Reply</span></h4>
            <div class="reply-form-container">
              <form method="post" action-xhr="{{ $post['ampReplyUrl'] }}">
                <textarea class="reply-textarea" name="message" placeholder="Reply..." required></textarea>
                <button type="submit" class="reply-submit">Send</button>
                <div submitting><div class="form-status"><div class="submitting-msg">Sending…</div></div></div>
                <div submit-success template="rsuccess"></div>
                <div submit-error template="rerror"></div>
              </form>
              <p class="reply-fallback"><a href="{{ $post['fallbackReplyUrl'] }}">Or reply on the website</a></p>
            </div>
          </section>
        </amp-accordion>
      </div>
    </div>
    @endforeach

    {{-- Jobs near you (V1 single.html parity — mirrors the MJML jobs block).
         AMP4Email needs concrete amp-img dimensions, so the thumb is a fixed
         40x40. --}}
    @if(isset($jobAds) && $jobAds->isNotEmpty())
    <div class="jobs-section">
      <p class="jobs-title">Jobs near you</p>
      @foreach($jobAds as $job)
      <div class="job-row">
        @if($job->image_url ?? null)
        <a href="{{ $job->tracked_url }}">
          <amp-img class="job-thumb" src="{{ $job->image_url }}" width="40" height="40" layout="fixed" alt=""></amp-img>
        </a>
        @endif
        <a href="{{ $job->tracked_url }}" class="job-link">{{ $job->title }}</a>
        @if($job->location ?? null)
        <br /><span class="job-location">{{ $job->location }}</span>
        @endif
      </div>
      @endforeach
      <p class="jobs-note">If you are interested and click, it will raise a little to help keep Freegle running and free to use.</p>
      <div class="jobs-buttons">
        <a href="{{ $jobsUrl }}" class="jobs-button">View more jobs</a>
        <a href="{{ $donateUrl }}" class="jobs-button">Donating helps too!</a>
      </div>
    </div>
    @endif

    {{-- Sponsors (V1 parity — mirrors the MJML sponsors block) --}}
    @if(isset($sponsors) && $sponsors->isNotEmpty())
    <div class="sponsors-section">
      <div class="sponsors-divider"></div>
      <p class="sponsors-label">Sponsored by:</p>
      @foreach($sponsors as $sponsor)
      <div class="sponsor-row">
        @if($sponsor->imageurl)
        <a href="{{ $sponsor->linkurl }}">
          <amp-img class="sponsor-thumb" src="{{ $sponsor->imageurl }}" width="60" height="60" layout="fixed" alt="{{ $sponsor->name }}"></amp-img>
        </a>
        @endif
        @if($sponsor->linkurl)<a href="{{ $sponsor->linkurl }}" class="sponsor-name">{{ $sponsor->name }}</a>@else<strong class="sponsor-name">{{ $sponsor->name }}</strong>@endif
        @if($sponsor->tagline)<br /><span class="sponsor-tagline">{{ $sponsor->tagline }}</span>@endif
      </div>
      @endforeach
    </div>
    @endif

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
