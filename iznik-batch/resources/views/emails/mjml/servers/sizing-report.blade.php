<mjml>
  @include('emails.mjml.partials.head', ['preview' => $previewText])

  <mj-body background-color="#f4f4f4">
    @include('emails.mjml.partials.header', ['title' => 'Server sizing'])

    {{-- The headline: what we pay, and what the rules say could be saved. --}}
    <mj-section background-color="#ffffff" padding="15px 0 5px">
      <mj-column>
        <mj-text font-size="15px" line-height="1.5" padding="5px 25px">
          Our current monthly invoice is expected to be about <strong>{{ $invoiceExpected }}</strong> ({{ $invoiceBeforeVat }} before VAT). Earlier invoices may be different.
          @if ($possibleSaving)
            The moves down suggested below would save <strong>{{ $possibleSaving }} a month</strong>.
          @else
            No change that saves money is suggested this week.
          @endif
          @if ($possibleCost)
            The moves up suggested below would cost <strong>{{ $possibleCost }} a month</strong> more.
          @endif
        </mj-text>
        @if ($historyDays < $minHistoryDays)
        <mj-text font-size="14px" line-height="1.5" padding="5px 25px" color="#8a5a00">
          {{ $historyDays === 0 ? 'Readings only began in the last day.' : 'There '.($historyDays === 1 ? 'is 1 day' : "are {$historyDays} days").' of readings so far.' }} A move down is only suggested after readings on {{ $minHistoryDays }} days, so this report shows where things stand and what the readings point to.
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    {{-- Claude's review of the figures. --}}
    <mj-section background-color="#ffffff" padding="0 0 10px">
      <mj-column>
        @if ($review)
          @foreach (['changes' => 'Changes worth making', 'watch' => 'Worth watching', 'caveats' => 'What the rules cannot see'] as $key => $heading)
            @if (!empty($review[$key]))
            <mj-text font-size="14px" line-height="1.5" padding="8px 25px 0">
              <strong>{{ $heading }}</strong>
              <ul style="margin:4px 0 0;padding-left:20px;">
                @foreach ($review[$key] as $line)
                <li>{{ $line }}</li>
                @endforeach
              </ul>
            </mj-text>
            @endif
          @endforeach
          <mj-text font-size="12px" color="#666666" padding="8px 25px 0">
            This review was written by AI from the figures below. The verdict on each server comes from fixed rules, not from the AI.
          </mj-text>
        @else
          <mj-text font-size="13px" color="#666666" padding="8px 25px 0">
            The AI review was not available this week. The figures and verdicts below are complete without it.
          </mj-text>
        @endif
      </mj-column>
    </mj-section>

    {{-- One card per server, dearest first. --}}
    @foreach ($cards as $card)
    <mj-section background-color="#ffffff" padding="10px 0 0" border-top="6px solid #f4f4f4">
      <mj-column>
        <mj-text font-size="17px" font-weight="bold" mj-class="text-header" padding="8px 25px 0">
          {{ $card['name'] }}
        </mj-text>
        <mj-text font-size="13px" color="#555555" line-height="1.4" padding="2px 25px 0">
          {{ $card['package'] }}. {{ $card['cost'] }}.
        </mj-text>
        <mj-text font-size="14px" font-weight="bold" line-height="1.4" padding="8px 25px 0" color="{{ $card['tone'] === 'act' ? '#b35c00' : ($card['tone'] === 'ok' ? '#338808' : '#555555') }}">
          {{ $card['verdict'] }}
        </mj-text>
        @if (!empty($card['reasons']))
        <mj-text font-size="13px" line-height="1.5" padding="2px 25px 0">
          @foreach ($card['reasons'] as $reason)
          {{ $reason }}@if (!$loop->last)<br />@endif
          @endforeach
        </mj-text>
        @endif
        @if (!empty($card['lines']))
        <mj-table padding="8px 25px 0" font-size="13px" line-height="1.4">
          @foreach ($card['lines'] as $line)
          <tr style="border-top:1px solid #ecedee;">
            <td style="padding:5px 8px 5px 0;color:#555555;width:38%;vertical-align:top;">{{ $line[0] }}</td>
            <td style="padding:5px 0;vertical-align:top;">{{ $line[1] }}</td>
          </tr>
          @endforeach
        </mj-table>
        @endif
        @if (!empty($card['trend']))
        <mj-text font-size="12px" color="#555555" line-height="1.5" padding="8px 25px 0">
          <strong>Week by week</strong><br />
          @foreach ($card['trend'] as $row)
          {{ $row }}@if (!$loop->last)<br />@endif
          @endforeach
        </mj-text>
        @endif
        <mj-spacer height="12px" />
      </mj-column>
    </mj-section>
    @endforeach

    @if (!empty($unmatchedHosts))
    <mj-section background-color="#ffffff" padding="10px 0" border-top="6px solid #f4f4f4">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="8px 25px">
          <strong>Sampled, but not a server Katapult lists</strong><br />
          {{ implode(', ', $unmatchedHosts) }}. Its readings are stored but it is not in this report. Check the name it gives itself against its name on Katapult.
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    @if (!empty($unattached))
    <mj-section background-color="#ffffff" padding="10px 0" border-top="6px solid #f4f4f4">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="8px 25px">
          <strong>Disks to look at</strong>
          <ul style="margin:4px 0 0;padding-left:20px;">
            @foreach ($unattached as $line)
            <li>{{ $line }}</li>
            @endforeach
          </ul>
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    <mj-section background-color="#f5f5f5" padding="15px 0">
      <mj-column>
        <mj-text font-size="11px" color="#666666" line-height="1.5" padding="5px 25px">
          How to read this. "Busy end" is the level a server stayed under for 95% of the week. A smaller package is suggested only if the busiest week in up to eight would leave headroom on it: load at most 70% of the processors, processor time at most 60% in use, memory at most 80% used. Only packages of the same kind are compared. The next size up is suggested when load is above the number of processors while they are more than 60% in use, processor time is above 85% in use, or memory is above 92% used. A disk is flagged at 85% full, or when its growth would take it to 90% within eight weeks.
        </mj-text>
        <mj-text font-size="11px" color="#666666" line-height="1.5" padding="5px 25px">
          The cost shown for each server is before VAT. Costs are Katapult's monthly prices for our account ({{ $monthlyList }} in all){{ $discountPct > 0 ? ", less our {$discountPct}% discount" : "" }}. {{ $otherPaid ? "The expected invoice adds {$otherPaid} a month for charges the API does not show, such as object storage, then VAT at {$vatPct}%." : "The expected invoice adds VAT at {$vatPct}%; it leaves out object storage, data transfer and backups, which the API does not show." }} A server marked as meant to run flat out is judged on late-job alerts and memory, not on load. Every GB of disk is charged for, whatever the package includes, and a stopped server is still charged for its package, so a change of package changes the package price and nothing else. Taking processors or memory away needs the server shut down; adding them does not. Disks can grow but never shrink.
        </mj-text>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5" padding="5px 25px">
          This is an automated report from {{ $siteName }}, sent weekly by servers:rightsize-report.
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
