We love your stories!

It's great to hear why people freegle — and here are some recent tales from other freeglers.
Be inspired — tell us your story, or get freegling!

---

@foreach ($stories as $story)
{!! $story['headline'] !!}
@if (!empty($story['username']) && !empty($story['groupname']))
{!! $story['username'] !!} · {!! $story['groupname'] !!}
@elseif (!empty($story['username']))
{!! $story['username'] !!}
@elseif (!empty($story['groupname']))
From a freegler on {!! $story['groupname'] !!}.
@endif

{!! $story['story'] !!}

---

@endforeach

Tell your story: {!! $tellUrl !!}
Give something: {!! $giveUrl !!}
Find something: {!! $findUrl !!}

---

This email was sent to {!! $email !!}.
Change your email settings: {!! $settingsUrl !!}
Unsubscribe: {!! $unsubscribeUrl !!}

Freegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.
Registered address: {{ config('freegle.branding.registered_address') }}
