<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'We think ' . $email1 . ' and ' . $email2 . ' may be the same person - merge them?'])

  <mj-body background-color="#ffffff">
    @include('emails.mjml.partials.header', ['title' => 'Multiple Freegle accounts'])

    <mj-section padding="30px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" line-height="1.6">
          We think you may be using two different accounts on Freegle, perhaps by mistake:
        </mj-text>
        <mj-text font-size="16px" line-height="1.6" padding-top="10px">
          <strong>{{ $name1 }}</strong> ({{ $email1 }})<br/>
          <strong>{{ $name2 }}</strong> ({{ $email2 }})
        </mj-text>
        <mj-text font-size="16px" line-height="1.6" padding-top="10px">
          If you'd like to combine them into a single account, please click the button below.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section padding="10px 20px 30px 20px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $mergeUrl }}" border-radius="4px" font-size="18px" padding="14px 40px">
          Merge my accounts
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section padding="0 20px 20px 20px">
      <mj-column>
        <mj-text font-size="13px" line-height="1.5" color="#666666" align="center">
          If you're happy with separate accounts, you can safely ignore this email.
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

    @include('emails.mjml.partials.footer', ['email' => $email1])
  </mj-body>
</mjml>
