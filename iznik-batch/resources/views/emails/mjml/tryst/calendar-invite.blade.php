<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Add your Freegle handover to your calendar'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
          Great news — you've arranged a handover!
        </mj-text>
        <mj-text>
          To help make sure everything goes smoothly, please add this to your calendar:
        </mj-text>
        <mj-text font-size="16px" font-weight="bold">
          {{ $title }}
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        <mj-button href="{{ $calendarLink }}" mj-class="btn-success" border-radius="3px" font-size="16px">
          Add to Calendar
        </mj-button>
        <mj-text font-size="13px" color="#666666">
          You'll be able to choose your preferred calendar app: Google Calendar &bull; Outlook &bull; Apple Calendar &bull; Yahoo &bull; and more.
        </mj-text>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text font-size="13px" color="#666666">
          If anything changes, please let the other person know through Chat on Freegle.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email, 'settingsUrl' => $settingsUrl, 'unsubscribeUrl' => $unsubscribeUrl])

  </mj-body>
</mjml>
