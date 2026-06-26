<mjml>
  @include('emails.mjml.partials.head', ['preview' => "We'll stop sending you emails soon"])
  <mj-body background-color="#f4f4f4">

    {{-- Header — vertical padding on the SECTION (not the text) so mj-column
         vertical-align="middle" works; same fix pattern as bf82093b4. --}}
    <mj-section mj-class="bg-success" padding="15px 0">
      <mj-column width="65%" vertical-align="middle">
        <mj-text font-size="20px" font-weight="bold" color="#ffffff" padding="0 25px">
          We'll stop sending you emails soon...
        </mj-text>
      </mj-column>
      <mj-column width="35%" vertical-align="middle">
        <mj-image
          width="80px"
          src="{{ config('freegle.branding.logo_url', 'https://www.ilovefreegle.org/icon.png') }}"
          alt="Freegle"
          align="right"
          padding="0 20px"
        />
      </mj-column>
    </mj-section>

    {{-- Body --}}
    <mj-section background-color="#F7F6EC" padding="20px 0 0">
      <mj-column>
        <mj-text font-size="14px" color="#333333" padding="10px 25px">
          Dear {{ $name }},
        </mj-text>
        <mj-text font-size="14px" color="#333333" padding="10px 25px">
          This is just a quick check to see if we can make our service better for you. You haven't logged in for a few months, so to make sure we aren't sending you unwanted emails, or in case you are not interested in freegling any more, we will stop our emails to you.
        </mj-text>
        <mj-text font-size="14px" color="#333333" padding="10px 25px">
          If you'd still like to get them, then just click one of the buttons below — this will log you in and confirm you wish to keep your account active.
        </mj-text>
        <mj-text font-size="14px" color="#333333" padding="10px 25px 20px">
          Maybe you've got something lying around that someone else could use, or perhaps there's something someone else might have?
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- CTA buttons --}}
    <mj-section background-color="#F7F6EC" padding="0 0 20px">
      <mj-column>
        <mj-button
          mj-class="btn-warning"
          href="{{ $userSite }}/engage?engageid={{ $engageId }}&action=find"
          border-radius="3px"
          font-size="14px"
          width="180px"
        >
          Ask for something
        </mj-button>
      </mj-column>
      <mj-column>
        <mj-button
          mj-class="btn-secondary"
          href="{{ $userSite }}/engage?engageid={{ $engageId }}&action=browse"
          border-radius="3px"
          font-size="14px"
          width="160px"
        >
          Browse Freegle
        </mj-button>
      </mj-column>
      <mj-column>
        <mj-button
          mj-class="btn-success"
          href="{{ $userSite }}/engage?engageid={{ $engageId }}&action=give"
          border-radius="3px"
          font-size="14px"
          width="180px"
        >
          Give something
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Footer --}}
    @include('emails.mjml.partials.footer', [
      'email'          => $email,
      'unsubscribeUrl' => $unsubscribeUrl,
    ])

  </mj-body>
</mjml>
