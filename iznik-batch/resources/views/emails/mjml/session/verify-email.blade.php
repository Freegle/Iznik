<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Click to verify ' . $email . ' and complete your Freegle sign-up'])

  <mj-body background-color="#ffffff">
    @include('emails.mjml.partials.header', ['title' => 'Verify your email'])

    <mj-section padding="30px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" line-height="1.6" align="center">
          Someone, probably you, has said that <strong>{{ $email }}</strong> is their email address.
        </mj-text>
        <mj-text font-size="16px" line-height="1.6" align="center" padding-top="10px">
          If this was you, please click the button below to verify the address.
          If this wasn't you, please just ignore this email.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section padding="10px 20px 30px 20px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $confirmUrl }}" border-radius="4px" font-size="18px" padding="14px 40px">
          Verify my email
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section padding="0 20px 20px 20px">
      <mj-column>
        <mj-text font-size="13px" line-height="1.5" color="#666666" align="center">
          If you didn't request this, you can safely ignore this email.
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
