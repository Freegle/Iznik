{{--
  First-week onboarding — one tip a day (day {{ $day ?? 1 }} of {{ $totalDays ?? 5 }}).
  Warm, short, one clear call to action, signed off by a real local volunteer
  where we have one. Shared template driven by ReengageContentService::tip().
--}}
<mjml>
  @include('emails.mjml.partials.head', ['preview' => $preheader ?? ''])
  <mj-body background-color="#f4f4f4">

    {{-- Header band: logo + "Getting started · Day N of 5" --}}
    <mj-section mj-class="bg-success" padding="20px 0">
      <mj-column width="70%" vertical-align="middle">
        <mj-text font-size="13px" color="#d7f0c4" padding="0 25px 2px" text-transform="uppercase" letter-spacing="1px">
          Getting started &middot; Day {{ $day }} of {{ $totalDays }}
        </mj-text>
        <mj-text font-size="22px" font-weight="bold" color="#ffffff" padding="0 25px">
          {{ $heading }}
        </mj-text>
      </mj-column>
      <mj-column width="30%" vertical-align="middle">
        <mj-image width="64px" src="{{ config('freegle.branding.logo_url', 'https://www.ilovefreegle.org/icon.png') }}" alt="Freegle" align="right" padding="0 20px" />
      </mj-column>
    </mj-section>

    {{-- Progress dots --}}
    <mj-section background-color="#ffffff" padding="16px 0 0">
      <mj-column>
        <mj-text align="center" font-size="16px" letter-spacing="3px" color="#cccccc" padding="0">
          @for($i = 1; $i <= $totalDays; $i++){!! $i <= $day ? '<span style="color:#338808;">&bull;</span>' : '&bull;' !!}@endfor
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Intro + body --}}
    <mj-section background-color="#ffffff" padding="18px 0 6px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" padding="0 25px 10px" line-height="1.5">
          Hi {{ $name }},
        </mj-text>
        @if(!empty($intro))
        <mj-text font-size="16px" color="#333333" padding="0 25px 12px" line-height="1.5">
          {{ $intro }}
        </mj-text>
        @endif
        @foreach(($body ?? []) as $para)
        <mj-text font-size="16px" color="#333333" padding="0 25px 12px" line-height="1.5">
          {!! $para !!}
        </mj-text>
        @endforeach
      </mj-column>
    </mj-section>

    {{-- Optional highlight box --}}
    @if(!empty($highlight))
    <mj-section background-color="#ffffff" padding="0 12px 6px">
      <mj-column mj-class="bg-green-light" border-radius="10px" padding="16px 20px">
        <mj-text font-size="15px" font-weight="bold" color="#1d6607" padding="0 0 8px">
          {{ $highlight['title'] }}
        </mj-text>
        @foreach($highlight['items'] as $item)
        <mj-text font-size="15px" color="#333333" padding="0 0 6px" line-height="1.4">
          <span style="color:#338808;font-weight:bold;">&#10003;</span>&nbsp; {!! $item !!}
        </mj-text>
        @endforeach
      </mj-column>
    </mj-section>
    @endif

    {{-- Primary CTA --}}
    <mj-section background-color="#ffffff" padding="18px 0 6px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $ctaUrl }}" border-radius="6px" font-size="17px" font-weight="bold" inner-padding="15px 34px">
          {{ $ctaLabel }}
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Volunteer sign-off (real local volunteer, or the Freegle team) --}}
    <mj-section background-color="#ffffff" padding="10px 0 26px">
      <mj-column>
        @if(!empty($volunteerName))
        <mj-text font-size="15px" color="#555555" align="center" line-height="1.5" padding="0 25px">
          Happy freegling,<br/>
          <strong>{{ $volunteerName }}</strong><br/>
          <span style="color:#888888;">Your local Freegle volunteer{{ !empty($volunteerGroup) ? ', ' . $volunteerGroup : '' }}</span>
        </mj-text>
        @else
        <mj-text font-size="15px" color="#555555" align="center" line-height="1.5" padding="0 25px">
          Happy freegling,<br/>
          <strong>The Freegle team</strong>
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    @if(!empty($trackingPixelMjml))<mj-section padding="0"><mj-column>{!! $trackingPixelMjml !!}</mj-column></mj-section>@endif

    @include('emails.mjml.partials.footer', [
      'email'          => $email,
      'settingsUrl'    => $settingsUrl,
      'unsubscribeUrl' => $unsubscribeUrl,
    ])

  </mj-body>
</mjml>
