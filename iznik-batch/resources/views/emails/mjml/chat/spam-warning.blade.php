<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'A warning from ' . $siteName . ' about ' . $spammerName])
  <mj-body background-color="#ffffff">
    {{-- Header --}}
    <mj-section mj-class="bg-freegle" padding="20px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" color="#ffffff" align="center">
          A warning from {{ $siteName }}
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Be careful --}}
    <mj-section background-color="#ffffff" padding="25px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          Hi there,
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#fff3cd" padding="15px 20px">
      <mj-column>
        <mj-text font-size="18px" font-weight="bold" color="#856404">
          Be careful!
        </mj-text>
        <mj-text font-size="15px" color="#333333" line-height="1.6">
          You've been talking to <strong>{{ $spammerName }}</strong>.
          Our checks suggest that this person might be a scammer or spammer.
        </mj-text>
      </mj-column>
    </mj-section>

    @if(!empty($messageSubject))
    <mj-section background-color="#ffffff" padding="10px 20px">
      <mj-column>
        <mj-text font-size="14px" color="#333333" line-height="1.5">
          We think you were talking about: <strong>{{ $messageSubject }}</strong>
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    <mj-section background-color="#ffffff" padding="10px 20px">
      <mj-column>
        <mj-text font-size="15px" color="#333333" line-height="1.6">
          <strong>Don't give them any money</strong>, no matter how tempting it might be,
          and don't arrange to receive anything by courier.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="10px 20px 25px 20px">
      <mj-column>
        <mj-text font-size="15px" color="#333333" line-height="1.6">
          This is an automated email, but if you reply it'll go to your local community
          volunteers. We all hate scammers, and we try to keep you safe.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Footer --}}
    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
          {{ $siteName }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
          Registered address: {{ config('freegle.branding.registered_address') }}
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
