<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Daily donation summary'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          Donation total today: £{{ number_format($total, 2) }}
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        {{-- <mj-table> emits its own <table>/<tbody>, so $htmlContent is just
             the <tr>…</tr> rows. Using <mj-raw> here used to nest a full
             <table> inside the column's layout <tbody>, which Gmail/Outlook
             sanitised away — leaving the email with the header total but
             no donations list. --}}
        <mj-table cellpadding="0" cellspacing="0" font-size="14px"
                  line-height="1.4" border="0" width="100%">
          {!! $htmlContent !!}
        </mj-table>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
