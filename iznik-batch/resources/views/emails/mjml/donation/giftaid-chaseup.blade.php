<mjml>
    @include('emails.mjml.partials.head', ['preview' => 'Could Freegle collect Gift Aid on your donation?'])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Dear {{ $user->displayname ?? 'there' }},
                </mj-text>
                <mj-text>
                    You kindly donated to Freegle on {{ $donationDate }}. We can make your kind donation go even further if we can claim Gift Aid on it.
                </mj-text>
                <mj-text>
                    If you're a UK taxpayer, Freegle can claim an extra 25p for every £1 you donate — at no cost to you!
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-button href="{{ $giftAidUrl }}" mj-class="btn-success" border-radius="3px">
                    Consent to Gift Aid
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text font-size="13px" color="#666666">
                    This is a one-off email. You won't be asked again about this donation.
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

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])
    </mj-body>
</mjml>
