@if($isModerator ?? false)
Member conversation on {!! $groupShortName ?? 'Freegle' !!}

Member: {!! $memberName ?? 'Unknown' !!}@if($member?->email_preferred) ({!! $member->email_preferred !!})@endif

@else
New message from {!! $senderName !!}
@endif
@if($replyExpected)

⏰ Reply requested - please respond
@endif
@if($refMessage)

About your post: {!! $refMessage->subject !!}
@endif

----------------------------------------
{!! $chatMessage['userName'] !!}{{ $chatMessage['isFromRecipient'] ? ' (you)' : '' }}:
{!! $chatMessage['text'] !!}
@if(!empty($chatMessage['refMessage']))
{{ $chatMessage['refMessage']['subject'] }}
@endif
@if($chatMessage['imageUrl'])
[Image: {{ $chatMessage['imageUrl'] }}]
@endif

----------------------------------------

@if($isModerator ?? false)
Reply to member: {{ $chatUrl }}
@else
Reply to {!! $senderName !!}: {{ $chatUrl }}
@endif
@if($showOutcomeButtons && !empty($outcomeUrls))

Has this item gone?
- Mark as TAKEN: {{ $outcomeUrls['taken'] ?? '' }}
- Withdraw post: {{ $outcomeUrls['withdrawn'] ?? '' }}
@endif
@if($previousMessages->isNotEmpty())

Earlier in this conversation:
@foreach($previousMessages as $prevMessage)
{!! $prevMessage['userName'] !!} ({{ $prevMessage['formattedDate'] }}): {!! $prevMessage['text'] !!}
@if(!empty($prevMessage['refMessage']))
  {{ $prevMessage['refMessage']['subject'] }}
@endif
@endforeach
@endif
@if($sender && $sender->aboutme)

About {!! $senderName !!}:
"{!! $sender->aboutme !!}"
@endif
@if($jobAds->isNotEmpty())

Jobs near you:
@foreach($jobAds as $job)
- {!! $job->title !!}@if($job->location) ({!! $job->location !!})@endif
  {{ config('freegle.sites.user') }}/job/{{ $job->id }}
@endforeach

View more jobs: {{ $jobsUrl }}
@endif

--
This email was sent to {{ $recipient->email_preferred }}
Change your settings: {{ $settingsUrl }}

{{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers.
