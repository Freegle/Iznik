Hi {!! $user->displayname ?? 'there' !!},

@if($itemSubject)
Did you just get this from Freegle?

{!! $itemSubject !!}

(If we're wrong, just delete this message.)

If you've not already, why not send a thanks to the person who gave it? Just to be nice. And you can also give them a Thumbs Up in the Chat window.
@else
Thank you for using your local Freegle group.
@endif

Freegle is free to use, but it's not free to run. This month we're trying to raise GBP {{ number_format($target) }} to keep us going.

If you can, please consider donating GBP 1 to help support Freegle:
{!! $donateUrl !!}

We realise not everyone is able to do this - and that's fine.

Either way, thanks for freegling!

Continue freegling: {!! $continueUrl !!}

---
To change your notification settings: {!! $settingsUrl !!}
