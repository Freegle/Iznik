<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'How to reply to posts on Freegle'])
  <mj-body background-color="#ffffff">
    {{-- Header with logo --}}
    @include('emails.mjml.components.header')

    {{-- Greeting --}}
    <mj-section background-color="#ffffff" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333">
          Hi {{ $recipientName ?? 'there' }},
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Explanation --}}
    <mj-section background-color="#ffffff" padding="10px 20px">
      <mj-column>
        <mj-text font-size="14px" color="#555555" line-height="1.6">
          It looks like you tried to reply to a digest email. Digest emails contain multiple posts from your Freegle communities, so we can't tell which post you'd like to reply to.
        </mj-text>
        <mj-text font-size="14px" color="#555555" line-height="1.6" padding-top="10px">
          To reply to a specific post, please use the <strong>Reply</strong> button next to the post you're interested in. This will open a conversation with the person who posted it.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- CTA buttons --}}
    <mj-section background-color="#ffffff" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-button href="{{ $browseUrl }}" mj-class="btn-success" font-size="16px" inner-padding="14px 40px">
          Browse Posts
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px 20px">
      <mj-column>
        <mj-text font-size="13px" color="#888888" align="center">
          You can also change how you receive emails in your
          <a href="{{ $settingsUrl }}" style="color: #338808;">settings</a>.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Footer --}}
    @include('emails.mjml.partials.footer', ['email' => $recipientEmail, 'settingsUrl' => $settingsUrl])

    {{-- Tracking pixel --}}
    @if(!empty($trackingPixelHtml))
    <mj-section padding="0">
      <mj-column>
        <mj-text padding="0">{!! $trackingPixelHtml !!}</mj-text>
      </mj-column>
    </mj-section>
    @endif
  </mj-body>
</mjml>
