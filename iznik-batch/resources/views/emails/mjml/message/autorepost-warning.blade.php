<mjml>
    @include('emails.mjml.partials.head', ['preview' => 'Will repost soon: ' . \Illuminate\Support\Str::limit(strip_tags($messageSubject), 75)])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
                    Will Repost: {{ $messageSubject }}
                </mj-text>
                <mj-text>
                    Dear {{ $userName ?? 'there' }},
                </mj-text>
                <mj-text>
                    We will automatically repost your message <strong>{{ $messageSubject }}</strong> soon, so that more people will see it.
                </mj-text>
                <mj-text>
                    If you <strong>don't</strong> want us to do that, please click on one of the following buttons to let us know:
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column width="50%">
                <mj-button href="{{ $completedUrl }}" mj-class="btn-success" border-radius="3px" font-size="14px">
                    Mark as {{ $outcomeType }}
                </mj-button>
            </mj-column>
            <mj-column width="50%">
                <mj-button href="{{ $withdrawUrl }}" mj-class="btn-dark" border-radius="3px" font-size="14px">
                    Withdraw
                </mj-button>
            </mj-column>
        </mj-section>

        @if($isOffer)
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text>
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
                <mj-divider border-color="#eeeeee" />
                <mj-text font-size="12px" color="#666666">
                    If you don't want your posts to be "bumped" by autoreposting, you can turn this off in
                    <a href="{{ $settingsUrl }}" style="color: #338808;">Settings</a>.
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
