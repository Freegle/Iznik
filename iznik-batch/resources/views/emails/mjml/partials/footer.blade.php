<mj-section background-color="#f5f5f5" padding="20px">
  <mj-column>
    <mj-text font-size="12px" color="#666666" align="center" line-height="1.6">
      This email was sent{{ !empty($ampIncluded) ? ' with AMP' : '' }} to {{ $email }}<br/>
      <a href="{{ $settingsUrl ?? config('freegle.sites.user') . '/settings' }}" style="color: #338808; font-weight: bold; text-decoration: none;">Change your email settings</a> &bull;
      <a href="{{ $unsubscribeUrl ?? config('freegle.sites.user') . '/unsubscribe' }}" style="color: #338808; font-weight: bold; text-decoration: none;">Unsubscribe</a>
    </mj-text>
    <mj-divider border-color="#ddd" border-width="1px" padding="15px 40px"></mj-divider>
    <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
      {{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
      Registered address: 64a North Road, Ormesby, Great Yarmouth, Norfolk NR29 3LE
    </mj-text>
  </mj-column>
</mj-section>
