<mjml>
    @include('emails.mjml.partials.head', ['preview' => 'Deadline reached: ' . \Illuminate\Support\Str::limit(strip_tags($post->subject), 70)])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
                    Your post has reached its deadline
                </mj-text>
                <mj-text>
                    Dear {{ $user->displayname ?? 'there' }},
                </mj-text>
                <mj-text>
                    Your post "<strong>{{ $post->subject }}</strong>" has now reached the deadline you set.
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text font-weight="bold">
                    What would you like to do?
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column width="33%">
                <mj-button href="{{ $extendUrl }}" mj-class="btn-secondary" border-radius="3px" font-size="14px">
                    Extend Deadline
                </mj-button>
            </mj-column>
            <mj-column width="33%">
                <mj-button href="{{ $completedUrl }}" mj-class="btn-success" border-radius="3px" font-size="14px">
                    Mark as {{ ucfirst($outcomeType) }}
                </mj-button>
            </mj-column>
            <mj-column width="33%">
                <mj-button href="{{ $withdrawUrl }}" mj-class="btn-dark" border-radius="3px" font-size="14px">
                    Withdraw
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666">
                    <strong>Extend:</strong> Give yourself more time to find someone for your item.<br />
                    <strong>Mark as {{ ucfirst($outcomeType) }}:</strong> Tell us the item has found a new home.<br />
                    <strong>Withdraw:</strong> Remove the post if you no longer want to give it away.
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    This message was sent because your post on {{ $groupName }} reached the deadline you set.
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
