{{-- CROSS-REFERENCE: A pre-compiled HTML preview of this template exists at:
     iznik-nuxt3/modtools/components/ModAdminPreviewLittleFreeShop2026.vue
     If you modify this MJML template, you must regenerate that preview to match. --}}
<mjml>
    @include('emails.mjml.partials.head', ['preview' => $adminSubject])
    <mj-body background-color="#ffffff">

        {{-- Hero banner — photo with heading overlaid at bottom-left --}}
        <mj-section background-url="{{ $heroImageUrl }}" background-size="cover" background-position="center" padding="0">
            <mj-column>
                <mj-text font-size="28px" font-weight="bold" color="#ffffff" align="left" line-height="1.3" font-family="Helvetica, Arial, sans-serif" padding="10px 25px 228px 0">
                    <span style="background-color: rgba(0,0,0,0.5); padding: 6px 12px; display: inline;">{{ $heroHeading ?? $adminSubject }}</span>
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- First paragraph --}}
        <mj-section background-color="#ffffff" padding="20px 25px 10px">
            <mj-column>
                <mj-text font-size="15px" color="#333333" line-height="1.7" font-family="Helvetica, Arial, sans-serif">
                    <p>Imagine if there was one of these near you. A place on your street where you could drop off good stuff you don't need any more, and pick up things other people have left. For free.</p>
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Early donate button — above the fold --}}
        @if($ctaLink && $ctaText)
        <mj-section background-color="#ffffff" padding="5px 25px 10px">
            <mj-column>
                <mj-button href="{{ $ctaLink }}" background-color="#338808" color="#ffffff" font-size="18px" font-weight="bold" border-radius="6px" inner-padding="12px 35px" align="center" font-family="Helvetica, Arial, sans-serif">
                    {{ $ctaText }}
                </mj-button>
            </mj-column>
        </mj-section>
        @endif

        {{-- Remaining body text --}}
        <mj-section background-color="#ffffff" padding="10px 25px 10px">
            <mj-column>
                <mj-text font-size="15px" color="#333333" line-height="1.7" font-family="Helvetica, Arial, sans-serif">
                    <p>That's the <strong>Little Free Shop</strong> &mdash; a community reuse hub right where people live.</p>
                    <p>We already know a physical place to freegle works. Our Free Shop in Brighton Market has been a huge hit. Local authorities across the country want to try it. Now we need <strong>&pound;5,000</strong> to get us to the stage where we can run run a pilot of how we could deliver a Little Free Shop in lots of neighbourhoods across the UK.</p>
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Call to action heading --}}
        <mj-section background-color="#ffffff" padding="5px 25px 5px">
            <mj-column>
                <mj-text font-size="18px" font-weight="bold" color="#333333" align="center" font-family="Helvetica, Arial, sans-serif">
                    Can you chip in to help make it happen?
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Donate button --}}
        @if($ctaLink && $ctaText)
        <mj-section background-color="#ffffff" padding="10px 25px 5px">
            <mj-column>
                <mj-button href="{{ $ctaLink }}" background-color="#338808" color="#ffffff" font-size="18px" font-weight="bold" border-radius="6px" inner-padding="12px 35px" align="center" font-family="Helvetica, Arial, sans-serif">
                    {{ $ctaText }}
                </mj-button>
            </mj-column>
        </mj-section>
        @endif

        {{-- Target --}}
        @if(isset($targetAmount) && $targetAmount)
        <mj-section background-color="#ffffff" padding="0 25px 10px">
            <mj-column>
                <mj-text font-size="13px" color="#888888" align="center" font-family="Helvetica, Arial, sans-serif">
                    Target: <span style="font-weight:bold; color:#333333;">{{ $targetAmount }}</span> &mdash; every pound gets us closer.
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Divider --}}
        <mj-section background-color="#ffffff" padding="0 25px">
            <mj-column>
                <mj-divider border-color="#e0e0e0" border-width="1px" />
            </mj-column>
        </mj-section>

        {{-- Why it matters — green ticks, bold labels --}}
        @if(isset($bulletPoints) && count($bulletPoints) > 0)
        <mj-section background-color="#ffffff" padding="15px 25px 10px">
            <mj-column>
                <mj-text font-size="16px" font-weight="bold" color="#333333" padding-bottom="10px" font-family="Helvetica, Arial, sans-serif">
                    Why it matters:
                </mj-text>
                <mj-text font-size="14px" color="#333333" line-height="2.0" font-family="Helvetica, Arial, sans-serif">
                    @foreach($bulletPoints as $point)
                    ✅&nbsp;&nbsp;<strong>{{ Str::before($point, ' — ') }}</strong> — {{ Str::after($point, ' — ') }}<br/>
                    @endforeach
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Sign-off from board chair --}}
        <mj-section background-color="#ffffff" padding="15px 25px 5px">
            <mj-column>
                <mj-text font-size="14px" color="#4a5568" line-height="1.5" font-family="Helvetica, Arial, sans-serif" align="left">
                    <p>Thank you!</p>
                    <p>
                        Neil Morris<br/>
                        Freegle Board Chair
                    </p>
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Spending plan — muted, small --}}
        @if(isset($spendingPlan) && $spendingPlan)
        <mj-section background-color="#ffffff" padding="0 25px 15px">
            <mj-column>
                <mj-text font-size="12px" color="#999999" line-height="1.6" font-family="Helvetica, Arial, sans-serif">
                    {!! $spendingPlan !!}
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Local volunteers --}}
        @if(isset($volunteers) && count($volunteers) > 0)
        <mj-section background-color="#f8f9fa" padding="5px 25px 10px">
            <mj-column>
                <mj-text font-size="13px" color="#4a5568" font-style="italic" line-height="1.5" padding-bottom="10px" font-family="Helvetica, Arial, sans-serif">
                    @if(count($volunteers) === 1)
                        Your local volunteer is:
                    @else
                        Your local volunteers are:
                    @endif
                </mj-text>
                <mj-text padding="0 25px 5px" line-height="2.4">
                    @foreach(array_slice($volunteers, 0, 7) as $volunteer)
                    <span style="display: inline-block; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 3px 12px 3px 3px; margin: 0 6px 6px 0; vertical-align: middle;">
                        <img
                            src="{{ $volunteer['profileurl'] ?? '' }}"
                            alt="{{ $volunteer['firstname'] }}"
                            width="28"
                            height="28"
                            style="border-radius: 14px; vertical-align: middle; display: inline-block; width: 28px; height: 28px; object-fit: cover;"
                        />
                        <span style="font-size: 13px; color: #4a5568; vertical-align: middle; padding-left: 6px;">{{ $volunteer['firstname'] }}</span>
                    </span>
                    @endforeach
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Footer with opt-out, unsubscribe, and charity info --}}
        <mj-section background-color="#f5f5f5" padding="20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666" align="center" line-height="1.6" font-family="Helvetica, Arial, sans-serif">
                    @if($marketingOptOutUrl)
                    Don't want to receive these emails? <a href="{{ $marketingOptOutUrl }}" style="color: #338808;">Click here to opt out</a>
                    <br/>
                    @endif
                    <a href="{{ $settingsUrl ?? config('freegle.sites.user') . '/settings' }}" style="color: #338808;">Change your email settings</a> &bull;
                    <a href="{{ $unsubscribeUrl ?? config('freegle.sites.user') . '/unsubscribe' }}" style="color: #338808;">Unsubscribe</a>
                </mj-text>
                <mj-divider border-color="#ddd" border-width="1px" padding="15px 40px"></mj-divider>
                <mj-text font-size="11px" color="#666666" align="center" line-height="1.5" font-family="Helvetica, Arial, sans-serif">
                    {{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
                    Registered address: {{ config('freegle.branding.registered_address') }}
                </mj-text>
            </mj-column>
        </mj-section>

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>
