<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'You have unread messages waiting'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.partials.header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold">
          You have unread messages
        </mj-text>
        <mj-text>
          Hi {{ $recipientName }},
        </mj-text>
        <mj-text>
          @if ($provider)
            {{ $provider }} stopped accepting our emails on {{ $delayedSince }}, so we held off sending you
            notifications rather than fill your inbox with messages that would not have arrived.
          @else
            Your email provider stopped accepting our emails on {{ $delayedSince }}, so we held off sending you
            notifications rather than fill your inbox with messages that would not have arrived.
          @endif
          That is now fixed.
        </mj-text>
        <mj-text>
          @if ($chatCount === 1)
            While it was going on you had
            {{ $messageCount === 1 ? 'a message' : $messageCount . ' messages' }}
            in one chat.
          @else
            While it was going on you had
            {{ $messageCount === 1 ? 'a message' : $messageCount . ' messages' }}
            across {{ $chatCount }} chats.
          @endif
          We are sending this one email rather than all of them.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        <mj-button href="{{ $chatsUrl }}" border-radius="3px" font-size="16px">
          Read your messages
        </mj-button>
        <mj-text font-size="13px" color="#666666">
          Sorry about the gap. It was our problem, not yours, and nothing you sent was lost.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email, 'settingsUrl' => $settingsUrl])

  </mj-body>
</mjml>
