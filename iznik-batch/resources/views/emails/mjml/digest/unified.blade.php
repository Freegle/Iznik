<mjml>
    @if($mode === 'immediate')
    @php $post = $posts->first(); $isOffer = $post['type'] === 'Offer'; $accentColor = $isOffer ? '#3c763d' : '#2196A6'; @endphp
    @include('emails.mjml.partials.head', ['preview' => $post['subject']])
    @else
    @include('emails.mjml.partials.head', ['preview' => $postCount . ' new post' . ($postCount === 1 ? '' : 's') . ' near you'])
    @endif

    <mj-body background-color="#f0f0f0">
        @php
            $offerColor = '#3c763d';
            $wantedColor = '#2196A6';
        @endphp

        @if($mode === 'immediate')
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- IMMEDIATE MODE: single-post card matching browse page style    --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}

        {{-- Card: image left + content right (matches browse page layout) --}}
        <mj-section background-color="#ffffff" padding="0" border-radius="4px">
            {{-- Image column --}}
            <mj-column width="38%" padding="0" vertical-align="top">
                <mj-image
                    href="{{ $post['messageUrl'] }}"
                    src="{{ $post['trackedImageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    padding="0"
                    fluid-on-mobile="true"
                    container-background-color="#e8e8e8"
                />
            </mj-column>
            {{-- Content column --}}
            <mj-column width="62%" padding="16px 20px 12px 16px" vertical-align="top">
                {{-- OFFER / WANTED pill --}}
                <mj-text padding="0 0 8px 0" font-size="13px">
                    <span style="display: inline-block; background-color: {{ $accentColor }}; color: #ffffff; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 3px; letter-spacing: 0.3px;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                </mj-text>
                {{-- Title --}}
                <mj-text padding="0 0 4px 0" font-size="18px" font-weight="700" color="#212529" line-height="1.25">
                    <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none;">{{ $post['itemName'] }}</a>
                </mj-text>
                {{-- Location --}}
                @if($post['locationName'])
                <mj-text padding="0 0 8px 0" font-size="13px" color="#666666">
                    {{ $post['locationName'] }}
                </mj-text>
                @endif
                {{-- Description snippet --}}
                @if($post['messageText'])
                <mj-text padding="0 0 12px 0" font-size="13px" color="#555555" line-height="1.5">
                    {{ \Illuminate\Support\Str::limit($post['messageText'], 120, '…') }}
                </mj-text>
                @endif
                {{-- Distance + time row --}}
                <mj-text padding="0" font-size="12px" color="#888888">
                    @if($post['distanceText'])
                    <span style="margin-right: 12px;">&#x1F4CD; {{ $post['distanceText'] }}</span>
                    @endif
                    <span>&#x1F552; {{ $post['arrivalFormatted'] }}</span>
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Full body text --}}
        @if($post['messageText'])
        <mj-section background-color="#ffffff" padding="0 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" border-width="1px" padding="0" />
                <mj-text font-size="15px" color="#333333" line-height="1.65" padding="16px 0">
                    {{ $post['messageText'] }}
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Posted by + reply --}}
        <mj-section background-color="#ffffff" padding="0 20px 20px">
            <mj-column>
                {{-- Posted by --}}
                <mj-text font-size="13px" color="#888888" padding="0 0 16px 0">
                    <img src="{{ $post['posterAvatarUrl'] }}" alt="" width="22" height="22" style="display: inline-block; width: 22px; height: 22px; border-radius: 50%; vertical-align: middle; margin-right: 6px;" />
                    Posted by <strong style="color: #555555;">{{ \Illuminate\Support\Str::limit($post['posterName'], 40) }}</strong>
                </mj-text>
                {{-- Primary CTA --}}
                <mj-button
                    href="{{ $post['messageUrl'] }}"
                    background-color="{{ $accentColor }}"
                    color="#ffffff"
                    font-size="16px"
                    font-weight="600"
                    inner-padding="13px 0"
                    border-radius="5px"
                    width="100%"
                    align="center"
                    padding="0 0 10px 0"
                >
                    Reply
                </mj-button>
                {{-- Secondary actions --}}
                <mj-text align="center" font-size="13px" color="#888888" padding="0">
                    <a href="{{ $browseUrl }}" style="color: #555555; text-decoration: none;">Browse other posts</a>
                    &nbsp;&middot;&nbsp;
                    <a href="{{ $userSite }}/offer" style="color: #555555; text-decoration: none;">Post something</a>
                </mj-text>
            </mj-column>
        </mj-section>

        @else
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- DAILY MODE: multi-post digest with thumbnail nav               --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}

        <mj-section mj-class="bg-success" padding="12px 20px">
            <mj-column width="20%" vertical-align="middle">
                <mj-image
                    width="50px"
                    src="{{ config('freegle.branding.logo_url') }}"
                    alt="Freegle"
                    align="left"
                    padding="0"
                />
            </mj-column>
            <mj-column width="80%" vertical-align="middle">
                <mj-text padding="0" line-height="1">
                    @php $maxThumbItems = 8; @endphp
                    <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse: collapse;">
                        <tr>
                            @foreach(collect($posts)->take($maxThumbItems) as $thumbPost)
                            @php $thumbIsOffer = $thumbPost['type'] === 'Offer'; @endphp
                            <td style="padding: 0 3px 0 0; vertical-align: middle;">
                                <a href="#msg-{{ $thumbPost['message']->id }}" style="display: block; line-height: 0;">
                                    <img src="{{ $thumbPost['trackedImageUrl'] }}" alt="{{ $thumbPost['itemName'] }}" width="44" height="44" style="display: block; width: 44px; height: 44px; object-fit: cover; border-radius: 4px; border: 2px solid {{ $thumbIsOffer ? '#6ab04c' : '#74b9ff' }};" />
                                </a>
                            </td>
                            @endforeach
                            @if(count($posts) > $maxThumbItems)
                            <td style="vertical-align: middle; padding-left: 4px;">
                                <a href="{{ $browseUrl }}" style="color: #ffffff; text-decoration: none; font-size: 12px; font-weight: bold; line-height: 1.3;">+{{ count($posts) - $maxThumbItems }}<br/>more</a>
                            </td>
                            @endif
                        </tr>
                    </table>
                </mj-text>
            </mj-column>
        </mj-section>

        @foreach($posts as $index => $post)
        @php $isOffer = $post['type'] === 'Offer'; @endphp

        @if($index > 0)
        <mj-section padding="0" background-color="#ffffff">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 20px" />
            </mj-column>
        </mj-section>
        @endif

        <mj-section background-color="#ffffff" padding="0">
            <mj-column width="38%" padding="0" vertical-align="top">
                <mj-image
                    href="{{ $post['messageUrl'] }}"
                    src="{{ $post['trackedImageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    padding="0"
                    fluid-on-mobile="true"
                />
            </mj-column>
            <mj-column width="62%" padding="12px 16px 12px 12px" vertical-align="top">
                <mj-text padding="0 0 6px 0" font-size="13px">
                    <span style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 3px;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                </mj-text>
                <mj-text padding="0 0 3px 0" font-size="16px" font-weight="700" color="#212529" line-height="1.25">
                    <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none;">{{ $post['itemName'] }}</a>
                </mj-text>
                @if($post['locationName'])
                <mj-text padding="0 0 6px 0" font-size="12px" color="#666666">{{ $post['locationName'] }}</mj-text>
                @endif
                @if($post['messageText'])
                <mj-text padding="0 0 8px 0" font-size="12px" color="#555555" line-height="1.45">{{ \Illuminate\Support\Str::limit($post['messageText'], 80, '…') }}</mj-text>
                @endif
                <mj-text padding="0" font-size="12px" color="#888888">
                    @if($post['distanceText'])<span style="margin-right: 10px;">&#x1F4CD; {{ $post['distanceText'] }}</span>@endif
                    <span>&#x1F552; {{ $post['arrivalFormatted'] }}</span>
                </mj-text>
            </mj-column>
        </mj-section>
        @endforeach

        <mj-section background-color="#ffffff" padding="16px 20px 20px 20px">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 0 16px 0" />
                <mj-button href="{{ $browseUrl }}" mj-class="btn-success" font-size="16px" inner-padding="12px 40px" border-radius="4px">
                    Browse All Posts
                </mj-button>
            </mj-column>
        </mj-section>

        @endif

        {{-- Sponsors (both modes) --}}
        @if(isset($sponsors) && $sponsors->isNotEmpty())
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" padding-bottom="5px" />
                <mj-text font-size="12px" color="#888888" font-style="italic" padding-bottom="5px">Sponsored by:</mj-text>
            </mj-column>
        </mj-section>
        @foreach($sponsors as $sponsor)
        <mj-section background-color="#ffffff" padding="0 20px 10px">
            <mj-column width="80px" vertical-align="middle">
                @if($sponsor->imageurl)
                <mj-image width="60px" src="{{ $sponsor->imageurl }}" alt="{{ $sponsor->name }}" href="{{ $sponsor->linkurl }}" border-radius="5px" />
                @endif
            </mj-column>
            <mj-column vertical-align="middle">
                <mj-text font-size="13px">
                    @if($sponsor->linkurl)<a href="{{ $sponsor->linkurl }}" style="color: #338808; text-decoration: none; font-weight: bold;">{{ $sponsor->name }}</a>@else<strong>{{ $sponsor->name }}</strong>@endif
                    @if($sponsor->tagline)<br /><span style="font-size: 11px; color: #666;">{{ $sponsor->tagline }}</span>@endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endforeach
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl, 'unsubscribeUrl' => $unsubscribeUrl])

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>