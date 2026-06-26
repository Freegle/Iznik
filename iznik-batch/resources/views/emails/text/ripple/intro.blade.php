@if(!empty($firstName))Hi {{ $firstName }},@else Hi,@endif

Good news - we've noticed that some of the nearby freeglers in neighbouring communities might be interested in your recent {{ config('freegle.branding.name') }} post, so we're showing it to them too.

THERE'S NOTHING YOU NEED TO DO - this just helps your post reach the right person.

So this works smoothly, we've added you to those communities - just as if you'd posted there yourself. That way people there can reply to you, and our volunteers can reach you about your post if they need to.

WE'VE KEPT THINGS CALM FOR YOU
- Posts from those communities come in your once-a-day digest, not as they happen - so your inbox won't fill up.
- Your community events and volunteering emails stay exactly as you set them.

YOU'RE IN CONTROL
- Change how often each community emails you any time in your settings: {{ $settingsUrl }}
- Leave any community you'd rather not be in - and we won't add you back. Leaving also removes your post from that community.
@if(!empty($welcomeGroups))

A WELCOME FROM THE COMMUNITIES YOUR POST REACHED
@foreach($welcomeGroups as $wg)
== {{ $wg['name'] }} ==
{{ $wg['welcome'] }}
@endforeach
@endif

--
This email was sent to {{ $email }}
Change your email settings: {{ $settingsUrl }}
Unsubscribe: {{ $unsubscribeUrl }}
