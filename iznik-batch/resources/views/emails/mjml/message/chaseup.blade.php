<mjml>
    @include('emails.mjml.partials.head', ['preview' => 'What happened to: ' . \Illuminate\Support\Str::limit(strip_tags($messageSubject), 76)])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
                    What happened to: {{ $messageSubject }}
                </mj-text>
                <mj-text font-size="16px" line-height="1.6">
                    Dear {{ $userName ?? 'there' }},
                </mj-text>
                <mj-text font-size="16px" line-height="1.6">
                    Please click one of the following buttons to let us know what happened to your message <strong>{{ $messageSubject }}</strong>:
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column width="33%">
                <mj-button href="{{ $repostUrl }}" mj-class="btn-success" border-radius="3px" font-size="14px">
                    Repost
                </mj-button>
            </mj-column>
            <mj-column width="33%">
                <mj-button href="{{ $completedUrl }}" mj-class="btn-secondary" border-radius="3px" font-size="14px">
                    Mark as {{ $outcomeType }}
                </mj-button>
            </mj-column>
            <mj-column width="33%">
                <mj-button href="{{ $withdrawUrl }}" mj-class="btn-dark" border-radius="3px" font-size="14px">
                    Withdraw
                </mj-button>
            </mj-column>
        </mj-section>

        @if(!empty($isOffer))
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text font-size="16px" line-height="1.6">
                    If you are in the middle of arranging things, please mark it as <em>Promised</em> so that the system knows.
                </mj-text>
            </mj-column>
        </mj-section>
        <mj-section background-color="#ffffff" padding="0 20px 10px">
            <mj-column>
                <mj-button href="{{ $promiseUrl }}" mj-class="btn-success" border-radius="3px" font-size="14px">
                    Mark as Promised
                </mj-button>
            </mj-column>
        </mj-section>
        @endif

        <mj-section background-color="#ffffff" padding="10px 20px 20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666">
                    This helps us decide whether to keep showing it, and to measure how much freegling happens so that we can persuade councils to support Freegle more. You can also update your posts in <a href="{{ $chatsUrl }}" style="color: #338808;">Chats</a> or <a href="{{ $myPostsUrl }}" style="color: #338808;">My Posts</a>.
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
