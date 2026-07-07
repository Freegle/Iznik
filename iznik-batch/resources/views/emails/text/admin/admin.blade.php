{!! $adminSubject !!}

{!! $adminText !!}
@if($ctaLink && $ctaText)

{{ $ctaText }}: {!! $ctaLink !!}
@endif
@if(isset($volunteers) && count($volunteers) > 0)

@if(count($volunteers) === 1)
Your local volunteer is {{ $volunteers[0]['firstname'] }}.
@elseif(count($volunteers) === 2)
Your local volunteers are {{ $volunteers[0]['firstname'] }} and {{ $volunteers[1]['firstname'] }}.
@else
Your local volunteers are {{ collect($volunteers)->slice(0, -1)->pluck('firstname')->implode(', ') }}, and {{ $volunteers[count($volunteers) - 1]['firstname'] }}.
@endif
@endif
@if($groupName)

---
This message was sent to members of {{ $groupName }} on {{ config('freegle.branding.name') }}.
@if($modsEmail)
Contact your group volunteers: {{ $modsEmail }}
@endif
@endif
@if($marketingOptOutUrl)

Don't want to receive these emails? Opt out: {!! $marketingOptOutUrl !!}
@endif

---
Change your email settings: {!! $settingsUrl !!}
