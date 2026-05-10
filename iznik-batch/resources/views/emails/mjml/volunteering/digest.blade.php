<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Volunteer opportunities in ' . $groupName])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" mj-class="text-success">
          Volunteer Opportunity Roundup
        </mj-text>
        <mj-text font-size="14px" color="#555555">
          Charities, community organisations and good causes in {{ $groupName }} are looking for helpers.
          If you'd like to add one, <a href="{{ $userSite }}/volunteering">click here</a>.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($volunteerings as $vol)
    <mj-section background-color="#ffffff" padding="0 20px 0">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="16px 20px 8px">
      <mj-column>
        <mj-text font-size="18px" font-weight="bold" mj-class="text-success" padding="0 0 4px">
          <a href="{{ $vol['url'] }}" style="color: inherit; text-decoration: none;">{{ $vol['title'] }}</a>
        </mj-text>
        @if (!empty($vol['location']))
        <mj-text font-size="13px" color="#666666" padding="0 0 6px">
          📍 {{ $vol['location'] }}
        </mj-text>
        @endif
        @if (!empty($vol['description']))
        <mj-text font-size="14px" color="#333333" padding="0 0 10px" line-height="1.5">
          {!! nl2br(e(mb_strlen($vol['description']) > 300 ? mb_substr($vol['description'], 0, 300) . '...' : $vol['description'])) !!}
        </mj-text>
        @endif
        <mj-button mj-class="btn-success" href="{{ $vol['url'] }}" font-size="13px" inner-padding="8px 16px" align="left">
          Find out more
        </mj-button>
      </mj-column>
    </mj-section>
    @endforeach

    <mj-section background-color="#ffffff" padding="16px 20px 20px">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0 0 16px" />
        <mj-button href="{{ $userSite }}/volunteering" mj-class="btn-success" border-radius="3px" font-size="16px">
          View all volunteering opportunities
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', [
      'unsubscribeUrl' => $unsubscribeUrl,
      'email'          => $email,
    ])

  </mj-body>
</mjml>
