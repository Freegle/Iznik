<mjml>
  @include('emails.mjml.partials.head', ['preview' => "New chitchat posts from your members"])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          {{ $count }} chitchat post{{ $count !== 1 ? 's' : '' }} from your members
        </mj-text>
        <mj-text>
          {!! nl2br(e($summary)) !!}
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        <mj-button href="{{ $modSite }}/chitchat" mj-class="btn-modtools" border-radius="3px" font-size="16px">
          View Chitchat
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
