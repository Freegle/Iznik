<mjml>
    @include('emails.mjml.partials.head', ['preview' => 'A note about your past Freegle donations — and Gift Aid'])

    <mj-body background-color="#f4f4f4">
        @include('emails.mjml.components.header')

        {{-- Intro + apology/thanks (verbatim copy, split for readability) --}}
        <mj-section background-color="#ffffff" padding="24px 24px 8px 24px">
            <mj-column>
                <mj-text font-size="16px" line-height="1.6">
                    Dear {{ $user->displayname ?? 'there' }},
                </mj-text>
                <mj-text font-size="16px" line-height="1.6">
                    You have kindly donated to Freegle in past years and we are currently checking to make sure that all donations are correctly recorded.
                </mj-text>
                <mj-text font-size="16px" line-height="1.6">
                    We have found some — <strong>yours included</strong> — where changes in email addresses meant they were not acknowledged properly.
                </mj-text>
                <mj-text font-size="16px" line-height="1.6">
                    We apologise for this and extend our <strong>overdue thanks to you for your support</strong>.
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Gift Aid ask — verbatim copy, set apart on a soft green panel --}}
        <mj-section mj-class="bg-green-light" padding="20px 24px">
            <mj-column>
                <mj-text font-size="16px" line-height="1.6">
                    We have also looked at whether we have tied these donations correctly to any Gift Aid consents. We can't find a consent from you, so hope you don't mind us asking if you would consider giving this?
                </mj-text>
                <mj-text font-size="16px" line-height="1.6">
                    Our form is at <a href="{{ $giftAidUrl }}">www.ilovefreegle.org/giftaid</a> and there are options to give consent or decline. No pressure at all, but if this is something you can offer, it will add an <strong>additional 25%</strong> onto your donation, which would be wonderful.
                </mj-text>
                <mj-button href="{{ $giftAidUrl }}" mj-class="btn-success" border-radius="4px" font-size="16px" padding="12px 0 4px 0" inner-padding="14px 28px">
                    Go to the Gift Aid form
                </mj-button>
            </mj-column>
        </mj-section>

        {{-- Sign-off --}}
        <mj-section background-color="#ffffff" padding="16px 24px 24px 24px">
            <mj-column>
                <mj-text font-size="16px" line-height="1.6">
                    Kind regards,<br />
                    Jacky
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
