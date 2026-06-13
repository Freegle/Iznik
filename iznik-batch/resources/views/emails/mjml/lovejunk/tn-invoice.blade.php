<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Please raise an invoice for LoveJunk advertising income'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          LoveJunk Invoice Request
        </mj-text>
        <mj-text>
          Please raise an invoice on Freegle for your share of the LoveJunk advertising income.
        </mj-text>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text>
          <strong>Amount:</strong> £{{ $tnAmount }}<br/>
          <strong>Period:</strong> {{ $start }} (inclusive) to {{ $end }} (exclusive)<br/>
          <strong>TN share:</strong> {{ $tnPercent }}% of posts sent to LoveJunk
        </mj-text>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text>
          The invoice should be emailed as a PDF attachment to <a href="mailto:{{ $treasurerAddr }}">{{ $treasurerAddr }}</a>. Thanks!
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
