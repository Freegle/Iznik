{{--
  Re-engagement sequence — stage 3 of 3: "shall we stay in touch?".
  Honest opt-in confirmation + gentle sunset notice. Offers a frequency
  downgrade as an alternative to churn (research: recovers a slice of users
  and is the GDPR-friendly close to a win-back sequence). The primary CTA
  logs the user in, which resets their activity and ends the sequence.
--}}
<mjml>
  @include('emails.mjml.partials.head', ['preview' => "We'll soon pause our emails — tell us if you'd like to stay in touch"])
  <mj-body background-color="#f4f4f4">

    {{-- Header band with logo --}}
    <mj-section mj-class="bg-success" padding="22px 0">
      <mj-column width="68%" vertical-align="middle">
        <mj-text font-size="22px" font-weight="bold" color="#ffffff" padding="0 25px">
          Shall we stay in touch? 💚
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
          We haven't seen you on Freegle for a while, so to keep your inbox tidy we'll soon stop sending you these emails. No hard feelings if it's not for you right now!
        </mj-text>
        <mj-text font-size="16px" color="#333333" padding="12px 25px 0" line-height="1.5">
          If you'd still like to be part of your local community, just choose an option below:
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Option 1: stay — primary --}}
    <mj-section background-color="#ffffff" padding="18px 0 6px">
      <mj-column>
        <mj-button mj-class="btn-success" href="{{ $findUrl }}" border-radius="6px" font-size="17px" font-weight="bold" inner-padding="15px 34px">
          Keep me freegling
        </mj-button>
        <mj-text font-size="13px" color="#888888" align="center" padding="6px 25px 0">
          Logs you straight in and keeps your emails coming.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Option 2: fewer emails --}}
    <mj-section background-color="#ffffff" padding="14px 0 6px">
      <mj-column>
        <mj-button mj-class="btn-secondary" href="{{ $settingsUrl }}" border-radius="6px" font-size="15px" inner-padding="12px 28px">
          Email me less often
        </mj-button>
        <mj-text font-size="13px" color="#888888" align="center" padding="6px 25px 0">
          Switch to an occasional round-up instead of regular emails.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Local nudge --}}
    @if($hasLocation && $offerCount > 0)
    <mj-section mj-class="bg-green-light" padding="16px 20px" border-radius="10px">
      <mj-column>
        <mj-text font-size="14px" color="#333333" align="center" line-height="1.5">
          Just so you know — <strong>{{ number_format($offerCount) }}</strong> free {{ \Illuminate\Support\Str::plural('item', $offerCount) }} were offered near you this week. There's always something going.
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- Option 3: unsubscribe — quiet, but clearly available --}}
    <mj-section background-color="#ffffff" padding="20px 0 26px">
      <mj-column>
        <mj-text font-size="13px" color="#888888" align="center" line-height="1.5">
          Rather not? <a href="{{ $unsubscribeUrl }}" style="color:#888888;font-weight:bold;text-decoration:underline;">Unsubscribe</a> and we'll stop emailing you.
        </mj-text>
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
