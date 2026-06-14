{{--
  Re-engagement sequence — stage 1 of 3: "what's been freegled near you".
  Leads with concrete local activity (research: this beats generic "we miss
  you" guilt for community/charity win-back). One clear primary CTA.
--}}
<mjml>
  @include('emails.mjml.partials.head', ['preview' => $offerCount > 0 ? "$offerCount free items offered near " . $areaLabel . " this week" : 'See what neighbours are giving away near you'])
  <mj-body background-color="#f4f4f4">

    {{-- Header band with logo --}}
    <mj-section mj-class="bg-success" padding="22px 0">
      <mj-column width="68%" vertical-align="middle">
        <mj-text font-size="22px" font-weight="bold" color="#ffffff" padding="0 25px">
          Still freegling near {{ $areaLabel }}? 👋
        </mj-text>
      </mj-column>
      <mj-column width="32%" vertical-align="middle">
        <mj-image width="72px" src="{{ config('freegle.branding.logo_url', 'https://www.ilovefreegle.org/icon.png') }}" alt="Freegle" align="right" padding="0 20px" />
      </mj-column>
    </mj-section>

    {{-- Intro --}}
    <mj-section background-color="#ffffff" padding="28px 0 6px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" padding="0 25px 6px" line-height="1.5">
          Hi {{ $name }},
        </mj-text>
        @if($hasLocation && $offerCount > 0)
        <mj-text font-size="16px" color="#333333" padding="0 25px" line-height="1.5">
          It's been a little while! Your neighbours have been busy — <strong>{{ number_format($offerCount) }} free {{ \Illuminate\Support\Str::plural('item', $offerCount) }}</strong> have been offered near {{ $areaLabel }} in the last week alone. Here are a few that are up for grabs right now:
        </mj-text>
        @else
        <mj-text font-size="16px" color="#333333" padding="0 25px" line-height="1.5">
          It's been a little while! People near you are still giving away good stuff every day. Here are a few that are up for grabs right now:
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    {{-- Nearby item cards, two per row --}}
    @foreach(array_chunk($offers, 2) as $row)
    <mj-section background-color="#ffffff" padding="8px 12px">
      @foreach($row as $offer)
      <mj-column width="50%" background-color="#f7f7f5" border-radius="10px" padding="0">
        <mj-image src="{{ $offer['thumbnail_url'] ?? config('freegle.images.offer_placeholder') }}" href="{{ $offer['url'] }}" alt="{{ $offer['subject'] }}" width="150px" border-radius="10px 10px 0 0" padding="0" />
        <mj-text font-size="14px" font-weight="bold" color="#338808" align="center" padding="10px 10px 14px">
          {{ $offer['subject'] }}
        </mj-text>
      </mj-column>
      @endforeach
      @if(count($row) === 1)
      <mj-column width="50%"></mj-column>
      @endif
    </mj-section>
    @endforeach

    {{-- Primary CTA --}}
    <mj-section background-color="#ffffff" padding="18px 0 6px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $findUrl }}" border-radius="6px" font-size="17px" font-weight="bold" inner-padding="15px 34px">
          See what's near you
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Secondary action --}}
    <mj-section background-color="#ffffff" padding="0 0 26px">
      <mj-column>
        <mj-text font-size="14px" color="#555555" align="center" line-height="1.5">
          Got something to clear out? <a href="{{ $giveUrl }}" style="color:#338808;font-weight:bold;text-decoration:none;">Give it away for free →</a>
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
