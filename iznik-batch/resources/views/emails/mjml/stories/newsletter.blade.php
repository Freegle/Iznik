<mjml>
  @include('emails.mjml.partials.head', ['preview' => $previewText ?? 'Stories from other freeglers!'])

  <mj-body background-color="#f0f0eb">

    {{-- Hero image --}}
    <mj-section background-color="#ffffff" padding="0">
      <mj-column>
        <mj-image
          src="{{ $headerImageUrl }}"
          alt="Freegle stories"
          fluid-on-mobile="true"
          padding="0"
        />
      </mj-column>
    </mj-section>

    {{-- Action buttons --}}
    <mj-section background-color="#ffffff" padding="16px 0">
      <mj-column width="33%">
        <mj-button
          href="{{ $tellUrl }}"
          mj-class="btn-dark"
          font-size="13px"
          border-radius="4px"
          align="center"
        >Tell your story</mj-button>
      </mj-column>
      <mj-column width="33%">
        <mj-button
          href="{{ $giveUrl }}"
          mj-class="btn-success"
          font-size="13px"
          border-radius="4px"
          align="center"
        >Give something</mj-button>
      </mj-column>
      <mj-column width="33%">
        <mj-button
          href="{{ $findUrl }}"
          mj-class="btn-success"
          font-size="13px"
          border-radius="4px"
          align="center"
        >Find something</mj-button>
      </mj-column>
    </mj-section>

    {{-- Gap --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="8px 0" color="#f0f0eb"> </mj-text></mj-column>
    </mj-section>

    {{-- Intro card --}}
    <mj-section mj-class="bg-green-light" padding="24px 0 20px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" mj-class="text-header" line-height="1.3" padding="0 25px 12px">
          We love your stories!
        </mj-text>
        <mj-text font-size="15px" color="#333333" line-height="1.7" padding="0 25px 6px">
          It's great to hear why people freegle — and here are some recent tales from other freeglers.
        </mj-text>
        <mj-text font-size="15px" color="#333333" line-height="1.7" padding="0 25px 0">
          Be inspired — <a href="{{ $tellUrl }}">tell us your story</a>, or get freegling!
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Story cards --}}
    @foreach ($stories as $story)

    {{-- Gap before each story card --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="8px 0" color="#f0f0eb"> </mj-text></mj-column>
    </mj-section>

    {{-- Headline and attribution --}}
    <mj-section background-color="#ffffff" padding="20px 0 0">
      <mj-column>
        <mj-text font-size="19px" font-weight="bold" mj-class="text-header" line-height="1.3" padding="0 25px 8px">
          &#8220;{{ $story['headline'] }}&#8221;
        </mj-text>
        @php
            $hasName = !empty($story['username']);
            $hasGroup = !empty($story['groupname']);
            $hasProfile = !empty($story['profileurl']);
        @endphp
        @if ($hasName || $hasGroup)
        <mj-text font-size="13px" color="#555555" line-height="1.4" padding="0 25px 14px">
          @if ($hasProfile)<img src="{{ $story['profileurl'] }}" width="26" height="26" style="vertical-align:middle;margin-right:7px;" />@endif
          @if ($hasName)<strong>{{ e($story['username']) }}</strong>@if ($hasGroup) &nbsp;&middot;&nbsp; <span style="color:#888888;">{{ e($story['groupname']) }}</span>@endif
          @else<span style="color:#888888;">From a freegler on {{ e($story['groupname']) }}.</span>@endif
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    {{-- Story body — with photo (side by side) or full width --}}
    @if (!empty($story['photo']))
    <mj-section background-color="#ffffff" padding="0 0 24px">
      <mj-column width="62%" vertical-align="top">
        <mj-text font-size="15px" color="#333333" line-height="1.7" padding="0 12px 0 25px">
          {!! nl2br(e($story['story'])) !!}
        </mj-text>
      </mj-column>
      <mj-column width="38%" vertical-align="top">
        <mj-image
          src="{{ $story['photo'] }}"
          alt="Story photo"
          border-radius="4px"
          fluid-on-mobile="true"
          padding="0 20px 0 5px"
        />
      </mj-column>
    </mj-section>
    @else
    <mj-section background-color="#ffffff" padding="0 0 24px">
      <mj-column>
        <mj-text font-size="15px" color="#333333" line-height="1.7" padding="0 25px">
          {!! nl2br(e($story['story'])) !!}
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    @endforeach

    {{-- Gap before closing CTAs --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="8px 0" color="#f0f0eb"> </mj-text></mj-column>
    </mj-section>

    {{-- Closing CTAs --}}
    <mj-section background-color="#ffffff" padding="16px 0 20px">
      <mj-column width="33%">
        <mj-button
          href="{{ $tellUrl }}"
          mj-class="btn-dark"
          font-size="13px"
          border-radius="4px"
          align="center"
        >Tell your story</mj-button>
      </mj-column>
      <mj-column width="33%">
        <mj-button
          href="{{ $giveUrl }}"
          mj-class="btn-success"
          font-size="13px"
          border-radius="4px"
          align="center"
        >Give something</mj-button>
      </mj-column>
      <mj-column width="33%">
        <mj-button
          href="{{ $findUrl }}"
          mj-class="btn-success"
          font-size="13px"
          border-radius="4px"
          align="center"
        >Find something</mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', [
        'email'          => $email,
        'unsubscribeUrl' => $unsubscribeUrl,
        'settingsUrl'    => $settingsUrl,
    ])

  </mj-body>
</mjml>
