{{--
  Re-engagement sequence — stage 2 of 3: "your community's impact".
  Localised collective impact (items rehomed, weight saved from landfill) is a
  strong belonging/mission lever for charity win-back. One clear primary CTA.
--}}
<mjml>
  @include('emails.mjml.partials.head', ['preview' => $hasImpact ? "Your community saved " . number_format($impactWeightKg) . "kg from landfill recently" : 'See the difference your local community is making'])
  <mj-body background-color="#f4f4f4">

    {{-- Header band with logo --}}
    <mj-section mj-class="bg-success" padding="22px 0">
      <mj-column width="68%" vertical-align="middle">
        <mj-text font-size="22px" font-weight="bold" color="#ffffff" padding="0 25px">
          Look what your community's been up to 🌱
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
          While you've been away, freeglers near {{ $areaLabel }} have kept perfectly good things out of the bin and in the hands of people who needed them.
        </mj-text>
      </mj-column>
    </mj-section>

    @if($hasImpact)
    {{-- Impact stat badges --}}
    <mj-section background-color="#ffffff" padding="14px 12px 6px">
      <mj-column width="50%" background-color="#f0f7e6" border-radius="12px" padding="18px 8px">
        <mj-text align="center" font-size="34px" font-weight="bold" color="#338808" padding="0">
          {{ number_format($impactOutcomes) }}
        </mj-text>
        <mj-text align="center" font-size="13px" color="#555555" padding="4px 6px 0" line-height="1.4">
          things given a new home in the last month
        </mj-text>
      </mj-column>
      <mj-column width="50%" background-color="#e6f4f8" border-radius="12px" padding="18px 8px">
        <mj-text align="center" font-size="34px" font-weight="bold" color="#00A1CB" padding="0">
          {{ number_format($impactWeightKg) }}kg
        </mj-text>
        <mj-text align="center" font-size="13px" color="#555555" padding="4px 6px 0" line-height="1.4">
          kept out of landfill near you
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="2px 0 6px">
      <mj-column>
        <mj-text align="center" font-size="13px" color="#888888" padding="0 25px">
          across {{ $impactGroups }} local {{ \Illuminate\Support\Str::plural('group', $impactGroups) }} you're part of
        </mj-text>
      </mj-column>
    </mj-section>
    @else
    <mj-section background-color="#ffffff" padding="10px 0 6px">
      <mj-column>
        <mj-text align="center" font-size="17px" color="#338808" font-weight="bold" padding="6px 25px" line-height="1.5">
          Every day, your local Freegle groups quietly keep usable things out of the bin — one freegle at a time.
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- A couple of live nearby items, if we have them --}}
    @if(!empty($offers))
    <mj-section background-color="#ffffff" padding="14px 0 2px">
      <mj-column>
        <mj-text font-size="15px" font-weight="bold" color="#333333" align="center" padding="0 25px">
          Up for grabs near you right now
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="6px 12px 4px">
      @foreach(array_slice($offers, 0, 3) as $offer)
      <mj-column width="33%" padding="0 4px">
        <mj-image src="{{ $offer['thumbnail_url'] ?? config('freegle.images.offer_placeholder') }}" href="{{ $offer['url'] }}" alt="{{ $offer['subject'] }}" width="120px" border-radius="8px" padding="0" />
        <mj-text font-size="12px" color="#338808" align="center" font-weight="bold" padding="6px 4px 0" line-height="1.3">
          {{ $offer['subject'] }}
        </mj-text>
      </mj-column>
      @endforeach
    </mj-section>
    @endif

    {{-- Primary CTA --}}
    <mj-section background-color="#ffffff" padding="20px 0 6px">
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
