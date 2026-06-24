<mjml>
    @include('emails.mjml.partials.head', ['preview' => 'Promised: ' . \Illuminate\Support\Str::limit(strip_tags($messageSubject), 60) . ' - has it been collected?'])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
                    What happened to: {{ $messageSubject }}
                </mj-text>
                <mj-text>
                    Dear {{ $userName ?? 'there' }},
                </mj-text>
                <mj-text>
                    You promised this to someone. If they've already collected it, please let us know:
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-button href="{{ $completedUrl }}" mj-class="btn-success" border-radius="3px" font-size="18px" padding="12px 30px">
                    Mark as {{ $outcomeType }}
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text>
                    If it's not happened yet, just go to <a href="{{ $myPostsUrl }}" style="color: #338808;">My Posts</a> once it has. But if it doesn't work out, then you could repost it for other people to see, or withdraw it if you've changed your mind.
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column width="50%">
                <mj-button href="{{ $repostUrl }}" mj-class="btn-success" border-radius="3px" font-size="14px">
                    Repost
                </mj-button>
            </mj-column>
            <mj-column width="50%">
                <mj-button href="{{ $withdrawUrl }}" mj-class="btn-dark" border-radius="3px" font-size="14px">
                    Withdraw
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    This helps us decide whether to keep showing it, and to measure how much freegling happens so that we can persuade councils to support Freegle more.
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
