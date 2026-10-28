<mjml>
    @include('emails.mjml.partials.head', ['preview' => $count === 1 ? 'A post you were interested in is still available' : $count . ' posts you were interested in are still available'])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px 20px 6px">
            <mj-column>
                <mj-text font-size="20px" font-weight="bold" color="#333333" padding="0 0 6px">
                    @if($count === 1)
                        A post you were interested in is still available
                    @else
                        {{ $count }} posts you were interested in are still available
                    @endif
                </mj-text>
                <mj-text font-size="14px" color="#555555" padding="0">
                    You said you were interested, but have not replied yet. Reply while it is still there.
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
                        <a href="{{ $post['viewUrl'] }}" style="color: #212529; text-decoration: none; font-weight: 600; font-size: 16px; line-height: 1.3;">{{ $post['itemName'] }}</a>
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
                    You can see the posts you are interested in on <a href="{{ $myPostsUrl }}" style="color: #338808;">My Posts</a>.
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
