Hi {!! $name !!},

It's been a little while!
@if($hasLocation && $offerCount > 0)
Your neighbours have been busy — {{ number_format($offerCount) }} free {{ \Illuminate\Support\Str::plural('item', $offerCount) }} have been offered near you in the last week alone.
@else
People near you are still giving away good stuff every day.
@endif

@foreach($offers as $offer)
- {!! $offer['subject'] !!}: {!! $offer['url'] !!}
@endforeach

See what's near you: {!! $findUrl !!}
Got something to clear out? Give it away for free: {!! $giveUrl !!}

—
This email was sent to {!! $email !!}.
Change your email settings: {!! $settingsUrl !!}
Unsubscribe: {!! $unsubscribeUrl !!}
{{ config('freegle.branding.name') }} is a charity run by volunteers.
