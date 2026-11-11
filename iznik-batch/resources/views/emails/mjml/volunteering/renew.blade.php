<mjml>
  @include('emails.mjml.partials.head', ['preview' => count($opportunities) === 1
      ? 'Please confirm: ' . $opportunities[0]['title'] . ' on ' . $opportunities[0]['groupName'] . ' - is it still active?'
      : 'Please confirm: are your ' . count($opportunities) . ' volunteer opportunities still active?'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        @if(count($opportunities) === 1)
        <mj-text font-size="22px" font-weight="bold" mj-class="text-success">
          Your Volunteer Opportunity on {{ $opportunities[0]['groupName'] }}: {{ $opportunities[0]['title'] }}
        </mj-text>
        <mj-text font-size="14px" color="#555555" line-height="1.5">
          Please can you let us know whether this volunteer opportunity is still active?
          If we don't hear from you, we'll stop showing it soon. Don't forget to log in!
        </mj-text>
        @else
        <mj-text font-size="22px" font-weight="bold" mj-class="text-success">
          Are your volunteer opportunities still active?
        </mj-text>
        <mj-text font-size="14px" color="#555555" line-height="1.5">
          Please can you let us know whether each of these volunteer opportunities is still active?
          If we don't hear from you, we'll stop showing them soon. Don't forget to log in!
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    @foreach($opportunities as $opportunity)
    <mj-section background-color="#ffffff" padding="0 20px 16px">
      <mj-column>
        @if(count($opportunities) > 1)
        <mj-text font-size="16px" font-weight="bold" padding-bottom="0">
          {{ $opportunity['title'] }}
        </mj-text>
        <mj-text font-size="13px" color="#555555" padding-top="2px">
          {{ $opportunity['groupName'] }}
        </mj-text>
        @endif
        <mj-button mj-class="btn-success" href="{{ $opportunity['renewUrl'] }}" font-size="16px" border-radius="3px" align="left">
          Let us know
        </mj-button>
        <mj-text font-size="12px" color="#999999" padding="8px 0 0">
          If that's not clickable, copy and paste this into your browser:<br/>
          <a href="{{ $opportunity['renewUrl'] }}" style="color: #999999;">{{ $opportunity['renewUrl'] }}</a>
        </mj-text>
      </mj-column>
    </mj-section>
    @endforeach

    @if(!empty($trackingPixelMjml))
    <mj-section padding="0">
      <mj-column>
        {!! $trackingPixelMjml !!}
      </mj-column>
    </mj-section>
    @endif

    @include('emails.mjml.partials.footer', [
      'unsubscribeUrl' => $unsubscribeUrl,
      'email'          => $email,
    ])

  </mj-body>
</mjml>
