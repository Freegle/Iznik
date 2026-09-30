<mjml>
  @include('emails.mjml.partials.head', ['preview' => $ended
      ? $partnershipName . ' ended on ' . $endDate . ' with nothing agreed to follow it'
      : $partnershipName . ' ends on ' . $endDate . ' - time to ask about next year'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px 20px 0">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="{{ $ended ? 'text-danger' : 'text-modtools' }}">
          {{ $ended ? 'Sponsorship ended without renewal' : 'Sponsorship renewal due' }}
        </mj-text>
        <mj-text font-size="15px" line-height="1.5">
          @if($ended)
            <strong>{{ $partnershipName }}</strong> ({{ $authorityName }}) ended on
            <strong>{{ $endDate }}</strong>, and nothing has been agreed to follow it.
          @else
            <strong>{{ $partnershipName }}</strong> ({{ $authorityName }}) ends on
            <strong>{{ $endDate }}</strong>, which is {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} away.
          @endif
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px">
      <mj-column>
        <mj-table font-size="14px" padding="4px 25px">
          <tr>
            <td style="padding:6px 12px 6px 0;font-weight:bold;width:45%">Value of the deal</td>
            <td style="padding:6px 0">£{{ number_format($amount) }}</td>
          </tr>
          <tr>
            <td style="padding:6px 12px 6px 0;font-weight:bold">Communities covered</td>
            <td style="padding:6px 0">{{ $groupCount }}</td>
          </tr>
        </mj-table>
        @if(count($contacts))
          <mj-text font-size="14px" font-weight="bold" padding="12px 25px 4px">
            Council {{ Str::plural('contact', count($contacts)) }}
          </mj-text>
          @foreach($contacts as $contact)
            <mj-text font-size="14px" line-height="1.4" padding="4px 25px">
              {{ $contact['name'] ?: 'No name given' }}
              <span style="color:#666666">({{ $contact['role'] }})</span>
              @if($contact['email'])
                <br/><a href="mailto:{{ $contact['email'] }}" style="font-weight:normal;word-break:break-all">{{ $contact['email'] }}</a>
              @endif
            </mj-text>
          @endforeach
        @endif
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="10px 20px 20px">
      <mj-column>
        <mj-text font-size="15px" line-height="1.5">
          @if($ended)
            By now the council should have renewed and paid for the next year. The sponsor logo
            and tagline have stopped showing to members. Worth chasing, or recording on the page
            that they are not renewing.
          @else
            This is the point to ask the council about next year. The sponsor logo and tagline
            stop showing to members on the day it ends, and a council's budget round takes months.
          @endif
        </mj-text>
        <mj-button href="{{ $modToolsUrl }}" mj-class="btn-modtools" border-radius="3px" font-size="16px">
          Open the partnership
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text font-size="12px" color="#666666" align="center" line-height="1.6">
          Sent to the Partnerships team at {{ $email }} about each council deal coming up for
          renewal. Change the address on the Teams page in ModTools.
        </mj-text>
        <mj-divider border-color="#ddd" border-width="1px" padding="15px 40px"></mj-divider>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
          {{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
          Registered address: {{ config('freegle.branding.registered_address') }}
        </mj-text>
      </mj-column>
    </mj-section>

  </mj-body>
</mjml>
