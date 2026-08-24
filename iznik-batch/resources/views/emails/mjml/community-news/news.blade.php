<mjml>
  @include('emails.mjml.partials.head', ['preview' => $preview ?? ''])

  <mj-body background-color="#f0f0eb">

    @include('emails.mjml.partials.header', ['title' => 'Community News'])

    {{-- Intro card --}}
    <mj-section mj-class="bg-green-light" padding="24px 0 20px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" mj-class="text-header" line-height="1.3" padding="0 25px 12px">
          What&rsquo;s on around {{ $areaName }}
        </mj-text>
        <mj-text font-size="15px" color="#333333" line-height="1.7" padding="0 25px 0">
          {!! nl2br(e($intro)) !!}
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- News items --}}
    @foreach ($items as $item)

    {{-- Gap before each item --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="6px 0" color="#f0f0eb">&nbsp;</mj-text></mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="20px 0 18px">
      <mj-column>
        @if (!empty($item['image']))
        <mj-image src="{{ $item['image'] }}" alt="" fluid-on-mobile="true" border-radius="6px" padding="0 25px 12px" />
        @endif
        <mj-text font-size="18px" font-weight="bold" mj-class="text-header" line-height="1.35" padding="0 25px 8px">
          @if (!empty($item['url']))<a href="{{ $item['url'] }}">{{ $item['title'] }}</a>@else{{ $item['title'] }}@endif
        </mj-text>
        <mj-text font-size="15px" color="#333333" line-height="1.7" padding="0 25px 8px">
          {{ $item['blurb'] }}
        </mj-text>
        @if (!empty($item['url']))
        <mj-text font-size="13px" color="#888888" line-height="1.5" padding="0 25px 0">
          <a href="{{ $item['url'] }}">Take a look &rarr;</a>@if (!empty($item['source']))<span style="color:#aaaaaa;"> &middot; {{ $item['source'] }}</span>@endif
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    @endforeach

    @if (!empty($story))
    {{-- A member's story from this area --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="6px 0" color="#f0f0eb">&nbsp;</mj-text></mj-column>
    </mj-section>

    <mj-section mj-class="bg-green-light" padding="20px 0 18px">
      <mj-column>
        <mj-text font-size="13px" font-weight="bold" color="#338808" text-transform="uppercase" letter-spacing="1px" padding="0 25px 6px">
          A freegler near you says&hellip;
        </mj-text>
        <mj-text font-size="18px" font-weight="bold" mj-class="text-header" line-height="1.35" padding="0 25px 8px">
          {{ $story['headline'] }}
        </mj-text>
        <mj-text font-size="15px" color="#333333" line-height="1.7" font-style="italic" padding="0 25px 8px">
          &ldquo;{{ $story['story'] }}&rdquo;
        </mj-text>
        <mj-text font-size="13px" color="#888888" padding="0 25px 0">
          &mdash; {{ $story['name'] }}
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- Closing note + CTA --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="6px 0" color="#f0f0eb">&nbsp;</mj-text></mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="22px 0">
      <mj-column>
        <mj-text font-size="14px" color="#555555" line-height="1.7" padding="0 25px 16px" align="center">
          We send this now and then to freeglers who like a bit of local goings-on. Not your cup of tea? No worries &mdash; just turn off &ldquo;Newsletters &amp; stories&rdquo; in <a href="{{ $settingsUrl }}" style="color:#338808; font-weight:bold;">your email settings</a> and these will stop.
        </mj-text>
        <mj-button href="{{ $askUrl }}" mj-class="btn-success" font-size="14px" border-radius="4px" align="center">
          Give &amp; ask for things near you
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Custom footer, NOT the shared partial: that one carries an "Unsubscribe"
         link which leaves Freegle completely — far too big a hammer for "no more
         round-ups". The only control we offer is the specific setting. --}}
    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text font-size="12px" color="#666666" align="center" line-height="1.6">
          This email was sent to {{ $email }}<br/>
          To stop these, turn off &ldquo;Newsletters &amp; stories&rdquo; in
          <a href="{{ $settingsUrl }}" style="color: #338808; font-weight: bold; text-decoration: none;">your email settings</a>
        </mj-text>
        <mj-divider border-color="#ddd" border-width="1px" padding="15px 40px"></mj-divider>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
          {{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
          Registered address: {{ config('freegle.branding.registered_address') }}
        </mj-text>
        @if(!empty($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
      </mj-column>
    </mj-section>

  </mj-body>
</mjml>
