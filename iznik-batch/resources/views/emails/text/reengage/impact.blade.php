Hi {{ $name }},

While you've been away, freeglers near {{ $areaLabel }} have kept perfectly good things out of the bin and in the hands of people who needed them.
@if($hasImpact)

In the last month, across {{ $impactGroups }} local {{ \Illuminate\Support\Str::plural('group', $impactGroups) }} you're part of:
- {{ number_format($impactOutcomes) }} things given a new home
- {{ number_format($impactWeightKg) }}kg kept out of landfill
@endif
@if(!empty($offers))

Up for grabs near you right now:
@foreach(array_slice($offers, 0, 3) as $offer)
- {{ $offer['subject'] }}: {{ $offer['url'] }}
@endforeach
@endif

Join back in: {{ $findUrl }}
Offer something: {{ $giveUrl }}

—
This email was sent to {{ $email }}.
Change your email settings: {{ $settingsUrl }}
Unsubscribe: {{ $unsubscribeUrl }}
{{ config('freegle.branding.name') }} is a charity run by volunteers.
