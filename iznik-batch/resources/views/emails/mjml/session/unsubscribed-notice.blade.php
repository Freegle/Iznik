<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'We have changed your email settings, and here is what that means.'])
  <mj-body background-color="#ffffff">
    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333">
          Hi {{ $recipientName ?? 'there' }},
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="10px 20px">
      <mj-column>
        @if($alreadyOff)
          <mj-text font-size="14px" color="#555555" line-height="1.6">
            Thanks - you asked us to stop sending you {{ $whatTheyAskedFor }}. Those were already switched off, so there is nothing more to do.
          </mj-text>
        @else
          <mj-text font-size="14px" color="#555555" line-height="1.6">
            Thanks - we've turned off:
          </mj-text>
          <mj-text font-size="14px" color="#555555" line-height="1.6" padding-top="4px">
            <ul style="margin: 0; padding-left: 20px;">
              @foreach($turnedOff as $item)
                <li>{{ $item }}</li>
              @endforeach
            </ul>
          </mj-text>
        @endif

        @if(count($stillOn))
          <mj-text font-size="14px" color="#555555" line-height="1.6" padding-top="14px">
            You may still get some other kinds of email from us:
          </mj-text>
          <mj-text font-size="14px" color="#555555" line-height="1.6" padding-top="4px">
            <ul style="margin: 0; padding-left: 20px;">
              @foreach($stillOn as $item)
                <li>{{ $item }}</li>
              @endforeach
            </ul>
          </mj-text>
        @else
          <mj-text font-size="14px" color="#555555" line-height="1.6" padding-top="14px">
            That's everything switched off. We'll only email you if you ask us to, or about something essential like resetting your password.
          </mj-text>
        @endif
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-button href="{{ $settingsUrl }}" mj-class="btn-success" font-size="16px" inner-padding="14px 40px">
          Change your email settings
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px 20px">
      <mj-column>
        <mj-text font-size="13px" color="#888888" align="center" line-height="1.6">
          You can turn any of these back on, or stop everything, from your
          <a href="{{ $settingsUrl }}" style="color: #338808;">settings</a>. You don't need to reply to this email - nobody reads that mailbox.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $recipientEmail, 'settingsUrl' => $settingsUrl, 'unsubscribeUrl' => $unsubscribeUrl])

    @if(!empty($trackingPixelHtml))
    <mj-section padding="0">
      <mj-column>
        <mj-text padding="0">{!! $trackingPixelHtml !!}</mj-text>
      </mj-column>
    </mj-section>
    @endif
  </mj-body>
</mjml>
