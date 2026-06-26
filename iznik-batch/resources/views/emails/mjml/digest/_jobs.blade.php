{{-- Shared "Jobs near you" block (V1 parity — single.mjml/multiple.mjml both
     carry it). Included by BOTH the immediate (single-post) and daily
     (multi-post) branches of unified.blade.php so the two can never drift —
     the daily branch silently lacking this block is exactly the bug this
     partial fixes. Falls back to a standalone "Donating helps too!" button
     when the user has no nearby jobs so the donation CTA is always present.

     Layout: compact spacing, and the job list is split across TWO mj-columns
     so it shows 2-up on desktop (half the height) and stacks to a single
     column on mobile (mj-column's default <480px stack) — no media query
     needed. Needs: $jobAds, $jobsUrl, $donateUrl, $accentColor (from the
     parent view scope, inherited via @include). --}}
@if(isset($jobAds) && $jobAds->isNotEmpty())
@php
    $jobsList = collect($jobAds)->values();
@endphp
<mj-section background-color="#F7F6EC" padding="8px 6px 2px 6px" border-top="1px solid #e9ecef">
    <mj-column>
        <mj-text font-size="15px" font-weight="bold" color="#333333" align="center" padding="0">
            Jobs near you
        </mj-text>
    </mj-column>
</mj-section>
{{-- Single full-width column: one job per row. Was split into two 50% columns
     (2-up on desktop) but that left a visible seam between the two halves when
     they stacked on mobile, so it's a single column now — no seam. --}}
<mj-section background-color="#F7F6EC" padding="2px 6px 0 6px">
    <mj-column padding="0">
        <mj-table cellpadding="0" cellspacing="0" width="100%">
            @foreach($jobsList as $job)
            <tr>
                @if($job->image_url ?? null)
                <td style="width: 44px; padding: 2px 4px 2px 0; vertical-align: top;">
                    <a href="{{ $job->tracked_url }}">
                        <img src="{{ $job->image_url }}" width="40" height="40" alt="" style="border-radius: 4px; display: block;" />
                    </a>
                </td>
                <td style="padding: 2px 0; vertical-align: top;">
                @else
                {{-- No image: span both cells so the title fills the row. --}}
                <td colspan="2" style="padding: 2px 0; vertical-align: top;">
                @endif
                    {{-- Location inline in brackets (smaller, grey) so a long entry
                         flows as one wrapping phrase instead of the title wrapping
                         with the location dangling on its own line. --}}
                    <div style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3;"><a href="{{ $job->tracked_url }}" style="color: #338808; font-weight: bold; text-decoration: none; font-size: 14px;">{{ $job->display_title ?? $job->title }}</a>@if($job->display_location ?? null) <span style="color: #666666; font-size: 12px;">({{ $job->display_location }})</span>@endif</div>
                </td>
            </tr>
            @endforeach
        </mj-table>
    </mj-column>
</mj-section>
<mj-section background-color="#F7F6EC" padding="2px 10px 0 10px">
    <mj-column>
        <mj-text font-size="11px" color="#888888" line-height="1.3" align="center" padding="0">
            If you are interested and click, it will raise a little to help keep Freegle running and free to use.
        </mj-text>
    </mj-column>
</mj-section>
{{-- Small buttons, tight padding above and below. --}}
<mj-section background-color="#F7F6EC" padding="4px 16px 8px 16px">
    {{-- mj-group keeps the two buttons side-by-side on mobile too (mj-columns
         otherwise stack < 480px), saving a line. Short labels so each fits its
         half-width button on a narrow phone. --}}
    <mj-group>
        <mj-column width="50%">
            <mj-button href="{{ $jobsUrl }}" background-color="{{ $accentColor }}" color="#ffffff" font-size="13px" inner-padding="6px 14px" border-radius="5px" width="92%">
                More jobs
            </mj-button>
        </mj-column>
        <mj-column width="50%">
            <mj-button href="{{ $donateUrl }}" background-color="{{ $accentColor }}" color="#ffffff" font-size="13px" inner-padding="6px 14px" border-radius="5px" width="92%">
                Donate
            </mj-button>
        </mj-column>
    </mj-group>
</mj-section>
@else
<mj-section background-color="#ffffff" padding="0 20px 10px">
    <mj-column>
        <mj-divider border-color="#eeeeee" border-width="1px" padding="0 0 10px 0" />
        <mj-button
            href="{{ $donateUrl ?? 'https://freegle.in/paypal1510' }}"
            background-color="{{ $accentColor }}"
            color="#ffffff"
            font-size="13px"
            font-weight="600"
            inner-padding="6px 14px"
            border-radius="5px"
            align="center"
        >
            Donating helps too!
        </mj-button>
    </mj-column>
</mj-section>
@endif
