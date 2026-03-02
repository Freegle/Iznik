<mjml>
    @include('emails.mjml.partials.head', ['preview' => $postCount . ' new post' . ($postCount === 1 ? '' : 's') . ' near you'])

    <mj-body background-color="#f4f4f4">
        @php
            $offers = collect($posts)->where('type', 'Offer');
            $wanteds = collect($posts)->where('type', 'Wanted');
            $maxHeaderItems = 4;
        @endphp

        {{-- Header - Freegle brand green with logo + post summary --}}
        <mj-section mj-class="bg-success" padding="16px 20px 8px 20px">
            <mj-column width="30%">
                <mj-image
                    width="60px"
                    src="{{ config('freegle.branding.logo_url') }}"
                    alt="Freegle"
                    align="left"
                    padding="0"
                />
            </mj-column>
            <mj-column width="70%">
                <mj-text font-size="20px" font-weight="bold" color="#ffffff" align="right" padding="8px 0 0 0">
                    {{ $postCount }} new post{{ $postCount === 1 ? '' : 's' }} near you
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Header continued - greeting + OFFER/WANTED split summary --}}
        <mj-section mj-class="bg-success" padding="0 20px 16px 20px">
            <mj-column>
                <mj-text font-size="14px" color="#ffffff" padding="0 0 8px 0" line-height="1.5">
                    Hi {{ $user->displayname ?? 'there' }}, here's what's new:
                </mj-text>

                @if($offers->isNotEmpty())
                <mj-text font-size="13px" color="#ffffff" padding="0 0 4px 0" line-height="1.6">
                    <span style="display: inline-block; background-color: rgba(255,255,255,0.25); font-size: 11px; font-weight: bold; padding: 1px 6px; border-radius: 3px; letter-spacing: 0.5px; vertical-align: middle; margin-right: 4px;">OFFER</span>
                    @foreach($offers->take($maxHeaderItems) as $i => $post)
                    <a href="{{ $post['messageUrl'] }}" style="color: #ffffff; text-decoration: underline; text-decoration-color: rgba(255,255,255,0.4);">{{ $post['itemName'] }}</a>@if(!$loop->last || $offers->count() > $maxHeaderItems) &bull; @endif
                    @endforeach
                    @if($offers->count() > $maxHeaderItems)
                    <a href="{{ $browseUrl }}" style="color: #ffffff; text-decoration: underline;">{{ $offers->count() - $maxHeaderItems }} more&hellip;</a>
                    @endif
                </mj-text>
                @endif

                @if($wanteds->isNotEmpty())
                <mj-text font-size="13px" color="#ffffff" padding="0 0 4px 0" line-height="1.6">
                    <span style="display: inline-block; background-color: rgba(255,255,255,0.25); font-size: 11px; font-weight: bold; padding: 1px 6px; border-radius: 3px; letter-spacing: 0.5px; vertical-align: middle; margin-right: 4px;">WANTED</span>
                    @foreach($wanteds->take($maxHeaderItems) as $i => $post)
                    <a href="{{ $post['messageUrl'] }}" style="color: #ffffff; text-decoration: underline; text-decoration-color: rgba(255,255,255,0.4);">{{ $post['itemName'] }}</a>@if(!$loop->last || $wanteds->count() > $maxHeaderItems) &bull; @endif
                    @endforeach
                    @if($wanteds->count() > $maxHeaderItems)
                    <a href="{{ $browseUrl }}" style="color: #ffffff; text-decoration: underline;">{{ $wanteds->count() - $maxHeaderItems }} more&hellip;</a>
                    @endif
                </mj-text>
                @endif
            </mj-column>
        </mj-section>

        {{-- Post cards --}}
        @foreach($posts as $index => $post)
        @php $isOffer = $post['type'] === 'Offer'; @endphp

        {{-- Card separator --}}
        @if($index > 0)
        <mj-section padding="0" background-color="#f4f4f4">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 20px" />
            </mj-column>
        </mj-section>
        @endif

        {{-- Single section: image + all content side by side --}}
        <mj-section background-color="#ffffff" padding="16px 20px">
            {{-- Image column --}}
            <mj-column width="30%" vertical-align="top">
                <mj-image
                    width="120px"
                    src="{{ $post['trackedImageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    href="{{ $post['messageUrl'] }}"
                    border-radius="8px"
                    padding="0"
                />
            </mj-column>

            {{-- Content column --}}
            <mj-column width="70%" vertical-align="top">
                {{-- Badge + Title + Location using table for proper alignment --}}
                <mj-text font-size="15px" color="#212529" padding="0 0 4px 0" line-height="1.3">
                    <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse: collapse;">
                        <tr>
                            <td style="vertical-align: top; padding-right: 8px; padding-top: 2px;">
                                <span style="display: inline-block; background-color: {{ $isOffer ? '#3c763d' : '#4895DD' }}; color: #ffffff; font-size: 11px; font-weight: bold; padding: 2px 8px; border-radius: 4px; letter-spacing: 0.5px; white-space: nowrap;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                            </td>
                            <td style="vertical-align: top;">
                                <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none; font-weight: 600; font-size: 15px;">{{ $post['itemName'] }}</a>
                                @if($post['locationName'])
                                <br/><span style="color: #808080; font-size: 12px; font-weight: normal;">{{ $post['locationName'] }}</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </mj-text>

                {{-- Description preview --}}
                @if($post['messageText'])
                <mj-text font-size="13px" color="#808080" padding="2px 0 4px 0" line-height="1.4">
                    {{ \Illuminate\Support\Str::limit($post['messageText'], 120) }}
                </mj-text>
                @endif

                {{-- Posted to (cross-post indicator) - subtle --}}
                @if($post['postedToText'])
                <mj-text font-size="11px" color="#999999" padding="0 0 4px 0" font-style="italic">
                    {{ $post['postedToText'] }}
                </mj-text>
                @endif

                {{-- Meta row: time --}}
                <mj-text font-size="12px" color="#808080" padding="2px 0 8px 0">
                    {!! '&#128337;' !!} {{ $post['arrivalFormatted'] }}
                </mj-text>

                {{-- Reply button --}}
                <mj-button
                    href="{{ $post['messageUrl'] }}"
                    mj-class="btn-success"
                    align="left"
                    font-size="14px"
                    inner-padding="8px 24px"
                    border-radius="4px"
                    padding="0"
                >
                    Reply
                </mj-button>
            </mj-column>
        </mj-section>
        @endforeach

        {{-- Browse all CTA --}}
        <mj-section background-color="#ffffff" padding="20px">
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
