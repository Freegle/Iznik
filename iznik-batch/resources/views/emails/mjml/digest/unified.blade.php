<mjml>
    @include('emails.mjml.partials.head', ['preview' => $postCount . ' new post' . ($postCount === 1 ? '' : 's') . ' near you'])

    <mj-body background-color="#f4f4f4">
        @php
            $offers = collect($posts)->where('type', 'Offer');
            $wanteds = collect($posts)->where('type', 'Wanted');
            $maxHeaderItems = 3;
            $offerColor = '#3c763d';
            $wantedColor = '#4895DD';
        @endphp

        {{-- Header - Freegle brand green with logo + compact summary --}}
        <mj-section mj-class="bg-success" padding="16px 20px">
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
                <mj-text font-size="13px" color="#ffffff" padding="0" line-height="1.6">
                    @if($offers->isNotEmpty())
                    <span style="display: inline-block; background-color: {{ $offerColor }}; border: 1px solid rgba(255,255,255,0.4); font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 4px; letter-spacing: 0.5px; vertical-align: middle; margin-right: 3px;">OFFER</span>
                    @foreach($offers->take($maxHeaderItems) as $post)<a href="#msg-{{ $post['message']->id }}" style="color: #ffffff; text-decoration: underline;">{{ $post['itemName'] }}</a>@if(!$loop->last), @endif @endforeach @if($offers->count() > $maxHeaderItems) <a href="{{ $browseUrl }}" style="color: #ffffff; text-decoration: underline;">+{{ $offers->count() - $maxHeaderItems }} more</a> @endif
                    @endif
                    @if($offers->isNotEmpty() && $wanteds->isNotEmpty())
                    <br/>
                    @endif
                    @if($wanteds->isNotEmpty())
                    <span style="display: inline-block; background-color: {{ $wantedColor }}; font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 4px; letter-spacing: 0.5px; vertical-align: middle; margin-right: 3px;">WANTED</span>
                    @foreach($wanteds->take($maxHeaderItems) as $post)<a href="#msg-{{ $post['message']->id }}" style="color: #ffffff; text-decoration: underline;">{{ $post['itemName'] }}</a>@if(!$loop->last), @endif @endforeach @if($wanteds->count() > $maxHeaderItems) <a href="{{ $browseUrl }}" style="color: #ffffff; text-decoration: underline;">+{{ $wanteds->count() - $maxHeaderItems }} more</a> @endif
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Post cards --}}
        @foreach($posts as $index => $post)
        @php $isOffer = $post['type'] === 'Offer'; @endphp

        {{-- Card separator --}}
        @if($index > 0)
        <mj-section padding="0" background-color="#ffffff">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 20px" />
            </mj-column>
        </mj-section>
        @endif

        <mj-section background-color="#ffffff" padding="12px 20px">
            <mj-column>
                <mj-text padding="0" font-size="14px" color="#212529">
                    <a id="msg-{{ $post['message']->id }}"></a>
                    <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse: collapse; width: 100%;">
                        {{-- Top row: image (rowspan) + title/description --}}
                        <tr>
                            <td rowspan="2" style="vertical-align: top; width: 120px; height: 120px; padding-right: 16px;">
                                <a href="{{ $post['messageUrl'] }}">
                                    <img src="{{ $post['trackedImageUrl'] }}" alt="{{ $post['itemName'] }}" width="120" height="120" style="display: block; width: 120px; height: 120px; object-fit: cover;" />
                                </a>
                            </td>
                            <td style="vertical-align: top;">
                                <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse: collapse; width: 100%;">
                                    <tr>
                                        <td style="vertical-align: top; width: 85px; padding-right: 8px;">
                                            <span style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 13px; font-weight: bold; padding: 6px 11px; border-radius: 4px; line-height: 1;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                                        </td>
                                        <td style="vertical-align: top;">
                                            <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none; font-weight: 600; font-size: 15px; line-height: 1.4;">{{ $post['itemName'] }}</a>
                                            @if($post['locationName'])
                                            <br/><span style="color: #212529; font-size: 12px; font-weight: 500;">{{ $post['locationName'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @if($post['messageText'] || $post['postedToText'])
                                    <tr>
                                        <td colspan="2" style="padding-top: 4px;">
                                            @if($post['messageText'])
                                            <span style="color: #808080; font-size: 13px; font-weight: 500; line-height: 1.4;">{{ \Illuminate\Support\Str::limit($post['messageText'], 100, '...') }}</span>
                                            @endif
                                            @if($post['postedToText'])
                                            <br/><span style="color: #999999; font-size: 11px; font-style: italic;">{{ $post['postedToText'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @endif
                                </table>
                            </td>
                        </tr>
                        {{-- Bottom row: reply button + time, aligned to bottom of image --}}
                        <tr>
                            <td style="vertical-align: bottom; padding-top: 6px;">
                                <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse: collapse; width: 100%;">
                                    <tr>
                                        <td style="vertical-align: middle;">
                                            <a href="{{ $post['messageUrl'] }}" style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 13px; font-weight: 600; padding: 6px 16px; border-radius: 4px; text-decoration: none;">Reply</a>
                                        </td>
                                        <td style="vertical-align: middle; text-align: right; color: #999999; font-size: 12px; white-space: nowrap;">
                                            @if($post['distanceText'])<span style="margin-right: 8px;">{{ $post['distanceText'] }}</span>@endif{{ $post['arrivalFormatted'] }}
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </mj-text>
            </mj-column>
        </mj-section>
        @endforeach

        {{-- Browse all CTA --}}
        <mj-section background-color="#ffffff" padding="16px 20px 20px 20px">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 0 16px 0" />
                <mj-button
                    href="{{ $browseUrl }}"
                    mj-class="btn-success"
                    font-size="16px"
                    inner-padding="12px 40px"
                    border-radius="4px"
                >
                    Browse All Posts
                </mj-button>
            </mj-column>
        </mj-section>

        @if($sponsors->isNotEmpty())
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" padding-bottom="5px" />
                <mj-text font-size="12px" color="#888888" font-style="italic" padding-bottom="5px">
                    Sponsored by:
                </mj-text>
            </mj-column>
        </mj-section>
        @foreach($sponsors as $sponsor)
        <mj-section background-color="#ffffff" padding="0 20px 10px">
            <mj-column width="80px" vertical-align="middle">
                @if($sponsor->imageurl)
                <mj-image
                    width="60px"
                    src="{{ $sponsor->imageurl }}"
                    alt="{{ $sponsor->name }}"
                    href="{{ $sponsor->linkurl }}"
                    border-radius="5px"
                />
                @endif
            </mj-column>
            <mj-column vertical-align="middle">
                <mj-text font-size="13px">
                    @if($sponsor->linkurl)
                    <a href="{{ $sponsor->linkurl }}" style="color: #338808; text-decoration: none; font-weight: bold;">{{ $sponsor->name }}</a>
                    @else
                    <strong>{{ $sponsor->name }}</strong>
                    @endif
                    @if($sponsor->tagline)
                    <br /><span style="font-size: 11px; color: #666;">{{ $sponsor->tagline }}</span>
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endforeach
        @endif

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])
    </mj-body>
</mjml>
