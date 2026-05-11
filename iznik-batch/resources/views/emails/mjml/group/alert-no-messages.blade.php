<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Groups with no recent messages'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          WARNING: {{ $count }} group(s) not receiving messages
        </mj-text>
        <mj-text>
          {!! nl2br(e($body)) !!}
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
