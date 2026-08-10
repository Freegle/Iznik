{{--
    Shared donation ask block.

    Every donate button in every email used to link straight to the PayPal
    shortlink, so email donors could only pay by PayPal. These buttons go to
    our own Stripe donate page instead, where Apple Pay / Google Pay / Link /
    PayPal / card all show as one-tap buttons. See DonateLinkService.

    Params:
      $donateLinks    list<array{amount:int,label:string,url:string}> — amount buttons.
      $donateUrl      string — donate link with no amount ("another amount").
      $donateMarksUrl string — payment-mark strip image.
      $compact        bool   — single button instead of the amount row (default false).
      $donateHeading  string|null — heading above the buttons (full mode only).
      $donateBlurb    string|null — line under the heading (full mode only).
      $donateLabel    string|null — label for the single compact button.
      $accentColor    string|null — button colour (defaults to Freegle green).
      $bgColor        string|null — section background.

    The marks are an image, so the alt text has to carry the same message for
    the many clients that block images by default.
--}}
@php
    $dAccent = $accentColor ?? '#338808';
    $dBg = $bgColor ?? '#f9f9f9';
    $dCompact = $compact ?? false;
    $dMarksAlt = 'Apple Pay, Google Pay, PayPal or card';
    // Defaulted rather than required: the templates that include this are also
    // rendered directly in tests and previews, and a missing variable must not
    // fatal a whole email. Without a user these links carry no auto-login key.
    $dSvc = app(\App\Services\DonateLinkService::class);
    $dLinks = $donateLinks ?? $dSvc->amountLinks();
    $dUrl = $donateUrl ?? $dSvc->url();
    $dMarks = $donateMarksUrl ?? config('freegle.images.paymethods');
@endphp

@if($dCompact)
<mj-section background-color="{{ $dBg }}" padding="10px 20px 12px 20px">
    <mj-column>
        <mj-button href="{{ $dUrl }}" background-color="{{ $dAccent }}" color="#ffffff" font-size="14px" font-weight="600" inner-padding="10px 22px" border-radius="5px" align="center">
            {{ $donateLabel ?? 'Donate' }}
        </mj-button>
        <mj-image src="{{ $dMarks }}" alt="{{ $dMarksAlt }}" title="{{ $dMarksAlt }}" width="200px" align="center" padding="8px 0 0 0" href="{{ $dUrl }}" />
        <mj-text font-size="11px" color="#888888" align="center" line-height="1.4" padding="4px 0 0 0">
            One tap {{ '—' }} no card details to type.
        </mj-text>
    </mj-column>
</mj-section>
@else
<mj-section background-color="{{ $dBg }}" padding="18px 20px 8px 20px">
    <mj-column>
        @if(!empty($donateHeading))
        <mj-text font-size="17px" font-weight="bold" align="center" color="#333333" padding="0 0 6px 0">
            {{ $donateHeading }}
        </mj-text>
        @endif
        @if(!empty($donateBlurb))
        <mj-text font-size="14px" align="center" color="#555555" line-height="1.5" padding="0 0 4px 0">
            {!! $donateBlurb !!}
        </mj-text>
        @endif
    </mj-column>
</mj-section>

{{-- mj-group keeps the amounts on one row on phones; mj-columns stack < 480px. --}}
<mj-section background-color="{{ $dBg }}" padding="4px 12px 0 12px">
    <mj-group>
        @foreach($dLinks as $dLink)
        <mj-column width="{{ round(100 / max(count($dLinks), 1), 4) }}%">
            <mj-button href="{{ $dLink['url'] }}" background-color="{{ $dAccent }}" color="#ffffff" font-size="18px" font-weight="bold" inner-padding="12px 8px" border-radius="5px" width="90%" padding="4px 4px">
                {{ $dLink['label'] }}
            </mj-button>
        </mj-column>
        @endforeach
    </mj-group>
</mj-section>

<mj-section background-color="{{ $dBg }}" padding="4px 20px 16px 20px">
    <mj-column>
        <mj-image src="{{ $dMarks }}" alt="{{ $dMarksAlt }}" title="{{ $dMarksAlt }}" width="220px" align="center" padding="10px 0 0 0" href="{{ $dUrl }}" />
        <mj-text font-size="12px" color="#777777" align="center" line-height="1.5" padding="6px 0 0 0">
            One tap {{ '—' }} no card details to type.
            <a href="{{ $dUrl }}" style="color:#777777;text-decoration:underline;font-weight:normal">Another amount</a>
        </mj-text>
    </mj-column>
</mj-section>
@endif
