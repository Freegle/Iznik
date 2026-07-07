<!doctype html>
<html ⚡4email data-css-strict>
<head>
  <meta charset="utf-8">
  <script async src="https://cdn.ampproject.org/v0.js"></script>
  <script async custom-element="amp-list" src="https://cdn.ampproject.org/v0/amp-list-0.1.js"></script>
  <script async custom-element="amp-form" src="https://cdn.ampproject.org/v0/amp-form-0.1.js"></script>
  <script async custom-template="amp-mustache" src="https://cdn.ampproject.org/v0/amp-mustache-0.2.js"></script>
  <style amp4email-boilerplate>body{visibility:hidden}</style>
  <style amp-custom>
    /* Base styles matching Freegle website */
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
      padding: 20px;
      text-align: center;
    }
    .header h1 {
      color: #ffffff;
      font-size: 24px;
      font-weight: bold;
      margin: 0;
    }

    /* Message bubbles - matching website chat styling */
    .message {
      padding: 12px 20px;
      display: flex;
      align-items: flex-start;
    }
    .message-mine {
      flex-direction: row-reverse;
    }
    .message-avatar {
      width: 36px;
      height: 36px;
      border-radius: 18px;
      flex-shrink: 0;
    }
    .message-content {
      max-width: 80%;
      padding: 0 12px;
    }
    .message-mine .message-content {
      text-align: right;
    }
    .message-sender {
      font-size: 14px;
      font-weight: 600;
      margin-bottom: 4px;
    }
    .message-mine .message-sender {
      color: #338808;
    }
    .message-text {
      font-size: 16px;
      line-height: 1.5;
      color: #333333;
    }
    .message-date {
      font-size: 12px;
      color: #888888;
      margin-top: 4px;
    }

    /* New message indicator */
    .new-badge {
      display: inline-block;
      background-color: #d9534f;
      color: #ffffff;
      font-size: 10px;
      font-weight: bold;
      padding: 2px 6px;
      border-radius: 10px;
      margin-left: 8px;
      text-transform: uppercase;
    }

    /* Section headers */
    .section-header {
      background-color: #f8f9fa;
      padding: 15px 20px 10px;
    }
    .section-title {
      font-size: 12px;
      font-weight: bold;
      color: #338808;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin: 0;
    }
    .section-title-gray {
      color: #666666;
    }

    /* Reply form */
    .reply-section {
      background-color: #f8f9fa;
      padding: 20px;
      border-top: 1px solid #e9ecef;
    }
    .reply-form {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .reply-textarea {
      width: 100%;
      min-height: 80px;
      padding: 12px;
      border: 1px solid #ced4da;
      border-radius: 4px;
      font-size: 16px;
      font-family: inherit;
      resize: vertical;
      box-sizing: border-box;
    }
    .reply-textarea:focus {
      outline: none;
      border-color: #338808;
      box-shadow: 0 0 0 2px rgba(51, 136, 8, 0.25);
    }
    .reply-button {
      background-color: #338808;
      color: #ffffff;
      font-size: 16px;
      font-weight: bold;
      padding: 14px 24px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    .reply-button:hover {
      background-color: #2a6d07;
    }

    /* Form states - overlay to prevent layout shift */
    .reply-form {
      position: relative;
    }
    .reply-form[submitting] .reply-textarea,
    .reply-form[submitting] .reply-button,
    .reply-form[submit-success] .reply-textarea,
    .reply-form[submit-success] .reply-button,
    .reply-form[submit-error] .reply-textarea,
    .reply-form[submit-error] .reply-button {
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
      padding: 15px 20px;
      text-align: center;
      border-radius: 4px;
    }
    .submit-error {
      background-color: #f2dede;
      border: 1px solid #d9534f;
      color: #a94442;
      padding: 15px 20px;
      text-align: center;
      border-radius: 4px;
    }
    .submitting-msg {
      background-color: #fff;
      border: 1px solid #338808;
      color: #333;
      padding: 15px 20px;
      text-align: center;
      border-radius: 4px;
    }

    /* Reply alternative */
    .reply-alternative {
      text-align: center;
      padding-top: 12px;
    }
    .reply-website-link {
      color: #666666;
      font-size: 14px;
      text-decoration: none;
    }
    .reply-website-link:hover {
      color: #338808;
      text-decoration: underline;
    }

    /* Earlier conversation */
    .earlier-section {
      background-color: #f8f9fa;
    }
    .earlier-message {
      padding: 10px 20px;
      display: flex;
      align-items: flex-start;
    }
    .earlier-avatar {
      width: 28px;
      height: 28px;
      border-radius: 14px;
      flex-shrink: 0;
    }
    .earlier-content {
      padding-left: 10px;
    }
    .earlier-sender {
      font-size: 13px;
      font-weight: 600;
      color: #555555;
    }
    .earlier-date {
      font-size: 12px;
      color: #888888;
    }
    .earlier-text {
      font-size: 14px;
      color: #666666;
      margin-top: 4px;
      line-height: 1.4;
    }

    /* Fallback link */
    .fallback-section {
      padding: 20px;
      text-align: center;
      background-color: #f8f9fa;
    }
    .fallback-link {
      color: #338808;
      text-decoration: none;
      font-weight: 600;
    }

    /* Loading state */
    .loading {
      padding: 20px;
      text-align: center;
      color: #888888;
    }

    /* Divider */
    .divider {
      border-top: 1px solid #e9ecef;
      margin: 0;
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
      <h1>New message from {{ $senderName }}</h1>
    </div>

    {{-- New message section header --}}
    <div class="section-header">
      <p class="section-title">New message</p>
    </div>

    {{-- The triggering message (static) --}}
    <div class="message {{ $chatMessage['isFromRecipient'] ? 'message-mine' : '' }}">
      <amp-img class="message-avatar" src="{{ $chatMessage['profileUrl'] }}" alt="{{ $chatMessage['userName'] }}" width="36" height="36" layout="fixed"></amp-img>
      <div class="message-content">
        <div class="message-sender">
          {{ $chatMessage['userName'] }}{{ $chatMessage['isFromRecipient'] ? ' (you)' : '' }}
        </div>
        <div class="message-text">{!! nl2br(e($chatMessage['text'])) !!}</div>
        <div class="message-date">{{ $chatMessage['formattedDate'] }}</div>
      </div>
    </div>

    <hr class="divider">

    {{-- Reply form (AMP) --}}
    <div class="reply-section">
      <form class="reply-form"
            method="post"
            action-xhr="{{ $ampReplyUrl }}">
        <textarea class="reply-textarea"
                  name="message"
                  placeholder="Type your reply..."
                  required
                  minlength="1"
                  maxlength="10000"></textarea>
        <button type="submit" class="reply-button">
          Send Reply
        </button>
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
              <div class="submit-error">@{{message}} <a href="{{ $chatUrl }}" class="fallback-link">Reply on Freegle instead</a></div>
            </div>
          </template>
        </div>
      </form>
      <div class="reply-alternative">
        <a href="{{ $chatUrl }}" class="reply-website-link">Or reply via website →</a>
      </div>
    </div>

    {{-- Earlier conversation (dynamic via amp-list) --}}
    <div class="earlier-section">
      <div class="section-header">
        <p class="section-title section-title-gray">Earlier in this conversation</p>
      </div>

      <amp-list
        src="{{ $ampChatUrl }}"
        width="auto"
        height="300"
        layout="fixed-height"
        items="items">
        <template type="amp-mustache">
          <div class="earlier-message">
            <amp-img class="earlier-avatar" src="@{{fromImage}}" alt="@{{fromUser}}" width="28" height="28" layout="fixed"></amp-img>
            <div class="earlier-content">
              <span class="earlier-sender">@{{fromUser}}</span>
              @{{#isNew}}<span class="new-badge">New</span>@{{/isNew}}
              <div class="earlier-text">@{{message}}</div>
            </div>
          </div>
        </template>
        <div placeholder>
          <p class="loading">Loading earlier messages...</p>
        </div>
        <div fallback>
          <div class="fallback-section">
            <a href="{{ $chatUrl }}" class="fallback-link">View conversation on Freegle</a>
          </div>
        </div>
      </amp-list>
    </div>

    {{-- Fallback button --}}
    <div class="fallback-section">
      <a href="{{ $chatUrl }}" class="fallback-link">View full conversation on Freegle →</a>
    </div>

    {{-- Footer --}}
    <div class="footer">
      <p class="footer-text">This email was sent with AMP to {{ $recipient->email_preferred }}</p>
      <p class="footer-links">
        <a href="{{ $userSite }}/settings">Change your email settings</a> &bull;
        <a href="{{ $userSite }}/unsubscribe">Unsubscribe</a>
      </p>
      <div class="footer-divider"></div>
      <p class="footer-charity">
        {{ $siteName }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br>
        Registered address: {{ config('freegle.branding.registered_address') }}
      </p>
    </div>
  </div>
</body>
</html>
