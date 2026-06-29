{{--
  Initial community-reuse outreach email.
  Sent (via the Gmail API, not Laravel Mail) from natalie-wagg@ilovefreegle.org
  as "Natalie @ Freegle" to a local community organisation that is NOT a Freegle
  user. Reply-To is the mailbox, so replies land back in the inbox for the
  concierge. Footer is bespoke: provenance + a mailto unsubscribe (the orgs have
  no Freegle account, so the standard /settings + /unsubscribe footer is wrong).

  Data contract (provided by SendOutreachCommand):
    $greetingName   string  - how to address them (contact name, else org name, else "there")
    $orgName        ?string - organisation name, for the provenance line
    $area           ?string - their area, for the provenance line
    $intro          ?string - optional one-line tailored opener
    $posts          array   - [ ['title' => string, 'url' => string], ... ]
    $signoffName    string  - "Natalie @ Freegle"
    $unsubscribeUrl string  - mailto:natalie-wagg+unsub@ilovefreegle.org?subject=unsubscribe
    $preview        string  - inbox preview text
--}}
<mjml>
  @include('emails.mjml.partials.head', ['preview' => $preview])
  <mj-body background-color="#ffffff">

    <mj-section mj-class="bg-header" padding="18px 24px">
      <mj-column>
        <mj-text color="#ffffff" font-size="20px" font-weight="bold">{{ config('freegle.branding.name') }}</mj-text>
      </mj-column>
    </mj-section>

    <mj-section padding="24px 24px 8px 24px">
      <mj-column>
        <mj-text font-size="16px" line-height="1.6" color="#333333">
          <p style="margin:0 0 14px 0;">Hello {{ $greetingName }},</p>

          @if(!empty($intro))
            <p style="margin:0 0 14px 0;">{{ $intro }}</p>
          @endif

          <p style="margin:0 0 14px 0;">
            I help run Freegle, a free local reuse service. Some people near you are giving away
            things that I thought {{ $orgName ?? 'you' }} might be able to use - all free, and the
            giver just wants them to go to good use rather than to the tip.
          </p>

          <p style="margin:0 0 6px 0;">Here's what's currently available nearby:</p>
        </mj-text>

        <mj-text font-size="16px" line-height="1.6" color="#333333" padding="0 24px 8px 24px">
          <ol>
            @foreach($posts as $post)
              <li><a href="{{ $post['url'] }}">{{ $post['title'] }}</a></li>
            @endforeach
          </ol>
        </mj-text>

        <mj-text font-size="16px" line-height="1.6" color="#333333">
          <p style="margin:0 0 14px 0;">
            If any of these would be useful, just reply to this email and tell me which ones - I'll
            sort out the details and collection with you. No Freegle account or sign-up needed; we
            can do it all by email.
          </p>

          <p style="margin:0 0 4px 0;">Thanks,</p>
          <p style="margin:0;"><strong>{{ $signoffName }}</strong></p>
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Bespoke provenance + unsubscribe footer --}}
    <mj-section background-color="#f5f5f5" padding="20px 24px">
      <mj-column>
        <mj-text font-size="12px" color="#666666" line-height="1.6">
          You're receiving this because we found {{ $orgName ?? 'your organisation' }}'s contact
          details published online@if(!empty($area)) for {{ $area }}@endif, and the items above are
          local to you. We thought they might be useful. If they're not, no problem -
          <a href="{{ $unsubscribeUrl }}" style="color:#338808;font-weight:bold;text-decoration:none;">click here to stop receiving these emails</a>
          and we won't contact you about reuse offers again.
        </mj-text>
        <mj-divider border-color="#dddddd" border-width="1px" padding="14px 0"></mj-divider>
        <mj-text font-size="11px" color="#666666" line-height="1.5">
          {{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865)
          and is run by volunteers. Which is nice.<br/>
          Registered address: {{ config('freegle.branding.registered_address') }}
        </mj-text>
      </mj-column>
    </mj-section>

  </mj-body>
</mjml>
