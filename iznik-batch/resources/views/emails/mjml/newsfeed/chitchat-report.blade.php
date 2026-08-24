<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Reported by ' . $reporterName . ': ' . \Illuminate\Support\Str::limit($reason, 70)])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => 'ChitChat Report'])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p><strong>{{ $reporterName }}</strong> (#{{ $reporterId }}, {{ $reporterEmail }}) has reported a ChitChat thread.</p>
        </mj-text>

        <mj-text font-size="14px" line-height="1.5" padding="10px 25px" color="#666666">
          <p><strong>Reason:</strong></p>
          <p>{{ $reason }}</p>
        </mj-text>

        <mj-button mj-class="btn-success" href="{{ $threadUrl }}" border-radius="3px" font-size="14px" padding="10px 25px">
          View Thread
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
          This is an automated notification from {{ $siteName }}.
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
