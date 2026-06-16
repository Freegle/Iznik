<mjml>
  @include('emails.mjml.partials.head', ['preview' => $count . ' conversation' . ($count !== 1 ? 's' : '') . ' from your neighbours'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" color="#333333">
          Recent conversations from nearby freeglers
        </mj-text>
        <mj-text font-size="14px" color="#555555">
          Here's what people near you have been chatting about on Freegle.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($items as $item)
    <mj-section background-color="#F7F6EC" padding="12px 20px">
      <mj-column>
        <mj-text font-size="14px" color="#333333" padding="0 0 6px">
          <strong>{{ $item['author'] }}</strong>: {{ $item['text'] }}
        </mj-text>
        @foreach ($item['replies'] as $reply)
        <mj-text font-size="13px" color="#666666" padding="2px 0 2px 16px">
          ↳ <strong>{{ $reply['author'] }}</strong>: {{ $reply['text'] }}
        </mj-text>
        @endforeach
      </mj-column>
    </mj-section>
    <mj-section padding="0" background-color="#ffffff">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    @endforeach

    <mj-section background-color="#ffffff" padding="10px 20px 20px">
      <mj-column>
        <mj-button href="{{ $readUrl }}" background-color="#338808" border-radius="3px" font-size="16px">
          Read them on Freegle
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email, 'settingsUrl' => $settingsUrl])

  </mj-body>
</mjml>
