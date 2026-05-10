<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Community events in your area'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
          Community Events from {{ $groupName }}
        </mj-text>
        <mj-text>
          {!! nl2br(e($summary)) !!}
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email])

  </mj-body>
</mjml>
