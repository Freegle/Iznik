<mjml>
    @include('emails.mjml.partials.head', ['preview' => "There's stuff to do on ModTools"])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.modtools-header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
                    There's stuff to do on ModTools
                </mj-text>
                <mj-text>
                    Dear {{ $recipientName }},
                </mj-text>
                <mj-text>
                    {!! $htmlSummary !!}
                </mj-text>
                <mj-button href="{{ config('freegle.sites.mod', 'https://modtools.org') }}" mj-class="btn-modtools" border-radius="3px">
                    Go to ModTools
                </mj-button>
            </mj-column>
        </mj-section>

        @include('emails.mjml.partials.modtools-footer', ['email' => $email, 'settingsUrl' => $settingsUrl])
    </mj-body>
</mjml>
