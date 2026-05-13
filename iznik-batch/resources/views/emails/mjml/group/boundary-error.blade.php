<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Invalid group boundary'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" color="#cc0000">
          Invalid CGA/DPA for {{ $groupId }} {{ $groupName }}
        </mj-text>
        <mj-text>
          {!! nl2br(e($errorMessage)) !!}
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
