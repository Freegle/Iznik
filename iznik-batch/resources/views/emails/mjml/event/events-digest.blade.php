<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Community events in ' . $groupName])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" mj-class="text-success">
          Community Event Roundup
        </mj-text>
        <mj-text font-size="14px" color="#555555">
          Here are upcoming community events for {{ $groupName }}.
          If you'd like to add one, <a href="{{ $userSite }}/communityevents">click here</a>.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($events as $event)
    <mj-section background-color="#ffffff" padding="0 20px 0">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="16px 20px 8px">
      <mj-column>
        <mj-text font-size="18px" font-weight="bold" mj-class="text-success" padding="0 0 4px">
          <a href="{{ $event['url'] }}" style="color: inherit; text-decoration: none;">{{ $event['title'] }}</a>
        </mj-text>
        <mj-text font-size="13px" color="#555555" font-weight="bold" padding="0 0 4px">
          🗓 {{ $event['start'] }}{{ !empty($event['end']) ? ' – ' . $event['end'] : '' }}
        </mj-text>
        @if (!empty($event['location']))
        <mj-text font-size="13px" color="#666666" padding="0 0 10px">
          📍 {{ $event['location'] }}
        </mj-text>
        @endif
        <mj-button mj-class="btn-success" href="{{ $event['url'] }}" font-size="13px" inner-padding="8px 16px" align="left">
          Find out more
        </mj-button>
      </mj-column>
    </mj-section>
    @endforeach

    <mj-section background-color="#ffffff" padding="16px 20px 20px">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0 0 16px" />
        <mj-button href="{{ $userSite }}/communityevents" mj-class="btn-success" border-radius="3px" font-size="16px">
          View all community events
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', [
      'unsubscribeUrl' => $unsubscribeUrl,
      'email'          => $email,
    ])

  </mj-body>
</mjml>
