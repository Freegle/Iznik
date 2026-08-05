Hi {{ $recipientName ?? 'there' }},

@if($alreadyOff)
Thanks - you asked us to stop sending you {{ $whatTheyAskedFor }}. Those were already switched off, so there is nothing more to do.
@else
Thanks - we've turned off:
@foreach($turnedOff as $item)
- {{ $item }}
@endforeach
@endif

@if(count($stillOn))
You may still get some other kinds of email from us:
@foreach($stillOn as $item)
- {{ $item }}
@endforeach
@else
That's everything switched off. We'll only email you if you ask us to, or about something essential like resetting your password.
@endif

You can turn any of these back on, or stop everything, from your settings:
{{ $settingsUrl }}

You don't need to reply to this email - nobody reads that mailbox.

------------------------------------

{{ config('freegle.branding.name') }} - Don't throw it away, give it away!
{{ config('freegle.sites.user') }}

{{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers.
Registered address: {{ config('freegle.branding.registered_address') }}
