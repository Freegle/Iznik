<mjml>
  @include('emails.mjml.partials.head', ['preview' => $subject])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" mj-class="text-modtools">
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

    @if ($requireReply)
    <mj-section background-color="#ffffff" padding="0 20px 10px">
      <mj-column>
        <mj-text font-size="14px" color="#c0392b" font-weight="bold" padding="0 0 8px">
          A reply is needed. When you next open ModTools it will ask you to answer this before you carry on.
        </mj-text>
        <mj-button href="{{ $modSite }}" mj-class="btn-modtools" border-radius="3px" font-size="16px">
          Open ModTools to reply
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    @if ($quoted)
    <mj-section background-color="#ffffff" padding="0 20px 10px">
      <mj-column border-left="4px solid #cccccc" padding-left="12px">
        <mj-text font-size="12px" color="#777777" padding="0 0 6px">
          The member's original email, in full:
        </mj-text>
        <mj-text font-size="14px" color="#555555" line-height="1.5" background-color="#f7f7f7" padding="8px 12px">
          {!! nl2br(e($quoted)) !!}
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    @if (!empty($fileNames))
    <mj-section background-color="#ffffff" padding="0 20px 10px">
      <mj-column>
        <mj-text font-size="13px" color="#555555">
          Attached: {{ implode(', ', $fileNames) }}
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    @if ($clickUrl)
    <mj-section background-color="#ffffff" padding="0 20px 10px">
      <mj-column>
        <mj-text font-size="14px" color="#c0392b" padding="0 0 8px">
          If you're active{{ $groupName ? ' on '.$groupName : '' }}, please let us know you got this:
        </mj-text>
        <mj-button href="{{ $clickUrl }}" mj-class="btn-modtools" border-radius="3px" font-size="16px">
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

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
