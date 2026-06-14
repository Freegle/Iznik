{{--
  Re-engagement sequence — stage 2 of 3: the local community as a COLLAGE of
  real people (neighbour avatars + first names) and the things they've shared
  (item photos), instead of abstract stat counters. All public wall data; only
  neighbours who uploaded an avatar are shown, first names only. Degrades to an
  items-only / generic version when faces or photos aren't available.
--}}
<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'See who has been freegling near you'])
  <mj-body background-color="#f4f4f4">

    {{-- Header band with logo --}}
    <mj-section mj-class="bg-success" padding="22px 0">
      <mj-column width="68%" vertical-align="middle">
        <mj-text font-size="22px" font-weight="bold" color="#ffffff" padding="0 25px">
          Your community has been busy 🌱
        </mj-text>
      </mj-column>
      <mj-column width="32%" vertical-align="middle">
        <mj-image width="72px" src="{{ config('freegle.branding.logo_url', 'https://www.ilovefreegle.org/icon.png') }}" alt="Freegle" align="right" padding="0 20px" />
      </mj-column>
    </mj-section>

    {{-- Intro --}}
    <mj-section background-color="#ffffff" padding="28px 0 4px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" padding="0 25px 6px" line-height="1.5">
          Hi {{ $name }},
        </mj-text>
        <mj-text font-size="16px" color="#333333" padding="0 25px" line-height="1.5">
          While you've been away, real people near you have been giving and getting. Here are a few of your neighbours and some of what they've shared:
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Item photos --}}
    @if(!empty($offers))
    <mj-section background-color="#ffffff" padding="16px 12px 4px">
      @foreach(array_slice($offers, 0, 4) as $offer)
      <mj-column width="25%" padding="0 4px">
        <mj-image src="{{ $offer['thumbnail_url'] ?? config('freegle.images.offer_placeholder') }}" href="{{ $offer['url'] }}" alt="{{ $offer['subject'] }}" width="118px" border-radius="8px" padding="0" />
      </mj-column>
      @endforeach
    </mj-section>
    @endif

    {{-- Neighbour avatars + first names --}}
    @if(!empty($faces))
    <mj-section background-color="#ffffff" padding="12px 12px 2px">
      @foreach(array_slice($faces, 0, 4) as $face)
      <mj-column width="25%" padding="0 4px">
        <mj-image src="{{ $face['avatar'] }}" alt="{{ $face['name'] }}" width="78px" border-radius="50%" padding="0" />
        <mj-text align="center" font-size="13px" font-weight="bold" color="#338808" padding="7px 2px 0">
          {{ $face['name'] }}
        </mj-text>
      </mj-column>
      @endforeach
    </mj-section>

    {{-- Names sentence --}}
    <mj-section background-color="#ffffff" padding="10px 0 2px">
      <mj-column>
        @php $shownNames = array_map(fn ($f) => $f['name'], array_slice($faces, 0, 3)); @endphp
        <mj-text font-size="16px" color="#333333" align="center" padding="6px 25px" line-height="1.5">
          <strong>{{ implode(', ', $shownNames) }}</strong> and lots of other neighbours near you have been freegling this month.
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- Soft, qualitative impact (no counters) --}}
    <mj-section background-color="#ffffff" padding="2px 0 6px">
      <mj-column>
        <mj-text font-size="15px" color="#338808" font-weight="bold" align="center" padding="4px 25px" line-height="1.5">
          Together they're keeping good stuff out of landfill — one freegle at a time.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Primary CTA --}}
    <mj-section background-color="#ffffff" padding="18px 0 6px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $findUrl }}" border-radius="6px" font-size="17px" font-weight="bold" inner-padding="15px 34px">
          Join back in
        </mj-button>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 0 26px">
      <mj-column>
        <mj-text font-size="14px" color="#555555" align="center" line-height="1.5">
          It's free, local, and it all adds up. <a href="{{ $giveUrl }}" style="color:#338808;font-weight:bold;text-decoration:none;">Offer something →</a>
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', [
      'email'          => $email,
      'settingsUrl'    => $settingsUrl,
      'unsubscribeUrl' => $unsubscribeUrl,
    ])

  </mj-body>
</mjml>
