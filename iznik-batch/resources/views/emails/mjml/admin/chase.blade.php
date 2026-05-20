<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Action needed: Pending suggested admin for ' . $groupName])
  <mj-body background-color="#ffffff">
    {{-- Header - ModTools blue --}}
    <mj-section mj-class="bg-modtools" padding="20px">
      <mj-column>
        <mj-text font-size="24px" font-weight="bold" color="#ffffff" align="center">
          Action needed
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Main content --}}
    <mj-section background-color="#ffffff" padding="25px 20px 15px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          Dear {{ $userName }},
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 15px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          There is a pending suggested admin for <strong>{{ $groupName }}</strong> that has been waiting for <strong>{{ $pendingTimeText }}</strong> without being approved, rejected, or held.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Admin subject --}}
    <mj-section mj-class="bg-light" padding="15px 20px">
      <mj-column>
        <mj-text font-size="14px" color="#333333" line-height="1.5">
          <strong>Subject:</strong> {{ $adminSubject }}
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Call to action --}}
    <mj-section background-color="#ffffff" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" line-height="1.6" font-weight="bold">
          Please don't assume that somebody else will deal with it.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 15px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          You might be waiting for another moderator to handle this, but if so, please check with them whether they're going to do it &mdash; it's been hanging around for a while now.
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 15px 20px">
      <mj-column>
        <mj-text font-size="16px" color="#333333" line-height="1.6">
          Please log into ModTools and approve, reject, or hold this admin.
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Button --}}
    <mj-section background-color="#ffffff" padding="10px 20px 30px 20px">
      <mj-column>
        <mj-button href="{{ $modToolsUrl }}" mj-class="btn-modtools" font-size="18px" padding="14px 40px">
          View pending admins
        </mj-button>
      </mj-column>
    </mj-section>

    @if(!empty($trackingPixelMjml))
    <mj-section padding="0">
      <mj-column>
        {!! $trackingPixelMjml !!}
      </mj-column>
    </mj-section>
    @endif

    {{-- Footer --}}
    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text font-size="12px" color="#666666" align="center" line-height="1.6">
          This is an automated reminder from {{ $siteName ?? 'Freegle' }}.<br/>
          You are receiving this because you are a moderator of {{ $groupName }}.
        </mj-text>
        <mj-divider border-color="#ddd" border-width="1px" padding="15px 40px"></mj-divider>
        <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
          {{ $siteName ?? 'Freegle' }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.<br/>
          Registered address: {{ config('freegle.branding.registered_address') }}
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
