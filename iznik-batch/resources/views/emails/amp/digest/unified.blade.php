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

    /* Post cards */
    .post-card {
      padding: 12px 20px;
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
      padding-left: 12px;
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
      color: #333333;
      text-decoration: none;
    }
    .post-preview {
      font-size: 13px;
      color: #666666;
      margin: 0 0 4px 0;
      line-height: 1.4;
    }
    .post-groups {
      font-size: 11px;
      color: #888888;
      font-style: italic;
      margin: 0 0 8px 0;
    }

    /* Reply accordion */
    amp-accordion section {
      border: none;
    }
    amp-accordion section[expanded] .reply-toggle {
      display: none;
    }
    .reply-toggle {
      display: inline-block;
      background-color: #338808;
      color: #ffffff;
      font-size: 13px;
      font-weight: bold;
      padding: 8px 16px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      list-style: none;
    }
    .reply-form-container {
      padding: 8px 0;
    }
    .reply-textarea {
      width: 100%;
      min-height: 60px;
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
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      margin-top: 8px;
    }
    .reply-fallback {
      font-size: 12px;
      color: #666666;
      margin-top: 6px;
    }
    .reply-fallback a {
      color: #338808;
      text-decoration: none;
    }

    /* Form states */
    .reply-form-container {
      position: relative;
    }
    .reply-form-container[submitting] .reply-textarea,
    .reply-form-container[submitting] .reply-submit,
    .reply-form-container[submit-success] .reply-textarea,
    .reply-form-container[submit-success] .reply-submit {
      opacity: 0.3;
    }
    .form-status {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 90%;
      z-index: 10;
    }
    .submit-success {
      background-color: #e8f5e0;
      border: 1px solid #338808;
      color: #2a6d07;
      padding: 12px;
      text-align: center;
      border-radius: 4px;
    }
    .submit-error {
      background-color: #f2dede;
      border: 1px solid #d9534f;
      color: #a94442;
      padding: 12px;
      text-align: center;
      border-radius: 4px;
    }
    .submitting-msg {
      background-color: #ffffff;
      border: 1px solid #338808;
      color: #333333;
      padding: 12px;
      text-align: center;
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
      <amp-img
        src="{{ config('freegle.branding.logo_url') }}"
        width="120"
        height="40"
        alt="{{ config('freegle.branding.name', 'Freegle') }}"
        layout="fixed"
      ></amp-img>
    </div>

    {{-- Greeting --}}
    <div class="greeting">
      <h2>Hi {{ $user->displayname ?? 'there' }},</h2>
      <p>Here {{ $postCount === 1 ? 'is' : 'are' }} <strong>{{ $postCount }}</strong> new post{{ $postCount === 1 ? '' : 's' }} from your Freegle communities:</p>
    </div>

    {{-- Post cards with reply accordion --}}
    @foreach($posts as $index => $post)
    <div class="post-card">
      @if($post['imageUrl'])
      <amp-img class="post-image" src="{{ $post['imageUrl'] }}" width="80" height="80" layout="fixed" alt="{{ $post['itemName'] }}"></amp-img>
      @else
      <amp-img class="post-image" src="{{ config('freegle.branding.logo_url') }}" width="80" height="80" layout="fixed" alt="No photo"></amp-img>
      @endif
      <div class="post-content">
        <p class="{{ $post['type'] === 'Offer' ? 'post-type-offer' : 'post-type-wanted' }}">{{ $post['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}</p>
        <p class="post-title"><a href="{{ $post['fallbackReplyUrl'] }}">{{ $post['itemName'] }}</a></p>
        @if($post['messageText'])
        <p class="post-preview">{{ \Illuminate\Support\Str::limit($post['messageText'], 100) }}</p>
        @endif
        @if($post['postedToText'])
        <p class="post-groups">{{ $post['postedToText'] }}</p>
        @endif

        {{-- Reply accordion --}}
        <amp-accordion animate>
          <section>
            <h4 class="reply-toggle">Reply</h4>
            <div class="reply-form-container">
              <form method="post" action-xhr="{{ $post['ampReplyUrl'] }}">
                <textarea class="reply-textarea" name="message" placeholder="Type your reply..." required minlength="1" maxlength="10000"></textarea>
                <button type="submit" class="reply-submit">Send Reply</button>
                <div submitting>
                  <div class="form-status">
                    <div class="submitting-msg">Sending your reply...</div>
                  </div>
                </div>
                <div submit-success>
                  <template type="amp-mustache">
                    <div class="form-status">
                      <div class="submit-success">@{{message}}</div>
                    </div>
                  </template>
                </div>
                <div submit-error>
                  <template type="amp-mustache">
                    <div class="form-status">
                      <div class="submit-error">@{{message}} <a href="{{ $post['fallbackReplyUrl'] }}">Reply on Freegle instead</a></div>
                    </div>
                  </template>
                </div>
              </form>
              <div class="reply-fallback">
                <a href="{{ $post['fallbackReplyUrl'] }}">Or reply via website</a>
              </div>
            </div>
          </section>
        </amp-accordion>
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
        Registered address: Weaver's Field, Loud Bridge, Chipping PR3 2NX
      </p>
    </div>
  </div>
</body>
</html>
