<mjml>
  @include('emails.mjml.partials.head', [
    'preview' => 'Your post is reaching more people on ' . config('freegle.branding.name')
  ])
  <mj-body background-color="#ffffff">

    {{-- Header --}}
    <mj-section mj-class="bg-success" padding="10px 0">
      <mj-column width="65%" vertical-align="middle">
        <mj-text font-size="18px" font-weight="bold" color="#ffffff" padding="0 25px">
          Your post is reaching more people
        </mj-text>
      </mj-column>
      <mj-column width="35%" vertical-align="middle">
        <mj-image
          width="80px"
          src="{{ config('freegle.branding.logo_url', config('freegle.logo_url', 'https://www.ilovefreegle.org/icon.png')) }}"
          alt="{{ config('freegle.branding.name') }}"
          align="right"
          padding="0 20px"
        />
      </mj-column>
    </mj-section>

    {{-- Body --}}
    <mj-section background-color="#F7F6EC" padding="20px 0">
      <mj-column>
        <mj-text font-size="14px" color="#000000" line-height="1.6" padding="10px 25px">
          @if(!empty($firstName))Hi {{ $firstName }},@else Hi,@endif
        </mj-text>
        <mj-text font-size="14px" color="#000000" line-height="1.6" padding="6px 25px">
          Good news - your recent {{ config('freegle.branding.name') }} post is being shown to
          nearby communities beyond your own, so even more people can take what you're offering
          or help with what you're looking for.
        </mj-text>
        <mj-text font-size="15px" font-weight="bold" color="#338808" line-height="1.6" padding="6px 25px">
          There's nothing you need to do - this just helps your post reach the right person.
        </mj-text>
        <mj-text font-size="14px" color="#000000" line-height="1.6" padding="6px 25px">
          So this works smoothly, we've added you to those communities - just as if you'd posted
          there yourself. That way people there can reply to you, and our volunteers can reach you
          about your post if they need to.
        </mj-text>

        <mj-text font-size="14px" font-weight="bold" color="#000000" line-height="1.6" padding="14px 25px 4px 25px">
          We've kept things calm for you
        </mj-text>
        <mj-text font-size="14px" color="#000000" line-height="1.6" padding="0 25px">
          <ul>
            <li>Posts from those communities come in your <strong>once-a-day digest</strong>, not as
              they happen - so your inbox won't fill up.</li>
            <li>Your community events and volunteering emails stay <strong>exactly as you set them</strong>.</li>
          </ul>
        </mj-text>

        <mj-text font-size="14px" font-weight="bold" color="#000000" line-height="1.6" padding="14px 25px 4px 25px">
          You're in control
        </mj-text>
        <mj-text font-size="14px" color="#000000" line-height="1.6" padding="0 25px">
          <ul>
            <li>Change how often each community emails you any time in your
              <a href="{{ $settingsUrl }}">settings</a>.</li>
            <li>Leave any community you'd rather not be in - and we won't add you back. Leaving also
              removes your post from that community.</li>
          </ul>
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Each community's own welcome message, so the poster still sees what each one wanted to
         say - bundled here instead of as a separate welcome email per community. --}}
    @if(!empty($welcomeGroups))
    <mj-section background-color="#ffffff" padding="6px 0 0 0">
      <mj-column>
        <mj-text font-size="15px" font-weight="bold" color="#000000" line-height="1.6" padding="10px 25px 4px 25px">
          A welcome from the communities your post reached
        </mj-text>
      </mj-column>
    </mj-section>
    @foreach($welcomeGroups as $wg)
    <mj-section background-color="#F7F6EC" padding="6px 0" border-radius="4px">
      <mj-column>
        <mj-text font-size="14px" font-weight="bold" color="#338808" line-height="1.5" padding="8px 25px 2px 25px">
          {{ $wg['name'] }}
        </mj-text>
        <mj-text font-size="13px" color="#000000" line-height="1.6" padding="0 25px 8px 25px">
          {!! nl2br(e($wg['welcome'])) !!}
        </mj-text>
      </mj-column>
    </mj-section>
    @endforeach
    @endif

    {{-- CTA --}}
    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-button href="{{ $settingsUrl }}" mj-class="btn-success" font-size="14px" padding="12px 25px">
          Your email settings
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Footer --}}
    @include('emails.mjml.partials.footer', [
      'email' => $email,
      'settingsUrl' => $settingsUrl,
      'unsubscribeUrl' => $unsubscribeUrl,
    ])

  </mj-body>
</mjml>
