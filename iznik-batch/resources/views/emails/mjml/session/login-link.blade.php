<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Your sign-in link for '.$siteName])

  <mj-body background-color="#ffffff">
    @include('emails.mjml.partials.header', ['title' => 'Sign in to '.$siteName])

    <mj-section padding="30px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" line-height="1.6" align="center">
          Click the button below and you'll be signed in straight away - no password needed.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section padding="10px 20px 30px 20px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $loginUrl }}" border-radius="4px" font-size="18px" padding="14px 40px">
          Sign in
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section padding="0 20px 20px 20px">
      <mj-column>
        <mj-text font-size="13px" line-height="1.5" color="#666666" align="center">
          If you didn't ask for this, you can safely ignore this email.
        </mj-text>
      </mj-column>
    </mj-section>

    @if(!empty($trackingPixelMjml))
    <mj-section padding="0">
      <mj-column>
        {!! $trackingPixelMjml !!}
      </mj-column>
    </mj-section>
    @endif

    @include('emails.mjml.partials.footer', ['email' => $email])
  </mj-body>
</mjml>
