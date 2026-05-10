<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Groups with chat messages pending review'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          Chat messages pending review
        </mj-text>
        <mj-text>
          Here's a list of groups which have chat messages pending review for more than 48 hours:
        </mj-text>
        <mj-text>
          <pre style="font-family: monospace; font-size: 13px; color: #333333;">{{ $summary }}</pre>
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
