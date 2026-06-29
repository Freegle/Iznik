Hello {{ $greetingName }},
@if(!empty($intro))

{{ $intro }}
@else

Some people near you are giving away the things below - all free, and the givers just want them to go to good use rather than to the tip. I thought {{ $orgName ?? 'you' }} might find some of them handy.

Have a look - if anything would be useful, just reply to this email and tell me which, and I'll arrange collection with you. No sign-up or account needed.
@endif

Thanks,
{{ $signoffName }}

@foreach($items as $i => $item)
{{ $i + 1 }}. {{ $item['name'] }}@if(!empty($item['condition'])) ({{ $item['condition'] }})@endif
@if(!empty($item['description'])){{ $item['description'] }}
@endif
@endforeach

---
You're receiving this because we found {{ $orgName ?? 'your organisation' }}'s contact details published online{{ !empty($area) ? ' for '.$area : '' }}, and the items above are local to you. If they're not useful, reply with the word UNSUBSCRIBE or email {{ $unsubscribeMailto }} and we won't contact you again.

{{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice. Registered address: {{ config('freegle.branding.registered_address') }}
