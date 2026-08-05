Hi {{ $recipientName ?? 'there' }},

@if($alreadyOff)
Thanks - you asked us to stop sending you {{ $whatTheyAskedFor }}. Those were already switched off, so there was nothing to change.
@else
Thanks. We've turned off:
@foreach($turnedOff as $item)
- {{ $item }}
@endforeach
@endif

@if($everythingAlreadyOff)
That's everything switched off. We'll only email you if you ask us to, or about something essential like resetting your password.
@else
You may still get these from us:
@foreach($stillOn as $item)
- {{ $item }}
@endforeach
@if($canStopMore)

Want to stop the rest too? One tap, no need to log in. You'll still hear when someone replies to your posts, so you won't miss anyone:
{{ $stopMostUrl }}
@endif
@endif

You can turn any of these back on, or change them one by one, in your settings:
{{ $settingsUrl }}

Want to leave Freegle altogether, or leave one of your communities?
{{ $leaveUrl }}

There's no need to reply to this email - nobody reads that mailbox.

------------------------------------

{{ config('freegle.branding.name') }} - Don't throw it away, give it away!
{{ config('freegle.sites.user') }}

{{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers.
Registered address: {{ config('freegle.branding.registered_address') }}
