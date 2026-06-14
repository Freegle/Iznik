{{-- Shared "Jobs near you" block (V1 parity — single.mjml/multiple.mjml both
     carry it). Included by BOTH the immediate (single-post) and daily
     (multi-post) branches of unified.blade.php so the two can never drift —
     the daily branch silently lacking this block is exactly the bug this
     partial fixes. Falls back to a standalone "Donating helps too!" button
     when the user has no nearby jobs so the donation CTA is always present.
     Needs: $jobAds, $jobsUrl, $donateUrl, $accentColor (all from the parent
     view scope, inherited via @include). --}}
@if(isset($jobAds) && $jobAds->isNotEmpty())
<mj-section background-color="#F7F6EC" padding="20px 20px 10px 20px" border-top="1px solid #e9ecef">
    <mj-column>
        <mj-text font-size="16px" font-weight="bold" color="#333333" align="center" padding-bottom="10px">
            Jobs near you
        </mj-text>
    </mj-column>
</mj-section>
{{-- Mobile-tight: 12px side padding, 4px vertical row padding, 1.25 line-height
     on the title, and a 44px icon column (40px image + 4px gap to text). --}}
<mj-section background-color="#F7F6EC" padding="5px 12px">
    <mj-column>
        <mj-table cellpadding="0" cellspacing="0" width="100%">
            @foreach($jobAds as $job)
            <tr>
                @if($job->image_url ?? null)
                <td style="width: 44px; padding: 4px 4px 4px 0; vertical-align: middle;">
                    <a href="{{ $job->tracked_url }}">
                        <img src="{{ $job->image_url }}" width="40" height="40" alt="" style="border-radius: 4px; display: block;" />
                    </a>
                </td>
                <td style="padding: 4px 0; vertical-align: middle;">
                @else
                {{-- No image: span both columns so the title fills the row instead of
                     being squeezed into the right column reserved by sibling rows. --}}
                <td colspan="2" style="padding: 4px 0; vertical-align: middle;">
                @endif
                    <a href="{{ $job->tracked_url }}" style="color: #338808; font-weight: bold; text-decoration: none; font-size: 14px; line-height: 1.25;">
                        {{ $job->title }}
                    </a>
                    @if($job->location ?? null)
                    <br/><span style="color: #666666; font-size: 12px; line-height: 1.3;">{{ $job->location }}</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </mj-table>
    </mj-column>
</mj-section>
<mj-section background-color="#F7F6EC" padding="0 20px 10px 20px">
    <mj-column>
        <mj-text font-size="12px" color="#666666" line-height="1.4">
            If you are interested and click, it will raise a little to help keep Freegle running and free to use.
        </mj-text>
    </mj-column>
</mj-section>
<mj-section background-color="#F7F6EC" padding="0 20px 20px 20px">
    <mj-column width="50%">
        <mj-button href="{{ $jobsUrl }}" background-color="{{ $accentColor }}" color="#ffffff" font-size="14px" inner-padding="10px 25px" border-radius="5px" width="90%">
            View more jobs
        </mj-button>
    </mj-column>
    <mj-column width="50%">
        <mj-button href="{{ $donateUrl }}" background-color="{{ $accentColor }}" color="#ffffff" font-size="14px" inner-padding="10px 25px" border-radius="5px" width="90%">
            Donating helps too!
        </mj-button>
    </mj-column>
</mj-section>
@else
<mj-section background-color="#ffffff" padding="0 20px 20px">
    <mj-column>
        <mj-divider border-color="#eeeeee" border-width="1px" padding="0 0 16px 0" />
        <mj-button
            href="{{ $donateUrl ?? 'https://freegle.in/paypal1510' }}"
            background-color="{{ $accentColor }}"
            color="#ffffff"
            font-size="14px"
            font-weight="600"
            inner-padding="10px 25px"
            border-radius="5px"
            align="center"
        >
            Donating helps too!
        </mj-button>
    </mj-column>
</mj-section>
@endif
