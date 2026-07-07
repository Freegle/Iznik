<mjml>
  @include('emails.mjml.partials.head', ['preview' => "We've turned off your Freegle emails"])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => "We've turned off emails for you"])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>You've marked a mail from {{ $siteName }} as spam. If you want to leave {{ $siteName }} completely, please unsubscribe:</p>
        </mj-text>

        <mj-button mj-class="btn-success" href="{{ $unsubscribeUrl }}" border-radius="3px" font-size="14px" padding="10px 25px">
          Click here to unsubscribe
        </mj-button>

        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>Marking emails as spam affects other freeglers - it makes their emails go to spam too. So we've turned your emails off.</p>
          <p>If you want to stay a freegler, you can turn them back on from Settings:</p>
        </mj-text>

        <mj-button mj-class="btn-success" href="{{ $settingsUrl }}" border-radius="3px" font-size="14px" padding="10px 25px">
          Change your email settings
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer')
  </mj-body>
</mjml>
