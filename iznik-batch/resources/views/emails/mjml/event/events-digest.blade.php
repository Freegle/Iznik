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
          If you'd like to add one, <a href="https://{{ $userSite }}/communityevents">click here</a>.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($events as $event)
    <mj-section background-color="#ffffff" padding="0 20px 0">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>

    @if (!empty($event['imageUrl']))
    {{-- Event with photo: text left, image right --}}
    <mj-section background-color="#ffffff" padding="16px 20px 8px">
      <mj-column width="60%">
        <mj-text font-size="18px" font-weight="bold" mj-class="text-success" padding="0 0 4px">
          <a href="{{ $event['url'] }}" style="color: inherit; text-decoration: none;">{{ $event['title'] }}</a>
        </mj-text>
        <mj-text font-size="13px" color="#555555" font-weight="bold" padding="0 0 4px">
          🗓 {{ $event['start'] }}{{ !empty($event['end']) ? ' – ' . $event['end'] : '' }}
        </mj-text>
        @if (!empty($event['location']))
        <mj-text font-size="13px" color="#666666" padding="0 0 6px">
          📍 {{ $event['location'] }}
        </mj-text>
        @endif
        @if (!empty($event['description']))
        <mj-text font-size="13px" color="#333333" padding="0 0 6px" line-height="1.5">
          {{ $event['description'] }}
        </mj-text>
        @endif
      </mj-column>
      <mj-column width="40%">
        <mj-image src="{{ $event['imageUrl'] }}" alt="{{ $event['title'] }}"
          border="1px solid #dddddd" border-radius="6px"
          padding="8px 0 0" />
      </mj-column>
    </mj-section>
    @else
    {{-- Event without photo: full width --}}
    <mj-section background-color="#ffffff" padding="16px 20px 8px">
      <mj-column>
        <mj-text font-size="18px" font-weight="bold" mj-class="text-success" padding="0 0 4px">
          <a href="{{ $event['url'] }}" style="color: inherit; text-decoration: none;">{{ $event['title'] }}</a>
        </mj-text>
        <mj-text font-size="13px" color="#555555" font-weight="bold" padding="0 0 4px">
          🗓 {{ $event['start'] }}{{ !empty($event['end']) ? ' – ' . $event['end'] : '' }}
        </mj-text>
        @if (!empty($event['location']))
        <mj-text font-size="13px" color="#666666" padding="0 0 6px">
          📍 {{ $event['location'] }}
        </mj-text>
        @endif
        @if (!empty($event['description']))
        <mj-text font-size="13px" color="#333333" padding="0 0 6px" line-height="1.5">
          {{ $event['description'] }}
        </mj-text>
        @endif
      </mj-column>
    </mj-section>
    @endif

    @if (!empty($event['contactname']) || !empty($event['contactphone']) || !empty($event['contactemail']) || !empty($event['contacturl']))
    <mj-section background-color="#f9f9f9" padding="4px 20px 8px">
      <mj-column>
        <mj-text font-size="12px" color="#555555" padding="0">
          <strong>Contact:</strong>
          @if (!empty($event['contactname'])){{ $event['contactname'] }}@endif
          @if (!empty($event['contactphone'])) &bull; <a href="tel:{{ $event['contactphone'] }}">{{ $event['contactphone'] }}</a>@endif
          @if (!empty($event['contactemail'])) &bull; <a href="mailto:{{ $event['contactemail'] }}">{{ $event['contactemail'] }}</a>@endif
          @if (!empty($event['contacturl'])) &bull; <a href="{{ $event['contacturl'] }}">Website</a>@endif
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    <mj-section background-color="#ffffff" padding="0 20px 12px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $event['url'] }}" font-size="13px" inner-padding="8px 16px" align="left">
          Find out more
        </mj-button>
      </mj-column>
    </mj-section>
    @endforeach

    <mj-section background-color="#ffffff" padding="16px 20px 20px">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0 0 16px" />
        <mj-button href="https://{{ $userSite }}/communityevents" mj-class="btn-success" border-radius="3px" font-size="16px">
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
