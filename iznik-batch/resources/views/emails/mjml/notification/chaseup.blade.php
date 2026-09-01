<mjml>
  @include('emails.mjml.partials.head', [
    'preview' => (function() use ($notifications, $count) {
        // Preheader: what the first (newest) notification actually says, so the
        // inbox line is useful rather than repeating the subject.
        $first = 'You have a notification';

        if (!empty($notifications)) {
            $n = $notifications[0];
            $from = $n['fromname'] ?? 'Someone';
            $msg = \Illuminate\Support\Str::limit(strip_tags($n['newsfeed']['message'] ?? ''), 40);

            $first = match ($n['type'] ?? '') {
                'CommentOnCommented' => $from . ' replied: ' . $msg,
                'CommentOnYourPost' => $from . ' commented: ' . $msg,
                'LovedPost' => $from . ' loved your post' . ($msg ? ': ' . $msg : ''),
                'LovedComment' => $from . ' loved your comment' . ($msg ? ': ' . $msg : ''),
                'Exhort' => $n['title'] ?? $first,
                default => 'You have a notification from ' . $from,
            };
        }

        return \Illuminate\Support\Str::limit($first, 80) . ($count > 1 ? ' (and ' . ($count - 1) . ' more)' : '');
    })()
  ])

  <mj-body background-color="#f0f0eb">

    @include('emails.mjml.partials.header', ['title' => config('freegle.branding.name')])

    {{-- Intro card --}}
    <mj-section mj-class="bg-green-light" padding="24px 0 20px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" mj-class="text-header" line-height="1.3" padding="0 25px 12px">
          You have {{ $count }} notification{{ $count === 1 ? '' : 's' }}
        </mj-text>
        <mj-text font-size="15px" color="#333333" line-height="1.7" padding="0 25px 0">
          Here&rsquo;s what happened on Freegle while you were away.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($notifications as $notif)

    @php
      // Wording for this card. Two shapes: an actor line ("Alice commented on
      // your post") for the social notifications, and a plain statement for the
      // ones Freegle itself raises. Worked out here so the layout below stays
      // flat instead of repeating the card markup per type.
      $type = $notif['type'] ?? '';
      $from = $notif['fromname'] ?? 'Someone';
      $feed = $notif['newsfeed'] ?? null;
      $group = $notif['url'] ?? '';
      $actor = null;
      $quote = null;
      $cta = 'View thread';

      if ($type === 'CommentOnCommented') {
          $actor = $from;
          $statement = 'replied on &ldquo;' . e($feed['replyto']['message'] ?? 'your thread') . '&rdquo;';
          $quote = $feed['message'] ?? null;
      } elseif ($type === 'CommentOnYourPost') {
          $actor = $from;
          $statement = 'commented on your post';
          $quote = $feed['message'] ?? null;
      } elseif ($type === 'LovedPost') {
          $actor = $from;

          if (($feed['type'] ?? '') === 'Noticeboard') {
              // Noticeboard loves carry no text to quote.
              $statement = 'loved your noticeboard post';
          } else {
              $statement = 'loved your post';
              $quote = $feed['message'] ?? null;
          }
      } elseif ($type === 'LovedComment') {
          $actor = $from;
          $statement = 'loved your comment';
          $quote = $feed['message'] ?? null;
      } elseif ($type === 'Exhort') {
          $statement = '<strong>' . e($notif['title'] ?? 'You have a notification') . '</strong>';
          $quote = $notif['text'] ?? null;
          $cta = 'Take a look';
      } elseif ($type === 'MembershipPending') {
          $statement = 'Your application to ' . e($group) . ' needs approval. We&rsquo;ll let you know as soon as we hear.';
          $cta = 'Go to Freegle';
      } elseif ($type === 'MembershipApproved') {
          $statement = 'You&rsquo;re in! Your application to ' . e($group) . ' has been approved.';
          $cta = 'Go to Freegle';
      } elseif ($type === 'MembershipRejected') {
          $statement = 'Sorry, your application to ' . e($group) . ' wasn&rsquo;t approved.';
          $cta = 'Go to Freegle';
      } else {
          // Anything we have no wording for yet still gets a usable card rather
          // than an empty one.
          $statement = 'You have a notification from ' . e($from);
          $cta = 'Go to Freegle';
      }
    @endphp

    {{-- Gap before each card --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="6px 0" color="#f0f0eb">&nbsp;</mj-text></mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="18px 0 16px">
      <mj-column width="70px" vertical-align="top">
        @if (!empty($notif['fromimage']))
        <mj-image src="{{ $notif['fromimage'] }}" alt="" width="44px" height="44px"
                  border-radius="50%" align="left" padding="0 0 0 25px" />
        @endif
      </mj-column>
      <mj-column width="530px" vertical-align="top">
        <mj-text font-size="16px" color="#333333" line-height="1.45" padding="0 25px 2px 0">
          @if ($actor)<strong>{{ $actor }}</strong> @endif{!! $statement !!}
        </mj-text>
        <mj-text font-size="12px" color="#999999" line-height="1.4" padding="0 25px 0 0">
          {{ $notif['timestamp'] ?? '' }}
        </mj-text>
        @if (!empty($quote))
        <mj-text font-size="15px" color="#555555" line-height="1.65" padding="10px 25px 0 0">
          <span style="display:block; border-left:3px solid #cde3b4; padding-left:12px; font-style:italic;">{{ $quote }}</span>
        </mj-text>
        @endif
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px 0 0">
          <a href="{{ $notif['trackedUrl'] }}">{{ $cta }} &rarr;</a>
        </mj-text>
      </mj-column>
    </mj-section>

    @endforeach

    {{-- Closing CTA --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="6px 0" color="#f0f0eb">&nbsp;</mj-text></mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="22px 0">
      <mj-column>
        <mj-text font-size="14px" color="#555555" line-height="1.7" padding="0 25px 16px" align="center">
          Replies, loves and nudges all live on Freegle. Pop in to catch up on the lot.
        </mj-text>
        <mj-button href="{{ $chitchatUrl }}" mj-class="btn-success" font-size="14px" border-radius="4px" align="center">
          See what&rsquo;s happening
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', [
      'settingsUrl' => $settingsUrl,
      'unsubscribeUrl' => $unsubscribeUrl,
      'email' => $email
    ])

  </mj-body>
</mjml>
