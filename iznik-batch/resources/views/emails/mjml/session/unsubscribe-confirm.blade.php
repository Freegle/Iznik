<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Please confirm you want to leave Freegle'])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => 'Leaving Freegle?'])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>Please click the button below to confirm you want to leave Freegle.</p>
        </mj-text>

        <mj-button mj-class="btn-success" href="{{ $unsubUrl }}" border-radius="3px" font-size="14px" padding="10px 25px">
          Click here to leave Freegle
        </mj-button>

        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p><strong>This will remove all your data and cannot be undone.</strong></p>
          <p>If you just want to leave a Freegle community or reduce the number of emails you get, <a href="{{ config('freegle.sites.user') }}/settings" style="color: #338808;">go to your Settings</a> instead.</p>
        </mj-text>

        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>If you didn't try to leave, please ignore this email.</p>
          <p>Thanks for freegling, and do please come back in the future.</p>
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email])
  </mj-body>
</mjml>
