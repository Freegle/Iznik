Hi {!! $name !!},

Getting started on Freegle - tip {{ $day }} of {{ $totalDays }}: {!! $heading !!}

@if(!empty($intro)){!! $intro !!}

@endif
@foreach(($body ?? []) as $para){!! strip_tags($para) !!}

@endforeach
@if(!empty($highlight)){!! $highlight['title'] !!}:
@foreach($highlight['items'] as $item)- {!! strip_tags($item) !!}
@endforeach

@endif
{!! $ctaLabel !!}: {!! $ctaUrl !!}

@if(!empty($volunteerName))Happy freegling,
{!! $volunteerName !!}
Your local Freegle volunteer{{ !empty($volunteerGroup) ? ', ' . $volunteerGroup : '' }}
@else
Happy freegling,
The Freegle team
@endif

—
This email was sent to {!! $email !!}.
Change your email settings: {!! $settingsUrl !!}
Unsubscribe: {!! $unsubscribeUrl !!}
{{ config('freegle.branding.name') }} is a charity run by volunteers.
