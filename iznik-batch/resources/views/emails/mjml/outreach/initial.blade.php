{{--
  Initial community-reuse outreach email - SELF-CONTAINED.

  Sent via the Gmail API from natalie-wagg@ilovefreegle.org as "Natalie @ Freegle"
  to a local organisation that is NOT a Freegle user. The recipient deals entirely
  by replying to this email: there are deliberately NO links to Freegle and no
  reference to the website. The items are shown inline (embedded photos + details)
  so the recipient never needs to go anywhere to see them.

  Layout: an intro paragraph, then ONE section per item in a long vertical list
  (NOT a table - tables render badly across email clients). Each item section shows
  its photo (a normal hotlinked <img>, not a heavy CID attachment), then the
  name / condition / details.

  Data contract (provided by the sender):
    $greetingName   string  - how to address them (contact, else org, else "there")
    $orgName        ?string - organisation name, for the intro + provenance line
    $area           ?string - their area, for the provenance line
    $intro          ?string - optional override for the intro paragraph
    $items          array   - [ ['name'=>string, 'condition'=>?string,
                                  'description'=>?string, 'photo'=>?string,
                                  'photo_full'=>?string], ... ]
                              photo is the item's image URL shown in the email (a
                              Freegle image-delivery URL is fine). photo_full is the
                              raw/high-res image the photo links to (just opens the
                              image, no offer page); defaults to photo. Omit photo
                              when the item has none.
    $signoffName    string  - "Natalie @ Freegle"
    $unsubscribeUrl string  - mailto:natalie-wagg+unsub@ilovefreegle.org?subject=unsubscribe
    $preview        string  - inbox preview text
--}}
<mjml>
  @include('emails.mjml.partials.head', ['preview' => $preview])
  <mj-body background-color="#ffffff">

    {{-- Intro --}}
    <mj-section padding="28px 24px 8px 24px">
      <mj-column>
        <mj-text font-size="16px" line-height="1.6" color="#333333">
          <p style="margin:0 0 14px 0;">Hello {{ $greetingName }},</p>
          @if(!empty($intro))
            <p style="margin:0 0 14px 0;">{{ $intro }}</p>
          @else
            <p style="margin:0 0 14px 0;">
              Some people near you are giving away the things below - all free, and the givers
              just want them to go to good use rather than to the tip. I thought
              {{ $orgName ?? 'you' }} might find some of them handy.
            </p>
            <p style="margin:0 0 4px 0;">
              Have a look - if anything would be useful, just reply to this email and tell me which,
              and I'll arrange collection with you. No sign-up or account needed.
            </p>
          @endif
          <p style="margin:14px 0 4px 0;">Thanks,</p>
          <p style="margin:0;"><strong>{{ $signoffName }}</strong></p>
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- One section per item: embedded photo, then details. A long vertical list. --}}
    @foreach($items as $item)
      <mj-section padding="8px 24px" css-class="outreach-item">
        <mj-column>
          @if(!empty($item['photo']))
            <mj-image src="{{ $item['photo'] }}" href="{{ $item['photo_full'] ?? $item['photo'] }}"
                      alt="{{ $item['name'] }}" align="left" padding="0 0 8px 0" border-radius="6px" />
          @endif
          <mj-text font-size="17px" line-height="1.4" color="#1d6607" font-weight="bold" padding="0">
            {{ $item['name'] }}@if(!empty($item['condition'])) <span style="color:#666666;font-weight:normal;font-size:14px;">({{ $item['condition'] }})</span>@endif
          </mj-text>
          @if(!empty($item['description']))
            <mj-text font-size="15px" line-height="1.5" color="#333333" padding="4px 0 0 0">
              {{ $item['description'] }}
            </mj-text>
          @endif
        </mj-column>
      </mj-section>
      <mj-section padding="0 24px">
        <mj-column>
          <mj-divider border-color="#eeeeee" border-width="1px" padding="8px 0" />
        </mj-column>
      </mj-section>
    @endforeach

    {{-- Provenance + unsubscribe (no Freegle links). --}}
    <mj-section background-color="#f5f5f5" padding="20px 24px">
      <mj-column>
        <mj-text font-size="12px" color="#666666" line-height="1.6">
          You're receiving this because we found {{ $orgName ?? 'your organisation' }}'s contact
          details published online{{ !empty($area) ? ' for '.$area : '' }}, and the items above are
          local to you. If they're not useful, no problem -
          <a href="{{ $unsubscribeUrl }}" style="color:#338808;font-weight:bold;text-decoration:none;">click here to stop receiving these emails</a>
          and we won't contact you again.
        </mj-text>
        <mj-divider border-color="#dddddd" border-width="1px" padding="14px 0"></mj-divider>
        <mj-text font-size="11px" color="#666666" line-height="1.5">
          {{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
          Registered address: {{ config('freegle.branding.registered_address') }}
        </mj-text>
      </mj-column>
    </mj-section>

  </mj-body>
</mjml>
