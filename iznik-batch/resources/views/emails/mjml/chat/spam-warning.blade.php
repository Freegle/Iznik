<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Be careful - you have been talking to ' . $spammerName . ' about the voucher scam'])
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
          Our checks suggest this is the voucher scam: a well-known trick where someone
          contacts you about an item and then tries to get you to pay using a fake voucher,
          gift card or payment link, so they can steal your money or card details.
        </mj-text>
      </mj-column>
    </mj-section>

    @if(!empty($messageSubject))
    <mj-section background-color="#ffffff" padding="10px 20px">
      <mj-column>
        <mj-text font-size="14px" color="#333333" line-height="1.5">
          This email only goes to you. Your post is mentioned below because it's what
          <strong>{{ $spammerName }}</strong> contacted you about, not because there's
          anything wrong with it.
        </mj-text>
        <mj-text font-size="14px" color="#333333" line-height="1.5" font-weight="bold">
          {{ $messageSubject }}
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    <mj-section background-color="#ffffff" padding="10px 20px">
      <mj-column>
        <mj-text font-size="15px" color="#333333" line-height="1.6">
          <strong>Don't send any money or vouchers</strong>, don't click any payment links
          they send, and don't arrange to receive anything by courier.
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
