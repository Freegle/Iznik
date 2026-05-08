<mjml>
    @include('emails.mjml.partials.head', ['preview' => "There's stuff to do on ModTools"])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        <mj-section background-color="#ffffff" padding="20px">
            <mj-column>
                <mj-text>
                    Dear {{ $recipientName }},
                </mj-text>
                <mj-text>
                    There's stuff to do on ModTools:
                </mj-text>
                <mj-text>
                    {!! $htmlSummary !!}
                </mj-text>
                <mj-button href="{{ 'https://' . config('freegle.sites.mod', 'modtools.org') }}" mj-class="btn-success" border-radius="3px">
                    Go to ModTools
                </mj-button>
            </mj-column>
        </mj-section>

        <mj-section background-color="#f9f9f9" padding="20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666">
                    You can control how often you get these emails or turn them off entirely from
                    <a href="{{ $settingsUrl }}">your ModTools settings</a>.
                </mj-text>
            </mj-column>
        </mj-section>

        @include('emails.mjml.partials.footer', ['email' => '', 'settingsUrl' => $settingsUrl])
    </mj-body>
</mjml>
