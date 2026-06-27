<mjml>
  @include('emails.mjml.partials.head', ['preview' => $todayVerdicts . ' verdicts today, ' . $needsImproving . ' need improving'])

  <mj-body>
    @include('emails.mjml.partials.header', ['title' => 'AI Image Review Digest'])

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <h2>Daily Summary</h2>
          <ul>
            <li><strong>Verdicts today:</strong> {{ $todayVerdicts }}</li>
            <li><strong>Images with quorum (5+ votes):</strong> {{ $totalReviewed }} of {{ $totalImages }} ({{ $percentReviewed }}%)</li>
            <li><strong>Images needing improvement:</strong> {{ $needsImproving }}</li>
          </ul>
        </mj-text>
      </mj-column>
    </mj-section>

    @if(count($topProblems) > 0)
    <mj-section padding="0">
      <mj-column>
        <mj-text font-size="14px" line-height="1.5" padding="10px 25px">
          <h2>Top {{ count($topProblems) }} Images Needing Improvement</h2>
          <p>Ordered by usage count (most-used first). Outlier voters excluded.</p>
        </mj-text>

        <mj-table padding="10px 25px" font-size="13px">
          <tr style="border-bottom:1px solid #ecedee;text-align:left;">
            <th style="padding:6px;">Image</th>
            <th style="padding:6px;">Uses</th>
            <th style="padding:6px;">Good</th>
            <th style="padding:6px;">Bad</th>
            <th style="padding:6px;">People</th>
          </tr>
          @foreach($topProblems as $img)
          <tr style="border-bottom:1px solid #ecedee;">
            <td style="padding:6px;">{{ $img['name'] }}</td>
            <td style="padding:6px;">{{ $img['usage_count'] }}</td>
            <td style="padding:6px;">{{ $img['approve_count'] }}</td>
            <td style="padding:6px;">{{ $img['reject_count'] }}</td>
            <td style="padding:6px;">{{ $img['people_count'] }}</td>
          </tr>
          @endforeach
        </mj-table>
      </mj-column>
    </mj-section>
    @endif

    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
          This is an automated notification from {{ $siteName }}.
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
