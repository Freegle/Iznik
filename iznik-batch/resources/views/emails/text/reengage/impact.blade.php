Hi {{ $name }},

While you've been away, real people near you have been giving and getting.
@if(!empty($faces))

A few of your neighbours freegling this month:
@foreach(array_slice($faces, 0, 5) as $face)
- {{ $face['name'] }}
@endforeach
@endif
@if(!empty($offers))

Some of what's been shared near you:
@foreach(array_slice($offers, 0, 4) as $offer)
- {{ $offer['subject'] }}: {{ $offer['url'] }}
@endforeach
@endif

Together they're keeping good stuff out of landfill — one freegle at a time.

Join back in: {{ $findUrl }}
Offer something: {{ $giveUrl }}

—
This email was sent to {{ $email }}.
Change your email settings: {{ $settingsUrl }}
Unsubscribe: {{ $unsubscribeUrl }}
{{ config('freegle.branding.name') }} is a charity run by volunteers.
