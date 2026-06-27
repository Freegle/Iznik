<mjml>
    @include('emails.mjml.partials.head', ['preview' => 'Thank you for your donation - you help keep Freegle free for everyone.'])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="24px" font-weight="bold" mj-class="text-success" align="center">
                    Thank You!
                </mj-text>
                <mj-text>
                    Dear {{ $user->displayname ?? 'there' }},
                </mj-text>
                <mj-text>
                    Thank you so much for your generous donation to Freegle. Your support helps us keep the platform running and enables millions of items to find new homes instead of going to landfill.
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text font-weight="bold">
                    Your donation helps us to:
                </mj-text>
                <mj-text>
                    - Keep Freegle free for everyone to use<br />
                    - Maintain our servers and infrastructure<br />
                    - Develop new features to make freegling easier<br />
                    - Support our community of local volunteers<br />
                    - Keep stuff out of landfill
                </mj-text>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-button href="{{ $continueUrl }}" mj-class="btn-success" border-radius="3px">
                    Continue Freegling
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-text font-size="13px" color="#666666">
                    Thanks again for your support. You're helping make the world a greener place!
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
