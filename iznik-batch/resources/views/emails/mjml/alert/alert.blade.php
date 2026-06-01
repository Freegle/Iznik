<mjml>
  @include('emails.mjml.partials.head', ['preview' => $subject])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" color="#333333">
          {{ $subject }}
        </mj-text>

        @if ($global)
        <mj-text font-size="13px" color="#777777" padding="4px 0 0">
          Dear {{ $toName }},<br/>
          This is being sent to all Freegle groups. You will only get one copy.
        </mj-text>
        @else
        <mj-text font-size="13px" color="#777777" padding="4px 0 0">
          Dear {{ $toName }}{{ $groupName ? ' (for '.$groupName.')' : '' }},
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 10px">
      <mj-column>
        <mj-text font-size="15px" color="#333333" line-height="1.5">
          {!! $htmlBody !!}
        </mj-text>
      </mj-column>
    </mj-section>

    @if ($clickUrl)
    <mj-section background-color="#ffffff" padding="0 20px 10px">
      <mj-column>
        <mj-text font-size="14px" color="#c0392b" padding="0 0 8px">
          If you're active{{ $groupName ? ' on '.$groupName : '' }}, please let us know you got this:
        </mj-text>
        <mj-button href="{{ $clickUrl }}" background-color="#338808" border-radius="3px" font-size="16px">
          I got this
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    <mj-section background-color="#ffffff" padding="0 20px 10px">
      <mj-column>
        <mj-text font-size="11px" color="#999999">
          Sorry if you receive this several times. We have to try a variety of ways to make sure we don't miss anyone, including web beacons.
        </mj-text>
        @if ($beaconUrl)
        <mj-image src="{{ $beaconUrl }}" width="1px" height="1px" padding="0" alt="" />
        @endif
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email])

  </mj-body>
</mjml>
