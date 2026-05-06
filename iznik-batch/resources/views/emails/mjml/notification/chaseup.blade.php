<mjml>
  @include('emails.mjml.partials.head', [
    'preview' => 'You have ' . $count . ' notification' . ($count === 1 ? '' : 's') . ' on Freegle'
  ])
  <mj-body background-color="#ffffff">

    {{-- Header --}}
    <mj-section mj-class="bg-success" padding="0">
      <mj-column width="65%">
        <mj-text font-size="18px" font-weight="bold" color="#ffffff" padding="10px 25px">
          You have {{ $count }} notification{{ $count === 1 ? '' : 's' }}
        </mj-text>
      </mj-column>
      <mj-column width="35%">
        <mj-image
          width="80px"
          src="{{ config('freegle.logo_url', 'https://www.ilovefreegle.org/icon.png') }}"
          alt="Freegle"
          align="right"
          padding="10px 20px"
        />
      </mj-column>
    </mj-section>

    {{-- Notification rows --}}
    @foreach ($notifications as $notif)
    <mj-section background-color="#F7F6EC" padding="20px 0 0 0">
      <mj-column>

        {{-- Notification text --}}
        @if ($notif['type'] === 'CommentOnCommented')
        <mj-text font-size="13px" padding="10px 25px 2px">
          <strong>{{ $notif['fromname'] }}</strong>
          <span style="color: grey;"> commented on &ldquo;{{ $notif['newsfeed']['replyto']['message'] ?? '' }}&rdquo;:</span>
          <br/><br/><em>{{ $notif['newsfeed']['message'] ?? '' }}</em>
        </mj-text>

        @elseif ($notif['type'] === 'CommentOnYourPost')
        <mj-text font-size="13px" padding="10px 25px 2px">
          <strong>{{ $notif['fromname'] }}</strong>
          <span style="color: grey;"> commented on your post:</span>
          <br/><br/><em>{{ $notif['newsfeed']['message'] ?? '' }}</em>
        </mj-text>

        @elseif ($notif['type'] === 'LovedPost')
        <mj-text font-size="13px" padding="10px 25px 2px">
          <strong>{{ $notif['fromname'] }}</strong>
          @if (($notif['newsfeed']['type'] ?? '') === 'Noticeboard')
          <span style="color: grey;"> loved your noticeboard post</span>
          @else
          <span style="color: grey;"> loved your post:</span>
          <br/><br/><em>{{ $notif['newsfeed']['message'] ?? '' }}</em>
          @endif
        </mj-text>

        @elseif ($notif['type'] === 'LovedComment')
        <mj-text font-size="13px" padding="10px 25px 2px">
          <strong>{{ $notif['fromname'] }}</strong>
          <span style="color: grey;"> loved your comment:</span>
          <br/><br/><em>{{ $notif['newsfeed']['message'] ?? '' }}</em>
        </mj-text>

        @elseif ($notif['type'] === 'Exhort')
        <mj-text font-size="13px" padding="10px 25px 2px">
          <strong>{{ $notif['title'] ?? '' }}</strong>
          @if ($notif['text'])
          <br/><br/><em>{{ $notif['text'] }}</em>
          @endif
        </mj-text>

        @elseif ($notif['type'] === 'MembershipPending')
        <mj-text font-size="13px" padding="10px 25px 2px">
          <strong>Your application to {{ $notif['url'] ?? '' }} requires approval. We&rsquo;ll let you know soon.</strong>
        </mj-text>

        @elseif ($notif['type'] === 'MembershipApproved')
        <mj-text font-size="13px" padding="10px 25px 2px">
          <strong>Your application to {{ $notif['url'] ?? '' }} has been approved!</strong>
        </mj-text>

        @elseif ($notif['type'] === 'MembershipRejected')
        <mj-text font-size="13px" padding="10px 25px 2px">
          <strong>Sorry, your application to {{ $notif['url'] ?? '' }} was rejected</strong>
        </mj-text>
        @endif

        {{-- Timestamp --}}
        <mj-text font-size="11px" color="#707070" padding="0 25px 10px">
          {{ $notif['timestamp'] }}
        </mj-text>

      </mj-column>

      {{-- CTA button --}}
      <mj-column>
        <mj-button
          mj-class="btn-success"
          href="{{ $notif['trackedUrl'] }}"
          align="right"
          padding="10px 25px"
        >
          @if ($notif['type'] === 'Exhort')
          Click here!
          @else
          View thread
          @endif
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section padding="0">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    @endforeach

    {{-- Footer --}}
    @include('emails.mjml.partials.footer', [
      'settingsUrl'    => $settingsUrl,
      'unsubscribeUrl' => $unsubscribeUrl,
      'email' => $email,
    ])

  </mj-body>
</mjml>
