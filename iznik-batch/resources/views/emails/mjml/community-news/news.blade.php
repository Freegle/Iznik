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

    {{-- Closing note + CTA --}}
    <mj-section background-color="#f0f0eb" padding="0">
      <mj-column><mj-text padding="6px 0" color="#f0f0eb">&nbsp;</mj-text></mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="22px 0">
      <mj-column>
        <mj-text font-size="14px" color="#555555" line-height="1.7" padding="0 25px 16px" align="center">
          We send this now and then to freeglers who like a bit of local goings-on. Not your cup of tea? No worries &mdash; there&rsquo;s an unsubscribe link just below.
        </mj-text>
        <mj-button href="{{ $findUrl }}" mj-class="btn-success" font-size="14px" border-radius="4px" align="center">
          Give &amp; find things near you
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', [
        'email'          => $email,
        'unsubscribeUrl' => $unsubscribeUrl,
        'settingsUrl'    => $settingsUrl,
    ])

  </mj-body>
</mjml>
