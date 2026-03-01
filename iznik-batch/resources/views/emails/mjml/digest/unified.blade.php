<mjml>
    @include('emails.mjml.partials.head', ['preview' => $postCount . ' new post' . ($postCount === 1 ? '' : 's') . ' near you'])

    <mj-body background-color="#f4f4f4">
        {{-- Header - Freegle brand green with logo --}}
        <mj-section mj-class="bg-success" padding="16px 20px">
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

        {{-- Quick links summary - each item name links to its external URL --}}
        <mj-section mj-class="bg-green-light" padding="12px 20px">
            <mj-column>
                <mj-text font-size="13px" color="#333333" line-height="1.6" padding="0">
                    Hi {{ $user->displayname ?? 'there' }}, here's what's new:
                    @foreach($posts as $i => $post)
                    <a href="{{ $post['messageUrl'] }}" style="color: {{ $post['type'] === 'Offer' ? '#338808' : '#00A1CB' }}; font-weight: 600; text-decoration: none;">{{ $post['itemName'] }}</a>{!! $i < count($posts) - 1 ? ' &bull; ' : '' !!}
                    @endforeach
                </mj-text>
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

        <mj-section background-color="#ffffff" padding="16px 20px 4px 20px">
            {{-- Type badge --}}
            <mj-column width="100%">
                <mj-text font-size="11px" font-weight="bold" letter-spacing="0.5px" padding="0 0 4px 0"
                    color="{{ $isOffer ? '#338808' : '#00A1CB' }}">
                    {{ $isOffer ? 'OFFER' : 'WANTED' }}
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="0 20px 16px 20px">
            {{-- Image column --}}
            <mj-column width="30%" vertical-align="top">
                @if($post['imageUrl'])
                <mj-image
                    width="120px"
                    src="{{ $post['trackedImageUrl'] ?? $post['imageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    href="{{ $post['messageUrl'] }}"
                    border-radius="8px"
                    padding="0"
                />
                @else
                <mj-image
                    width="120px"
                    src="{{ config('freegle.branding.logo_url') }}"
                    alt="{{ $post['itemName'] }}"
                    href="{{ $post['messageUrl'] }}"
                    padding="10px"
                    css-class="no-photo-placeholder"
                />
                @endif
            </mj-column>

            {{-- Content column --}}
            <mj-column width="70%" vertical-align="top">
                {{-- Item name --}}
                <mj-text font-size="16px" font-weight="bold" color="#333333" padding="0 0 6px 0" line-height="1.3">
                    <a href="{{ $post['messageUrl'] }}" style="color: #333333; text-decoration: none;">{{ $post['itemName'] }}</a>
                </mj-text>

                {{-- Description preview --}}
                @if($post['messageText'])
                <mj-text font-size="14px" color="#555555" padding="0 0 6px 0" line-height="1.4">
                    {{ \Illuminate\Support\Str::limit($post['messageText'], 120) }}
                </mj-text>
                @endif

                {{-- Posted to (cross-post indicator) - subtle --}}
                @if($post['postedToText'])
                <mj-text font-size="11px" color="#999999" padding="0 0 6px 0" font-style="italic">
                    {{ $post['postedToText'] }}
                </mj-text>
                @endif

                {{-- Reply button --}}
                <mj-button
                    href="{{ $post['messageUrl'] }}"
                    mj-class="btn-success"
                    align="left"
                    font-size="14px"
                    inner-padding="8px 24px"
                    border-radius="4px"
                    padding="4px 0 0 0"
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
