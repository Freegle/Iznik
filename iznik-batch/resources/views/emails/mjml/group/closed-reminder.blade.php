<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Reminder: Your Freegle group is currently closed'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
          Your Freegle group is currently closed
        </mj-text>
        <mj-text>
          Hi there — just to remind you that your Freegle group (<strong>{{ $groupName }}</strong>) is currently closed.
        </mj-text>
        <mj-text>
          If you now feel it's time to re-open, you can do that from ModTools, in Settings &rarr; Community &rarr; Features for Members.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        <mj-button href="{{ $modSite }}/modtools" mj-class="btn-success" border-radius="3px" font-size="16px">
          Go to ModTools
        </mj-button>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text font-size="13px" color="#666666">
          This is an automated reminder sent once a week. — The Freegle Tech Team
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email])

  </mj-body>
</mjml>
