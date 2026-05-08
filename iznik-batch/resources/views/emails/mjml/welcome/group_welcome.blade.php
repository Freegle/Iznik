<mjml>
  @include('emails.mjml.partials.head', [
    'preview' => 'Welcome to ' . $groupName
  ])
  <mj-body background-color="#ffffff">

    {{-- Header --}}
    <mj-section mj-class="bg-success" padding="0">
      <mj-column width="65%" vertical-align="middle">
        <mj-text font-size="18px" font-weight="bold" color="#ffffff" padding="10px 25px">
          Welcome to {{ $groupName }}
        </mj-text>
      </mj-column>
      <mj-column width="35%" vertical-align="middle">
        <mj-image
          width="80px"
          src="{{ config('freegle.logo_url', 'https://www.ilovefreegle.org/icon.png') }}"
          alt="Freegle"
          align="right"
          padding="10px 20px"
        />
      </mj-column>
    </mj-section>

    {{-- Welcome message body --}}
    <mj-section background-color="#F7F6EC" padding="20px 0">
      <mj-column>
        <mj-text font-size="13px" color="#000000" line-height="1.6" padding="10px 25px">
          {!! $welcomeContent !!}
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- CTA --}}
    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-button href="{{ $userSite }}" mj-class="btn-success" font-size="14px" padding="12px 25px">
          Freegle something!
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Footer --}}
    @include('emails.mjml.partials.footer', [
      'email' => $email,
      'settingsUrl' => $settingsUrl,
      'unsubscribeUrl' => $unsubscribeUrl,
    ])

  </mj-body>
</mjml>
