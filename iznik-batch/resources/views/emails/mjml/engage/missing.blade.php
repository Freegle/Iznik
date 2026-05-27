<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'We miss you on Freegle!'])
  <mj-body background-color="#f4f4f4">

    {{-- Header — vertical padding on the SECTION (not the text) so mj-column
         vertical-align="middle" works; same fix pattern as bf82093b4. --}}
    <mj-section mj-class="bg-success" padding="15px 0">
      <mj-column width="65%" vertical-align="middle">
        <mj-text font-size="22px" font-weight="bold" color="#ffffff" padding="0 25px">
          We miss you!
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
          We think you've not freegled for a while. Could we tempt you back?
        </mj-text>
        <mj-text font-size="14px" color="#333333" padding="10px 25px">
          Maybe you've got something lying around that someone else could use, or perhaps there's something someone else might have?
        </mj-text>
        <mj-text font-size="14px" color="#333333" padding="10px 25px 20px">
          Either way, we'd love to have you back. It's only waste if you waste it...
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- CTA buttons --}}
    <mj-section background-color="#F7F6EC" padding="0 0 25px">
      <mj-column>
        <mj-button
          mj-class="btn-warning"
          href="{{ $userSite }}/engage?engageid={{ $engageId }}&action=find"
          border-radius="3px"
          font-size="15px"
          font-weight="bold"
          padding="12px 25px"
        >
          WANT something?
        </mj-button>
      </mj-column>
      <mj-column>
        <mj-button
          mj-class="btn-success"
          href="{{ $userSite }}/engage?engageid={{ $engageId }}&action=give"
          border-radius="3px"
          font-size="15px"
          font-weight="bold"
          padding="12px 25px"
        >
          OFFER something?
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
