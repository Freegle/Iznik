<mjml>
  @include('emails.mjml.partials.head', ['preview' => $reason ?? $title])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px 20px 0">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          A volunteer has asked Support for help
        </mj-text>
        <mj-table padding-top="0" cellpadding="4" font-size="14px">
          <tr>
            <td style="width: 110px; color: #666666;">Volunteer</td>
            <td>{{ $referrerName }} (#{{ $referrerId }})</td>
          </tr>
          @if($groupName)
          <tr>
            <td style="color: #666666;">Community</td>
            <td>{{ $groupName }}</td>
          </tr>
          @endif
          @if($memberName)
          <tr>
            <td style="color: #666666;">Member</td>
            <td>{{ $memberName }} (#{{ $memberId }})</td>
          </tr>
          @endif
          <tr>
            <td style="color: #666666;">Chat</td>
            <td>
              @if($chatId)
                #{{ $chatId }}@if($chatTypeText !== '') ({{ $chatTypeText }})@endif
              @else
                No longer there
              @endif
            </td>
          </tr>
        </mj-table>
      </mj-column>
    </mj-section>

    @if($reason)
    <mj-section background-color="#ffffff" padding="10px 20px 0">
      <mj-column mj-class="bg-light" border-left="4px solid #396aa3" padding="4px 0">
        <mj-text font-size="13px" color="#666666" padding-bottom="0">
          What they need help with
        </mj-text>
        <mj-text font-size="15px">
          {!! nl2br(e($reason)) !!}
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    @if($chatUrl || $memberUrl)
    <mj-section background-color="#ffffff" padding="10px 20px 0">
      @if($chatUrl)
      <mj-column>
        <mj-button href="{{ $chatUrl }}" mj-class="btn-modtools btn-group" border-radius="3px" font-size="15px">
          Read the chat
        </mj-button>
      </mj-column>
      @endif
      @if($memberUrl)
      <mj-column>
        <mj-button href="{{ $memberUrl }}" mj-class="btn-dark btn-group" border-radius="3px" font-size="15px">
          Member in Support Tools
        </mj-button>
      </mj-column>
      @endif
    </mj-section>
    @endif

    <mj-section background-color="#ffffff" padding="10px 20px 0">
      <mj-column>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text font-size="17px" font-weight="bold" mj-class="text-modtools" padding-bottom="0">
          AI first pass
        </mj-text>
        @if($brief)
          <mj-text font-size="13px" color="#666666" padding-bottom="0">
            The AI helper looked into this before you. Check it before relying on it.
          </mj-text>
          @if($brief['question'] !== '')
          <mj-text>
            <strong>The question:</strong> {{ $brief['question'] }}
          </mj-text>
          @endif
          @if($brief['answer'] !== '')
          <mj-text line-height="1.5">
            {!! nl2br(e($brief['answer'])) !!}
          </mj-text>
          @endif
          @if($brief['classification'] !== '' || $brief['confidence'] !== '')
          <mj-text font-size="13px" color="#666666">
            @if($brief['classification'] !== '')Classification: <strong>{{ $brief['classification'] }}</strong>.@endif
            @if($brief['confidence'] !== '')Confidence: <strong>{{ $brief['confidence'] }}</strong>@if($brief['confidenceReason'] !== '') ({{ $brief['confidenceReason'] }})@endif.@endif
          </mj-text>
          @endif
          @if($brief['open'])
          <mj-text padding-bottom="0"><strong>Open questions</strong></mj-text>
          <mj-text padding-top="0">
            <ul style="margin: 0; padding-left: 1.4em;">
              @foreach($brief['open'] as $q)<li>{{ $q }}</li>@endforeach
            </ul>
          </mj-text>
          @endif
        @else
          <mj-text>
            There is no first pass for this one: {{ $skipReason }}.
          </mj-text>
        @endif
      </mj-column>
    </mj-section>

    @if($brief && $brief['draft'] !== '')
    <mj-section background-color="#ffffff" padding="10px 20px 0">
      <mj-column mj-class="bg-green-light" border-left="4px solid #338808" padding="4px 0">
        <mj-text font-size="13px" color="#666666" padding-bottom="0">
          Suggested reply to the volunteer. Not sent: check it, change what you need, then reply.
        </mj-text>
        <mj-text line-height="1.5">
          {!! nl2br(e($brief['draft'])) !!}
        </mj-text>
        @if($brief['warnings'])
        <mj-text font-size="13px" mj-class="text-danger" padding-top="0">
          Check before sending:
          <ul style="margin: 0; padding-left: 1.4em;">
            @foreach($brief['warnings'] as $w)<li>{{ $w }}</li>@endforeach
          </ul>
        </mj-text>
        @endif
      </mj-column>
    </mj-section>
    @endif

    @if($brief && $brief['checks'])
    <mj-section background-color="#ffffff" padding="10px 20px 0">
      <mj-column>
        <mj-text padding-bottom="0"><strong>What was checked</strong></mj-text>
        <mj-text padding-top="0" font-size="13px">
          <ul style="margin: 0; padding-left: 1.4em;">
            @foreach($brief['checks'] as $c)<li>{{ $c }}</li>@endforeach
          </ul>
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    <mj-section background-color="#ffffff" padding="10px 20px 20px">
      <mj-column>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text font-size="13px" color="#666666">
          Reply to this email to reach {{ $replyToName }}.
          @if($brief)<br/>{{ $brief['pool'] }}.@endif
        </mj-text>
      </mj-column>
    </mj-section>

  </mj-body>
</mjml>
