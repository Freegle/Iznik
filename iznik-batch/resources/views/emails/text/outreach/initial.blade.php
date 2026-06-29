Hello {{ $greetingName }},
@if(!empty($intro))

{{ $intro }}
@endif

I help run {{ config('freegle.branding.name') }}, a free local reuse service. Some people near you are giving away things that I thought {{ $orgName ?? 'you' }} might be able to use - all free, and the giver just wants them to go to good use rather than to the tip.

Here's what's currently available nearby:
@foreach($posts as $i => $post)
  {{ $i + 1 }}. {{ $post['title'] }}
     {{ $post['url'] }}
@endforeach

If any of these would be useful, just reply to this email and tell me which ones - I'll sort out the details and collection with you. No Freegle account or sign-up needed; we can do it all by email.

Thanks,
{{ $signoffName }}

---
You're receiving this because we found {{ $orgName ?? 'your organisation' }}'s contact details published online@if(!empty($area)) for {{ $area }}@endif, and the items above are local to you. If they're not useful, no problem - to stop receiving these emails, reply with the word UNSUBSCRIBE or email {{ $unsubscribeMailto }} and we won't contact you about reuse offers again.

{{ config('freegle.branding.name') }} is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Registered address: {{ config('freegle.branding.registered_address') }}
