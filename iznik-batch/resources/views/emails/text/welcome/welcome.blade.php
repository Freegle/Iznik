Welcome{{ $firstName ? ', ' . $firstName : '' }}!

You're now part of the {{ config('freegle.branding.name') }} community.

@if($password)
--------------------------------------------------
IMPORTANT: Your password is: {{ $password }}
Save this password - you'll need it to log in.
--------------------------------------------------

@endif
Ready to start freegling?

Give stuff away: {{ $giveUrl }}
Find what you need: {{ $findUrl }}


THREE SIMPLE RULES
------------------

1. Everything must be FREE and LEGAL
   {{ $termsUrl }}

2. Be NICE to other freeglers
   {{ $helpUrl }}

3. Stay SAFE when meeting up
   {{ $safetyUrl }}


Happy freegling!

--
This email was sent to {{ $email }}
Change your email settings: {{ $settingsUrl }}

{{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.
Registered address: {{ config('freegle.branding.registered_address') }}
