<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Chat messages waiting for your review'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          Chat messages waiting for your review
        </mj-text>
        <mj-text>
          Dear {{ $groupName }} Volunteers,
        </mj-text>
        <mj-text>
          Messages between members are scanned to spot spam. For some of these, we need you to review them to check whether they are really spam or not.
        </mj-text>
        <mj-text>
          You currently have some messages which have been waiting for 48 hours for review. Some of these may be real messages which members won't have received yet, so they may be wondering what's going on.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        <mj-button href="{{ $reviewUrl }}" mj-class="btn-modtools" border-radius="3px" font-size="16px">
          Review Chat Messages
        </mj-button>
        <mj-text font-size="13px" color="#666666">
          Messages will automatically be deleted after 7 days.
        </mj-text>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text font-size="13px" color="#666666">
          This is an automated mail sent once a day. If you need help using ModTools, please ask the Mentor folk at <a href="mailto:{{ $mentorsAddr }}">{{ $mentorsAddr }}</a>.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
