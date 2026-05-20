<mj-section background-color="#f5f5f5" padding="20px">
  <mj-column>
    <mj-text font-size="12px" color="#666666" align="center" line-height="1.6">
      This email was sent to {{ $email }}<br/>
      <a href="{{ $settingsUrl ?? config('freegle.sites.mod') . '/modtools/settings' }}" style="color: #396aa3;">Change your ModTools email settings</a>
    </mj-text>
    <mj-divider border-color="#ddd" border-width="1px" padding="15px 40px"></mj-divider>
    <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
      {{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
      Registered address: {{ config('freegle.branding.registered_address') }}
    </mj-text>
  </mj-column>
</mj-section>
