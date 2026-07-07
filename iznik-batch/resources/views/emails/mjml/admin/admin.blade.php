<mjml>
    @include('emails.mjml.partials.head', ['preview' => $adminSubject])
    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="18px" font-weight="bold" color="#333333" padding-bottom="10px">
                    {{ $adminSubject }}
                </mj-text>
                <mj-text font-size="14px" color="#333333" line-height="1.6">
                    {!! nl2br(e($adminText)) !!}
                </mj-text>
            </mj-column>
        </mj-section>

        @if($ctaLink && $ctaText)
        <mj-section background-color="#ffffff" padding="10px 20px 20px">
            <mj-column>
                <mj-button mj-class="btn-success" href="{{ $ctaLink }}" font-size="16px" padding="10px 0">
                    {{ $ctaText }}
                </mj-button>
            </mj-column>
        </mj-section>
        @endif

        @if(isset($volunteers) && count($volunteers) > 0)
        <mj-section background-color="#f8f9fa" padding="15px 20px 10px">
            <mj-column>
                <mj-text font-size="13px" color="#4a5568" font-style="italic" line-height="1.5" padding-bottom="10px">
                    @if(count($volunteers) === 1)
                        Your local volunteer is:
                    @else
                        Your local volunteers are:
                    @endif
                </mj-text>
                <mj-text padding="0 25px 5px" line-height="2.4">
                    @foreach(array_slice($volunteers, 0, 7) as $volunteer)
                    {{-- Inline pill: avatar + name wrapped in a rounded background --}}
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

        @if($groupName)
        <mj-section background-color="#f8f9fa" padding="5px 20px 15px">
            <mj-column>
                <mj-text font-size="12px" color="#666666" line-height="1.5">
                    This message was sent to members of <strong>{{ $groupName }}</strong> on {{ config('freegle.branding.name') }}.
                    @if($modsEmail)
                    <br/>You can contact your group volunteers at <a href="mailto:{{ $modsEmail }}" style="color: #338808;">{{ $modsEmail }}</a>.
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- TODO: V1 renders group sponsorship logos here from the groups_sponsorship table.
             Implement when sponsorship data is available via a Laravel model. --}}

        @if($marketingOptOutUrl)
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text font-size="11px" color="#999999" align="center">
                    Don't want to receive these emails? <a href="{{ $marketingOptOutUrl }}" style="color: #999999; text-decoration: underline;">Click here to opt out</a>
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>
