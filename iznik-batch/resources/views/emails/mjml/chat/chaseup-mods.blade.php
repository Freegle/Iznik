<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Member conversation needs a reply'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          Member conversation needs a reply
        </mj-text>
        <mj-text>
          We can't find a reply to this message from your member, so we're resending it in case you missed it.
        </mj-text>
        <mj-text>
          If you've already replied, or if it doesn't need a reply, please ignore this.
        </mj-text>
        <mj-text>
          <strong>From:</strong> {{ $memberName }} ({{ $memberEmail }})
        </mj-text>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text>
          {!! nl2br(e($textSummary)) !!}
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        <mj-button href="{{ $chatUrl }}" mj-class="btn-modtools" border-radius="3px" font-size="16px">
          View and Reply
        </mj-button>
        <mj-text font-size="13px" color="#666666">
          You can also reply by email, but the button works better.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
