<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Volunteer opportunities in ' . $groupName])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        <mj-text font-size="22px" font-weight="bold" mj-class="text-success">
          Volunteer Opportunity Roundup
        </mj-text>
        <mj-text font-size="14px" color="#555555">
          Charities, community organisations and good causes in {{ $groupName }} are looking for helpers.
          If you'd like to add one, <a href="{{ $userSite }}/volunteering">click here</a>.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($volunteerings as $vol)
    <mj-section background-color="#ffffff" padding="0 20px 0">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="16px 20px 8px">
      <mj-column>
        <mj-text font-size="18px" font-weight="bold" mj-class="text-success" padding="0 0 4px">
          <a href="{{ $vol['url'] }}" style="color: inherit; text-decoration: none;">{{ $vol['title'] }}</a>
        </mj-text>
        @if (!empty($vol['location']))
        <mj-text font-size="13px" color="#666666" padding="0 0 6px">
          📍 {{ $vol['location'] }}
        </mj-text>
        @endif
        @if (!empty($vol['description']))
        <mj-text font-size="14px" color="#333333" padding="0 0 10px" line-height="1.5">
          {!! nl2br(e(mb_strlen($vol['description']) > 300 ? mb_substr($vol['description'], 0, 300) . '...' : $vol['description'])) !!}
        </mj-text>
        @endif
        @if (!empty($vol['timecommitment']))
        <mj-text font-size="13px" color="#555555" padding="0 0 6px">
          <strong>Time commitment:</strong> {!! nl2br(e($vol['timecommitment'])) !!}
        </mj-text>
        @endif
        @if (!empty($vol['contactname']) || !empty($vol['contactphone']) || !empty($vol['contactemail']) || !empty($vol['contacturl']))
        <mj-text font-size="13px" color="#555555" padding="0 0 6px">
          <strong>Contact:</strong>
          @if (!empty($vol['contactname'])){{ $vol['contactname'] }}@endif
          @if (!empty($vol['contactphone'])) &nbsp;·&nbsp; <a href="tel:{{ $vol['contactphone'] }}">{{ $vol['contactphone'] }}</a>@endif
          @if (!empty($vol['contactemail'])) &nbsp;·&nbsp; <a href="mailto:{{ $vol['contactemail'] }}">{{ $vol['contactemail'] }}</a>@endif
          @if (!empty($vol['contacturl'])) &nbsp;·&nbsp; <a href="{{ $vol['contacturl'] }}">Website</a>@endif
        </mj-text>
        @endif
        <mj-button mj-class="btn-success" href="{{ $vol['url'] }}" font-size="13px" inner-padding="8px 16px" align="left">
          Find out more
        </mj-button>
      </mj-column>
    </mj-section>
    @endforeach

    <mj-section background-color="#ffffff" padding="16px 20px 20px">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0 0 16px" />
        <mj-button href="{{ $userSite }}/volunteering" mj-class="btn-success" border-radius="3px" font-size="16px">
          View all volunteering opportunities
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Jobs section --}}
    @if(!empty($jobAds) && $jobAds->isNotEmpty())
    <mj-section background-color="#f8f9fa" padding="20px 20px 10px 20px" border-top="1px solid #e9ecef">
      <mj-column>
        <mj-text font-size="16px" font-weight="bold" color="#333333" align="center" padding-bottom="10px">
          Jobs near you
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#f8f9fa" padding="5px 20px">
      <mj-column>
        <mj-table cellpadding="0" cellspacing="0" width="100%">
          @foreach($jobAds as $job)
          <tr>
            @if(!empty($job->image_url))
            <td style="width: 50px; padding: 6px 8px 6px 0; vertical-align: middle;">
              <a href="{{ $job->tracked_url }}">
                <img src="{{ $job->image_url }}" width="40" height="40" alt="" style="border-radius: 4px; display: block;" />
              </a>
            </td>
            @endif
            <td style="padding: 6px 0; vertical-align: middle;">
              <a href="{{ $job->tracked_url }}" style="color: #2e7d32; font-weight: bold; text-decoration: none; font-size: 14px;">
                {{ $job->title }}
              </a>
              @if(!empty($job->location))
              <br/><span style="color: #666666; font-size: 12px;">{{ $job->location }}</span>
              @endif
            </td>
          </tr>
          @endforeach
        </mj-table>
      </mj-column>
    </mj-section>
    <mj-section background-color="#f8f9fa" padding="0 20px 10px 20px">
      <mj-column>
        <mj-text font-size="12px" color="#666666" line-height="1.4">
          If you are interested and click, it will raise a little to help keep Freegle running and free to use.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#f8f9fa" padding="0 20px 20px 20px">
      <mj-column width="50%">
        <mj-button href="{{ $jobsUrl }}" mj-class="btn-secondary" font-size="14px" padding="12px 20px" width="90%">
          View more jobs
        </mj-button>
      </mj-column>
      <mj-column width="50%">
        <mj-button href="{{ $donateUrl }}" mj-class="btn-success" font-size="14px" padding="12px 20px" width="90%">
          Donating helps too!
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    @include('emails.mjml.partials.footer', [
      'unsubscribeUrl' => $unsubscribeUrl,
      'email'          => $email,
    ])

  </mj-body>
</mjml>
