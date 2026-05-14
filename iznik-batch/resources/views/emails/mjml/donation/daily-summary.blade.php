<mjml>
  @include('emails.mjml.partials.head', [
    'preview' => 'Daily donation summary',
    /* MJML parses mj-style contents as XML, so the comments below avoid
       literal angle brackets — those would be read as tag delimiters and
       blow up compilation with "Malformed MJML". */
    'styles'  => '
      /* Inline cell rules. Gmail strips non-inline style blocks, so these
         have to be inline-friendly (no @media, no descendant combinators
         beyond what MJML can copy onto each matched element). */
      .donation-row td { word-break: break-word; vertical-align: top; }
    ',
    /* PHP string contents are not Blade-parsed, so a literal @media here
       reaches the compiled MJML unchanged (no @@-escaping needed). */
    'mediaStyles' => '
      /* On screens narrower than 480px the section padding + 4-column
         table starves the data cells. Shrink padding + font so the payer
         column has room to wrap instead of pushing the table off-screen.
         Lives in a non-inline mj-style block (Gmail mobile and most other
         mobile mail apps still honour those, even though desktop Gmail
         strips them; the inline cell padding above remains as the
         desktop-Gmail fallback). */
      @media only screen and (max-width: 480px) {
        .donation-row td {
          padding-left: 3px !important;
          padding-right: 3px !important;
          font-size: 12px !important;
        }
      }
    ',
  ])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px 10px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools" padding="0 10px">
          Donation total today: £{{ number_format($total, 2) }}
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 6px 16px" css-class="donation-section">
      <mj-column padding="0">
        {{-- <mj-table> emits its own <table>/<tbody>, so $htmlContent is just
             the <tr>…</tr> rows. Using <mj-raw> here used to nest a full
             <table> inside the column's layout <tbody>, which Gmail/Outlook
             sanitised away — leaving the email with the header total but
             no donations list.

             padding is tightened (was 0 20px 20px) so the 4-column table
             gets more horizontal room — on a 320px phone, 20px each side
             left only ~280px for time + amount + payer email + flags, and
             the payer column overflowed / squeezed everything else. --}}
        <mj-table cellpadding="0" cellspacing="0" font-size="14px"
                  line-height="1.4" border="0" width="100%"
                  padding="0 4px">
          {!! $htmlContent !!}
        </mj-table>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
