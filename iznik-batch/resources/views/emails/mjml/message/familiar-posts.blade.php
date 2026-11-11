<mjml>
    @include('emails.mjml.partials.head', ['preview' => $count === 1 ? $posts[0]['poster'] . ' has a new post' : 'People you have chatted with have new posts'])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px 20px 6px">
            <mj-column>
                <mj-text font-size="20px" font-weight="bold" color="#333333" padding="0 0 6px">
                    @if($count === 1)
                        {{ $posts[0]['poster'] }} has a new post
                    @else
                        New posts from people you have chatted with
                    @endif
                </mj-text>
                <mj-text font-size="14px" color="#555555" padding="0">
                    @if($count === 1)
                        You chatted with {{ $posts[0]['poster'] }} on Freegle recently.
                    @else
                        You chatted with these people on Freegle recently.
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>

        @foreach ($posts as $post)
        <mj-section background-color="#ffffff" padding="10px 20px 14px">
            <mj-group>
                <mj-column width="32%" vertical-align="top" padding="0 12px 0 0">
                    <mj-image src="{{ $post['imageUrl'] }}" href="{{ $post['viewUrl'] }}" alt="{{ $post['itemName'] }}" width="100%" border-radius="4px" padding="0" />
                </mj-column>
                <mj-column width="68%" vertical-align="top">
                    <mj-text padding="0" color="#212529">
                        <span style="display: inline-block; background-color: {{ $post['isOffer'] ? '#338808' : '#1d6fa5' }}; color: #ffffff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 3px;">{{ $post['isOffer'] ? 'OFFER' : 'WANTED' }}</span>
                        <div style="margin-top: 6px;">
                            <a href="{{ $post['viewUrl'] }}" style="color: #212529; text-decoration: none; font-weight: 600; font-size: 16px; line-height: 1.3;">{{ $post['itemName'] }}</a>
                        </div>
                        <div style="margin-top: 4px; font-size: 13px; color: #666666;">From {{ $post['poster'] }}</div>
                        <div style="margin-top: 10px;">
                            <a href="{{ $post['replyUrl'] }}" style="display: inline-block; background-color: #338808; color: #ffffff; font-size: 13px; font-weight: 700; padding: 8px 24px; border-radius: 4px; text-decoration: none;">Reply</a>
                        </div>
                    </mj-text>
                </mj-column>
            </mj-group>
        </mj-section>
        <mj-section padding="0" background-color="#ffffff">
            <mj-column>
                <mj-divider border-color="#ececec" border-width="1px" padding="0 20px" />
            </mj-column>
        </mj-section>
        @endforeach

        <mj-section background-color="#ffffff" padding="10px 20px 20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666" padding="0">
                    We send this at most {{ $perWeek === 1 ? 'once' : ($perWeek === 2 ? 'twice' : $perWeek . ' times') }} a week. <a href="{{ $optOutUrl }}" style="color: #338808;">Stop emails about posts you might like</a>.
                </mj-text>
            </mj-column>
        </mj-section>

        @if(!empty($trackingPixelMjml))
        <mj-section padding="0">
            <mj-column>
                {!! $trackingPixelMjml !!}
            </mj-column>
        </mj-section>
        @endif

        @include('emails.mjml.partials.footer', ['email' => $email, 'settingsUrl' => $settingsUrl])
    </mj-body>
</mjml>
