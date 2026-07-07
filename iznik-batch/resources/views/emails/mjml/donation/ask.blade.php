<mjml>
    @include('emails.mjml.partials.head', ['preview' => $itemSubject ? 'Did you just get this from Freegle? ' . \Illuminate\Support\Str::limit(strip_tags($itemSubject), 65) : 'Thanks for freegling!'])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Dear {{ $user->displayname ?? 'there' }},
                </mj-text>

                @if($itemSubject)
                <mj-text>
                    Did you just get this from Freegle?
                </mj-text>
                <mj-text font-weight="bold" mj-class="text-success">
                    {{ $itemSubject }}
                </mj-text>
                <mj-text font-size="12px" color="#666666">
                    (If we're wrong, just delete this message.)
                </mj-text>
                <mj-text>
                    If you've not already, why not send a thanks to the person who gave it? Just to be nice. And you can also give them a Thumbs Up in the Chat window.
                </mj-text>
                @else
                <mj-text>
                    Thank you for using your local Freegle group.
                </mj-text>
                @endif
            </mj-column>
        </mj-section>

        <mj-section background-color="#f9f9f9" padding="20px">
            <mj-column>
                <mj-text>
                    Freegle is free to use, but it's not free to run. This month we're trying to raise <strong>&pound;{{ number_format($target) }}</strong> to keep us going.
                </mj-text>
                <mj-text>
                    If you can, please consider donating &pound;1 to help support Freegle:
                </mj-text>
                <mj-button href="{{ $donateUrl }}" mj-class="btn-warning" border-radius="3px">
                    Donate &pound;1 via PayPal
                </mj-button>
                <mj-text font-size="12px" color="#666666" align="center">
                    We realise not everyone is able to do this - and that's fine.
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Either way, thanks for freegling!
                </mj-text>
                <mj-button href="{{ $continueUrl }}" mj-class="btn-success" border-radius="3px">
                    Continue Freegling
                </mj-button>
            </mj-column>
        </mj-section>

        @if(!empty($trackingPixelMjml))
        <mj-section padding="0">
            <mj-column>
                {!! $trackingPixelMjml !!}
            </mj-column>
        </mj-section>
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])
    </mj-body>
</mjml>
