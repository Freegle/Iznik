<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Please confirm: ' . $title . ' on ' . $groupName . ' - is it still active?'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" mj-class="text-success">
          Your Volunteer Opportunity on {{ $groupName }}: {{ $title }}
        </mj-text>
        <mj-text font-size="14px" color="#555555" line-height="1.5">
          Please can you let us know whether this volunteer opportunity is still active?
          If we don't hear from you, we'll stop showing it soon. Don't forget to log in!
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 16px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $renewUrl }}" font-size="16px" border-radius="3px" align="left">
          Let us know
        </mj-button>
        <mj-text font-size="12px" color="#999999" padding="8px 0 0">
          If that's not clickable, copy and paste this into your browser:<br/>
          <a href="{{ $renewUrl }}" style="color: #999999;">{{ $renewUrl }}</a>
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', [
      'unsubscribeUrl' => $unsubscribeUrl,
      'email'          => $email,
    ])

  </mj-body>
</mjml>
