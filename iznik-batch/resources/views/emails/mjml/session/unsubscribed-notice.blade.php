<mjml>
  @include('emails.mjml.partials.head', [
    'preview' => $alreadyOff
        ? 'Those emails were already off, so there was nothing to change.'
        : "We've turned those emails off. Here's what may still reach you.",
  ])

  <mj-body background-color="#ffffff">
    {{-- Headline and logo side by side on the brand green, as the notification mails do. --}}
    <mj-section mj-class="bg-success" padding="20px 0">
      <mj-column width="65%" vertical-align="middle">
        <mj-text font-size="22px" font-weight="bold" color="#ffffff" padding="0 0 0 25px">
          {{ $alreadyOff ? 'Already off' : "That's done" }}
        </mj-text>
      </mj-column>
      <mj-column width="35%" vertical-align="middle">
        <mj-image
          width="80px"
          src="{{ config('freegle.branding.logo_url') }}"
          alt="{{ config('freegle.branding.name') }}"
          align="right"
          padding="0 25px 0 0"
        />
      </mj-column>
    </mj-section>

    <mj-section padding="20px 0 0 0">
      <mj-column>
        <mj-text font-size="16px" line-height="1.5" padding="10px 25px">
          Hi {{ $recipientName ?? 'there' }},
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- What we turned off. --}}
    <mj-section padding="0">
      <mj-column>
        @if($alreadyOff)
          <mj-text font-size="14px" line-height="1.5" padding="0 25px 10px 25px">
            <p>Thanks - you asked us to stop sending you {{ $whatTheyAskedFor }}. Those were already switched off, so there was nothing to change.</p>
          </mj-text>
        @else
          <mj-text font-size="14px" line-height="1.5" padding="0 25px 5px 25px">
            <p>Thanks. We've turned off:</p>
          </mj-text>
        @endif
      </mj-column>
    </mj-section>

    @if(!$alreadyOff)
    <mj-section mj-class="bg-green-light" padding="0 25px">
      <mj-column>
        <mj-text font-size="14px" line-height="1.6" mj-class="text-header" padding="15px 20px">
          <ul style="margin: 0; padding-left: 1.2em;">
            @foreach($turnedOff as $item)
              <li>{{ $item }}</li>
            @endforeach
          </ul>
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- What may still arrive. Saying this plainly is the point of the email: the common
         complaint is unsubscribing from one thing and being surprised by the rest. --}}
    <mj-section padding="20px 0 0 0">
      <mj-column>
        @if($everythingAlreadyOff)
          <mj-text font-size="14px" line-height="1.5" padding="0 25px 10px 25px">
            <p>That's everything switched off. We'll only email you if you ask us to, or about something essential like resetting your password.</p>
          </mj-text>
        @else
          <mj-text font-size="14px" line-height="1.5" padding="0 25px 5px 25px">
            <p>You may still get these from us:</p>
          </mj-text>
        @endif
      </mj-column>
    </mj-section>

    @if(!$everythingAlreadyOff)
    <mj-section mj-class="bg-light" padding="0 25px">
      <mj-column>
        <mj-text font-size="14px" line-height="1.6" color="#555555" padding="15px 20px">
          <ul style="margin: 0; padding-left: 1.2em;">
            @foreach($stillOn as $item)
              <li>{{ $item }}</li>
            @endforeach
          </ul>
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- One tap to stop the lot, for the member who meant "stop emailing me" rather than
         "stop this one kind". Needs no login. --}}
    <mj-section padding="25px 0 0 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="0 25px 10px 25px">
          <p>Want to stop all of it? One tap, no need to log in:</p>
        </mj-text>
        <mj-button mj-class="btn-dark" href="{{ $stopAllUrl }}" border-radius="3px" font-size="14px" padding="0 25px 10px 25px">
          Stop all Freegle email
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    {{-- Or pick and choose. --}}
    <mj-section padding="20px 0 10px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="0 25px 10px 25px">
          <p>You can turn any of these back on, or change them one by one, in your settings.</p>
        </mj-text>
        <mj-button mj-class="btn-success" href="{{ $settingsUrl }}" border-radius="3px" font-size="14px" padding="0 25px 10px 25px">
          Change your email settings
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section padding="0 0 10px 0">
      <mj-column>
        <mj-text font-size="13px" color="#888888" align="center" line-height="1.5" padding="0 25px">
          There's no need to reply to this email - nobody reads that mailbox.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $recipientEmail, 'settingsUrl' => $settingsUrl, 'unsubscribeUrl' => $unsubscribeUrl])

    @if(!empty($trackingPixelHtml))
    <mj-section padding="0">
      <mj-column>
        <mj-text padding="0">{!! $trackingPixelHtml !!}</mj-text>
      </mj-column>
    </mj-section>
    @endif
  </mj-body>
</mjml>
