<mjml>
    @include('emails.mjml.partials.head', ['previewText' => $postCount . ' new post' . ($postCount === 1 ? '' : 's') . ' near you'])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Dear {{ $user->displayname ?? 'there' }},
                </mj-text>
                <mj-text>
                    Here {{ $postCount === 1 ? 'is' : 'are' }} <strong>{{ $postCount }}</strong> new post{{ $postCount === 1 ? '' : 's' }} from your Freegle communities:
                </mj-text>
            </mj-column>
        </mj-section>

        @foreach($posts as $post)
        <mj-section background-color="#ffffff" padding="10px 20px" css-class="message-card">
            <mj-column width="25%">
                @if($post['trackedImageUrl'] ?? $post['imageUrl'])
                <mj-image
                    width="80px"
                    src="{{ $post['trackedImageUrl'] ?? $post['imageUrl'] }}"
                    alt="Photo"
                    href="{{ $post['messageUrl'] }}"
                />
                @else
                <mj-image
                    width="80px"
                    src="{{ $userSite }}/icon.png"
                    alt="No photo"
                />
                @endif
            </mj-column>
            <mj-column width="75%">
                <mj-text css-class="message-type" color="{{ $post['type'] === 'Offer' ? '#5cb85c' : '#337ab7' }}">
                    {{ $post['type'] === 'Offer' ? 'OFFER' : 'WANTED' }}
                </mj-text>
                <mj-text css-class="message-title">
                    <a href="{{ $post['messageUrl'] }}">{{ $post['itemName'] }}</a>
                </mj-text>
                @if($post['messageText'])
                <mj-text font-size="13px" color="#666666">
                    {{ \Illuminate\Support\Str::limit($post['messageText'], 100) }}
                </mj-text>
                @endif
                @if($post['postedToText'])
                <mj-text css-class="posted-to">
                    {{ $post['postedToText'] }}
                </mj-text>
                @endif
                <mj-button href="{{ $post['messageUrl'] }}" align="left" padding="10px 0" background-color="#5cb85c" color="#ffffff">
                    Reply
                </mj-button>
            </mj-column>
        </mj-section>
        @endforeach

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-button href="{{ $browseUrl }}" background-color="#5cb85c" color="#ffffff">
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
