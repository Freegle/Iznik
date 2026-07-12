Hi {!! $name !!},

We haven't seen you on Freegle for a while, so to keep your inbox tidy we'll soon stop sending you these emails. No hard feelings if it's not for you right now!

If you'd still like to be part of your local community, just choose an option:

* Keep me freegling (logs you in and keeps your emails coming): {!! $findUrl !!}
* Email me less often (switch to an occasional round-up): {!! $settingsUrl !!}
@if($hasLocation && $offerCount > 0)

Just so you know — {{ number_format($offerCount) }} free {{ \Illuminate\Support\Str::plural('item', $offerCount) }} were offered near you this week. There's always something going.
@endif

Rather not? Unsubscribe and we'll stop emailing you: {!! $unsubscribeUrl !!}

—
This email was sent to {!! $email !!}.
{{ config('freegle.branding.name') }} is a charity run by volunteers.
