<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'New Charity Partner signup: ' . $orgName])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => 'Charity Partner Signup'])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p>A new organisation has signed up as a Charity Partner.</p>
        </mj-text>

        <mj-table padding="10px 25px" font-size="14px">
          <tr>
            <td style="padding:5px 10px;font-weight:bold;width:150px">Organisation</td>
            <td style="padding:5px 10px">{{ $orgName }}</td>
          </tr>
          <tr>
            <td style="padding:5px 10px;font-weight:bold">Type</td>
            <td style="padding:5px 10px">{{ $orgType === 'registered' ? 'Registered charity' : 'Other community organisation' }}</td>
          </tr>
          @if($charityNumber)
          <tr>
            <td style="padding:5px 10px;font-weight:bold">Charity number</td>
            <td style="padding:5px 10px">{{ $charityNumber }}</td>
          </tr>
          @endif
          <tr>
            <td style="padding:5px 10px;font-weight:bold">Contact email</td>
            <td style="padding:5px 10px">{{ $contactEmail }}</td>
          </tr>
          @if($contactName)
          <tr>
            <td style="padding:5px 10px;font-weight:bold">Contact name</td>
            <td style="padding:5px 10px">{{ $contactName }}</td>
          </tr>
          @endif
          @if($website)
          <tr>
            <td style="padding:5px 10px;font-weight:bold">Website</td>
            <td style="padding:5px 10px">{{ $website }}</td>
          </tr>
          @endif
        </mj-table>

        @if($description)
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <p><strong>About the organisation:</strong></p>
          <p>{{ $description }}</p>
        </mj-text>
        @endif
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
