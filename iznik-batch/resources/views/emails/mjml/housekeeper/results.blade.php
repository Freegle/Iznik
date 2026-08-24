<mjml>
  @include('emails.mjml.partials.head', ['preview' => ($taskStatus === 'success' ? 'OK' : 'FAILED') . ': ' . $summary])

  <mj-body background-color="#ffffff">
    @include('emails.mjml.partials.header', ['title' => 'Housekeeper: ' . $task])

    {{-- Status badge --}}
    <mj-section padding="20px 20px 10px 20px">
      <mj-column>
        @if($taskStatus === 'success')
          <mj-button mj-class="btn-success" border-radius="4px" font-size="16px" padding="10px 30px" href="#">
            OK
          </mj-button>
        @else
          <mj-button background-color="#dc3545" color="white" font-weight="bold" border-radius="4px" font-size="16px" padding="10px 30px" href="#">
            FAILED
          </mj-button>
        @endif
      </mj-column>
    </mj-section>

    {{-- Summary --}}
    <mj-section padding="10px 20px">
      <mj-column>
        <mj-text font-size="16px" line-height="1.6" align="center">
          {{ $summary }}
        </mj-text>
        <mj-text font-size="13px" color="#666666" align="center" padding-top="5px">
          {{ $timestamp }}
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Results table --}}
    @if(!empty($results))
      <mj-section padding="10px 20px 20px 20px">
        <mj-column>
          <mj-table font-size="14px" cellpadding="6" css-class="results-table">
            <tr style="background-color: #f5f5f5; font-weight: bold; text-align: left;">
              <td>Facebook ID</td>
              <td>Freegle ID</td>
              <td>Action</td>
            </tr>
            @foreach($results as $r)
              <tr style="border-bottom: 1px solid #ecedee;">
                <td>{{ $r['facebook_id'] ?? '?' }}</td>
                <td>{{ $r['freegle_id'] ?? 'N/A' }}</td>
                <td>{{ $r['status'] ?? '?' }}</td>
              </tr>
            @endforeach
          </mj-table>
        </mj-column>
      </mj-section>
    @endif

    @include('emails.mjml.partials.footer', ['email' => config('freegle.mail.noreply_addr')])
  </mj-body>
</mjml>
