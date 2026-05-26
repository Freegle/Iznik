<mjml>
  @if($isMod2Mod ?? false)
  @include('emails.mjml.partials.head', ['preview' => $senderName . ' sent a message in Volunteer Chat'])
  @elseif($isModerator ?? false)
  @include('emails.mjml.partials.head', ['preview' => 'Member conversation with ' . ($memberName ?? 'a member')])
  @elseif($isOwnMessage ?? false)
  @include('emails.mjml.partials.head', ['preview' => 'Copy of your message to ' . ($otherUserName ?? 'the other user')])
  @else
  @include('emails.mjml.partials.head', ['preview' => $senderName . ' sent you a message'])
  @endif
  <mj-body background-color="#ffffff">
    {{-- Header - ModTools blue for moderators, Freegle green for members --}}
    <mj-section mj-class="{{ ($isModerator ?? false) ? 'bg-modtools' : 'bg-success' }}" padding="20px">
      <mj-column>
        <mj-text font-size="24px" font-weight="bold" color="#ffffff" align="center">
          @if($isMod2Mod ?? false)
          Volunteer Chat: {{ $groupShortName ?? 'Freegle' }}
          @elseif($isModerator ?? false)
          Member conversation on {{ $groupShortName ?? 'Freegle' }}
          @elseif($isOwnMessage ?? false)
          Copy of your message to {{ $otherUserName ?? 'the other user' }}
          @elseif($isUser2Mod ?? false)
          Reply from {{ $groupName ?? 'Freegle' }} volunteers
          @else
          New message from {{ $senderName }}
          @endif
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Member info bar for moderators (not for Mod2Mod chats) --}}
    @if(($isModerator ?? false) && !($isMod2Mod ?? false))
    <mj-section background-color="#e8f4fc" padding="12px 20px">
      <mj-column>
        <mj-text font-size="14px" color="#396aa3" align="center" padding="0">
          Member: <strong>{{ $memberName ?? 'Unknown' }}</strong>
          @if($memberProfileUrl)
          <a href="{{ $memberProfileUrl }}" style="color: #396aa3; font-weight: normal;">#{{ $member->id }}</a>
          @endif
          @if(!empty($member?->email_preferred))
          ({{ $member->email_preferred }})
          @endif
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- RSVP banner if reply expected --}}
    @if($replyExpected)
    <mj-section mj-class="bg-danger" padding="12px 20px">
      <mj-column>
        <mj-text font-size="16px" font-weight="bold" color="#ffffff" padding="0" align="center">
          🔔 Reply requested - please respond
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- Referenced item --}}
    @if($refMessage)
    <mj-section mj-class="bg-light" padding="15px 20px {{ $refMessageImageUrl ? '10px' : '15px' }} 20px">
      <mj-column>
        <mj-text font-size="13px" color="#666666" align="center" padding-bottom="4px">
          @if($isRecipientPoster)
            About your post
          @else
            Re: {{ $refMessage->subject }}
          @endif
        </mj-text>
        @if($isRecipientPoster)
        <mj-text font-size="16px" font-weight="600" color="#333333" align="center">
          {{ $refMessage->subject }}
        </mj-text>
        @endif
      </mj-column>
    </mj-section>
    @if($refMessageImageUrl)
    <mj-section mj-class="bg-light" padding="5px 20px 15px 20px">
      <mj-column>
        <mj-image
          src="{{ $refMessageImageUrl }}"
          width="200px"
          alt="{{ $refMessage->subject }}"
          padding="0"
          border-radius="8px"
        />
      </mj-column>
    </mj-section>
    @endif
    <mj-section padding="0">
      <mj-column>
        <mj-divider border-color="#e9ecef" border-width="2px" padding="0" />
      </mj-column>
    </mj-section>
    @endif

    {{-- New message section label --}}
    <mj-section background-color="#ffffff" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="12px" font-weight="bold" mj-class="{{ ($isModerator ?? false) ? 'text-modtools' : 'text-success' }}" text-transform="uppercase" letter-spacing="1px">
          @if($isOwnMessage ?? false)
          Your message
          @elseif($isMod2Mod ?? false)
          Message from {{ $senderName }}
          @elseif($isModerator ?? false)
            @if($senderIsMember ?? true)
          Message from member
            @else
          Message from volunteer
            @endif
          @endif
          {{-- For member User2Mod view, no label needed - header already says "Reply from volunteers" --}}
        </mj-text>
      </mj-column>
    </mj-section>

    @php $accentColor = ($isModerator ?? false) ? '#396aa3' : '#338808'; @endphp
    {{-- Message - always left-justified for consistent layout --}}
    <mj-section background-color="#ffffff" padding="8px 20px">
      <mj-column width="44px" vertical-align="top">
        <mj-image
          src="{{ $chatMessage['profileUrl'] }}"
          width="36px"
          height="36px"
          border-radius="18px"
          alt="{{ $chatMessage['userName'] }}"
          padding="0"
          href="{{ $chatMessage['userPageUrl'] ?? '' }}"
        />
      </mj-column>
      <mj-column vertical-align="top">
        <mj-text padding="0 0 0 8px" line-height="1.6">
          @if($chatMessage['userPageUrl'])
          <a href="{{ $chatMessage['userPageUrl'] }}" style="font-weight: 600; color: #333333; font-size: 14px; text-decoration: none;">{{ $chatMessage['userName'] }}</a>
          @else
          <span style="font-weight: 600; color: #333333; font-size: 14px;">{{ $chatMessage['userName'] }}</span>
          @endif
          <br/>
          <span style="color: #000000; font-size: 18px; font-weight: 500;">{!! nl2br(e($chatMessage['text'])) !!}</span>
        </mj-text>
      </mj-column>
    </mj-section>

    {{-- Show referenced item if this message refers to one --}}
    @if(!empty($chatMessage['refMessage']))
    <mj-section background-color="#ffffff" padding="5px 20px">
      {{-- Spacer column to align with profile image --}}
      <mj-column width="44px" vertical-align="top">
        <mj-text padding="0">&nbsp;</mj-text>
      </mj-column>
      {{-- Item summary --}}
      @if($chatMessage['refMessage']['imageUrl'])
      <mj-column width="55px" vertical-align="middle" background-color="#f8f9fa" border-radius="8px 0 0 8px" padding="8px 0 8px 8px">
        <mj-image
          src="{{ $chatMessage['refMessage']['imageUrl'] }}"
          width="45px"
          height="45px"
          alt="{{ $chatMessage['refMessage']['subject'] }}"
          padding="0"
          border-radius="4px"
          href="{{ $chatMessage['refMessage']['url'] }}"
        />
      </mj-column>
      <mj-column vertical-align="middle" background-color="#f8f9fa" border-radius="0 8px 8px 0" padding="8px 8px 8px 0">
        <mj-text padding="0 0 0 8px" line-height="1.4">
          <a href="{{ $chatMessage['refMessage']['url'] }}" style="color: {{ $accentColor }}; font-weight: 600; font-size: 13px; text-decoration: none;">
            {{ $chatMessage['refMessage']['subject'] }}
          </a>
        </mj-text>
      </mj-column>
      @else
      <mj-column vertical-align="middle" background-color="#f8f9fa" border-radius="8px" padding="8px">
        <mj-text padding="0" line-height="1.4">
          <a href="{{ $chatMessage['refMessage']['url'] }}" style="color: {{ $accentColor }}; font-weight: 600; font-size: 13px; text-decoration: none;">
            {{ $chatMessage['refMessage']['subject'] }}
          </a>
        </mj-text>
      </mj-column>
      @endif
    </mj-section>
    @endif

    @if($chatMessage['imageUrl'])
    <mj-section background-color="#ffffff" padding="5px 20px 10px {{ $chatMessage['isFromRecipient'] ? '20px' : '52px' }}">
      <mj-column>
        <mj-image
          src="{{ $chatMessage['imageUrl'] }}"
          width="200px"
          alt="Shared image"
          padding="0"
        />
      </mj-column>
    </mj-section>
    @endif

    {{-- Google Maps link for address messages --}}
    @if(!empty($chatMessage['mapUrl']))
    <mj-section background-color="#ffffff" padding="5px 20px 15px 20px">
      @if(!$chatMessage['isFromRecipient'])
      {{-- Spacer column to align with profile image --}}
      <mj-column width="44px" vertical-align="top">
        <mj-text padding="0">&nbsp;</mj-text>
      </mj-column>
      @endif
      <mj-column vertical-align="top">
        <mj-text padding="0 0 0 {{ $chatMessage['isFromRecipient'] ? '0' : '8px' }}" font-size="14px" align="{{ $chatMessage['isFromRecipient'] ? 'right' : 'left' }}">
          <a href="{{ $chatMessage['mapUrl'] }}" style="color: {{ $accentColor }}; text-decoration: none;">
            📍 View in Google Maps
          </a>
        </mj-text>
      </mj-column>
      @if($chatMessage['isFromRecipient'])
      {{-- Spacer column for right-aligned messages --}}
      <mj-column width="44px" vertical-align="top">
        <mj-text padding="0">&nbsp;</mj-text>
      </mj-column>
      @endif
    </mj-section>
    @endif

    {{-- Reply button --}}
    <mj-section background-color="#ffffff" padding="25px 20px">
      <mj-column>
        <mj-button href="{{ $chatUrl }}" mj-class="{{ ($isModerator ?? false) ? 'btn-modtools' : 'btn-success' }}" font-size="18px" padding="14px 40px">
          @if($isOwnMessage ?? false)
          View conversation
          @elseif($isMod2Mod ?? false)
          Reply to volunteers
          @elseif($isModerator ?? false)
          Reply to member
          @elseif($isUser2Mod ?? false)
          Reply to volunteers
          @else
          Reply to {{ $senderName }}
          @endif
        </mj-button>
      </mj-column>
    </mj-section>

    {{-- Outcome buttons for OFFER items --}}
    @if($showOutcomeButtons && !empty($outcomeUrls))
    <mj-section mj-class="bg-light" padding="15px 20px">
      <mj-column>
        <mj-text font-size="13px" color="#666666" align="center" padding-bottom="10px">
          Has this item gone?
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section mj-class="bg-light" padding="0 20px 20px 20px">
      <mj-column width="50%">
        <mj-button href="{{ $outcomeUrls['taken'] ?? '#' }}" mj-class="btn-success" font-size="14px" padding="12px 20px" width="90%">
          Mark as TAKEN
        </mj-button>
      </mj-column>
      <mj-column width="50%">
        <mj-button href="{{ $outcomeUrls['withdrawn'] ?? '#' }}" background-color="#6c757d" color="#ffffff" font-size="14px" padding="12px 20px" width="90%">
          Withdraw post
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    {{-- Earlier conversation --}}
    @if($previousMessages->isNotEmpty())
    <mj-section mj-class="bg-light" padding="20px 20px 10px 20px">
      <mj-column>
        <mj-text font-size="12px" font-weight="bold" color="#666666" text-transform="uppercase" letter-spacing="1px">
          Earlier in this conversation
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach($previousMessages as $prevMessage)
    <mj-section mj-class="bg-light" padding="8px 20px">
      <mj-column width="36px" vertical-align="top">
        <mj-image
          src="{{ $prevMessage['profileUrl'] }}"
          width="28px"
          height="28px"
          border-radius="14px"
          alt="{{ $prevMessage['userName'] }}"
          padding="0"
          href="{{ $prevMessage['userPageUrl'] ?? '' }}"
        />
      </mj-column>
      <mj-column vertical-align="top">
        <mj-text padding="0 0 0 8px" line-height="1.4">
          @if($prevMessage['userPageUrl'])
          <a href="{{ $prevMessage['userPageUrl'] }}" style="font-weight: 600; color: #555555; font-size: 13px; text-decoration: none;">{{ $prevMessage['userName'] }}</a>
          @else
          <span style="font-weight: 600; color: #555555; font-size: 13px;">{{ $prevMessage['userName'] }}</span>
          @endif
          <span style="color: #888888; font-size: 12px;"> · {{ $prevMessage['formattedDate'] }}</span>
          <br/>
          <span style="color: #666666; font-size: 14px;">{!! nl2br(e($prevMessage['text'])) !!}</span>
        </mj-text>
      </mj-column>
    </mj-section>
    {{-- Show referenced item for previous message --}}
    @if(!empty($prevMessage['refMessage']))
    <mj-section mj-class="bg-light" padding="4px 20px 8px 20px">
      {{-- Spacer column to align with profile image --}}
      <mj-column width="36px" vertical-align="top">
        <mj-text padding="0">&nbsp;</mj-text>
      </mj-column>
      {{-- Item summary --}}
      @if($prevMessage['refMessage']['imageUrl'])
      <mj-column width="45px" vertical-align="middle" background-color="#e9ecef" border-radius="6px 0 0 6px" padding="6px 0 6px 6px">
        <mj-image
          src="{{ $prevMessage['refMessage']['imageUrl'] }}"
          width="36px"
          height="36px"
          alt="{{ $prevMessage['refMessage']['subject'] }}"
          padding="0"
          border-radius="4px"
          href="{{ $prevMessage['refMessage']['url'] }}"
        />
      </mj-column>
      <mj-column vertical-align="middle" background-color="#e9ecef" border-radius="0 6px 6px 0" padding="6px 6px 6px 0">
        <mj-text padding="0 0 0 6px" line-height="1.3">
          <a href="{{ $prevMessage['refMessage']['url'] }}" style="color: {{ $accentColor }}; font-size: 12px; text-decoration: none;">
            {{ $prevMessage['refMessage']['subject'] }}
          </a>
        </mj-text>
      </mj-column>
      @else
      <mj-column vertical-align="middle" background-color="#e9ecef" border-radius="6px" padding="6px">
        <mj-text padding="0" line-height="1.3">
          <a href="{{ $prevMessage['refMessage']['url'] }}" style="color: {{ $accentColor }}; font-size: 12px; text-decoration: none;">
            {{ $prevMessage['refMessage']['subject'] }}
          </a>
        </mj-text>
      </mj-column>
      @endif
    </mj-section>
    @endif
    @endforeach

    <mj-section mj-class="bg-light" padding="10px 20px 20px 20px">
      <mj-column>
        <mj-button href="{{ $chatUrl }}" background-color="transparent" mj-class="{{ ($isModerator ?? false) ? 'text-modtools' : 'text-success' }}" font-size="14px" padding="8px 20px" border="1px solid {{ $accentColor }}">
          View full conversation
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    {{-- About sender - hide for own message notifications --}}
    @if($sender && $sender->aboutme && !($isOwnMessage ?? false))
    <mj-section background-color="#ffffff" padding="20px" border-top="1px solid #e9ecef">
      <mj-column>
        <mj-text font-size="12px" font-weight="bold" color="#666666" text-transform="uppercase" letter-spacing="1px" padding-bottom="10px">
          About {{ $senderName }}
        </mj-text>
        <mj-text font-size="14px" color="#555555" font-style="italic" line-height="1.5">
          "{{ $sender->aboutme }}"
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    {{-- Jobs section --}}
    @if($jobAds->isNotEmpty())
    <mj-section mj-class="bg-light" padding="20px 20px 10px 20px" border-top="1px solid #e9ecef">
      <mj-column>
        <mj-text font-size="16px" font-weight="bold" color="#333333" align="center" padding-bottom="10px">
          Jobs near you
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section mj-class="bg-light" padding="5px 20px">
      <mj-column>
        <mj-table cellpadding="0" cellspacing="0" width="100%">
          @foreach($jobAds as $job)
          <tr>
            @if($job->image_url)
            <td style="width: 50px; padding: 6px 8px 6px 0; vertical-align: middle;">
              <a href="{{ $job->tracked_url }}">
                <img src="{{ $job->image_url }}" width="40" height="40" alt="" style="border-radius: 4px; display: block;" />
              </a>
            </td>
            <td style="padding: 6px 0; vertical-align: middle;">
            @else
            {{-- No image: span both columns so the title fills the row instead of
                 being squeezed into the right column reserved by sibling rows. --}}
            <td colspan="2" style="padding: 6px 0; vertical-align: middle;">
            @endif
              <a href="{{ $job->tracked_url }}" style="color: {{ $accentColor }}; font-weight: bold; text-decoration: none; font-size: 14px;">
                {{ $job->title }}
              </a>
              @if($job->location)
              <br/><span style="color: #666666; font-size: 12px;">{{ $job->location }}</span>
              @endif
            </td>
          </tr>
          @endforeach
        </mj-table>
      </mj-column>
    </mj-section>
    <mj-section mj-class="bg-light" padding="0 20px 10px 20px">
      <mj-column>
        <mj-text font-size="12px" color="#666666" line-height="1.4">
          If you are interested and click, it will raise a little to help keep Freegle running and free to use.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section mj-class="bg-light" padding="0 20px 20px 20px">
      <mj-column width="50%">
        <mj-button href="{{ $jobsUrl }}" mj-class="{{ ($isModerator ?? false) ? 'btn-modtools' : 'btn-secondary' }}" font-size="14px" padding="12px 20px" width="90%">
          View more jobs
        </mj-button>
      </mj-column>
      <mj-column width="50%">
        {{-- Keep donate button green - it's for Freegle charity --}}
        <mj-button href="{{ $donateUrl }}" mj-class="btn-success" font-size="14px" padding="12px 20px" width="90%">
          Donating helps too!
        </mj-button>
      </mj-column>
    </mj-section>
    @endif

    {{-- Footer --}}
    @include('emails.mjml.partials.footer', ['email' => $recipient->email_preferred, 'settingsUrl' => $settingsUrl, 'unsubscribeUrl' => $unsubscribeUrl, 'ampIncluded' => $ampIncluded ?? false])

    {{-- Tracking pixel --}}
    @if(!empty($trackingPixelHtml))
    <mj-section padding="0">
      <mj-column>
        <mj-text padding="0">{!! $trackingPixelHtml !!}</mj-text>
      </mj-column>
    </mj-section>
    @endif
  </mj-body>
</mjml>
