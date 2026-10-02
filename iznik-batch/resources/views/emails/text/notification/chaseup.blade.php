You have {!! $count !!} notification{!! $count === 1 ? '' : 's' !!} on Freegle.

Here's what happened while you were away.

@foreach ($notifications as $notif)
---
@if ($notif['type'] === 'CommentOnCommented')
{!! $notif['fromname'] !!} replied on "{!! $notif['newsfeed']['replyto']['message'] ?? 'your thread' !!}":

{!! $notif['newsfeed']['message'] ?? '' !!}
@elseif ($notif['type'] === 'CommentOnYourPost')
{!! $notif['fromname'] !!} commented on your post:

{!! $notif['newsfeed']['message'] ?? '' !!}
@elseif ($notif['type'] === 'LovedPost')
@if (($notif['newsfeed']['type'] ?? '') === 'Noticeboard')
{!! $notif['fromname'] !!} loved your noticeboard post
@else
{!! $notif['fromname'] !!} loved your post:

{!! $notif['newsfeed']['message'] ?? '' !!}
@endif
@elseif ($notif['type'] === 'LovedComment')
{!! $notif['fromname'] !!} loved your comment:

{!! $notif['newsfeed']['message'] ?? '' !!}
@elseif ($notif['type'] === 'Exhort')
{!! $notif['title'] ?? '' !!}

{!! $notif['text'] ?? '' !!}
@elseif ($notif['type'] === 'MembershipPending')
Your application to {!! $notif['url'] ?? '' !!} needs approval. We'll let you know as soon as we hear.
@elseif ($notif['type'] === 'MembershipApproved')
You're in! Your application to {!! $notif['url'] ?? '' !!} has been approved.
@elseif ($notif['type'] === 'MembershipRejected')
Sorry, your application to {!! $notif['url'] ?? '' !!} wasn't approved.
@else
You have a notification from {!! $notif['fromname'] ?? 'Freegle' !!}
@endif

{!! $notif['timestamp'] ?? '' !!}
View on Freegle: {!! $notif['trackedUrl'] !!}
@endforeach

---
Replies, loves and nudges all live on Freegle. Pop in to catch up on the lot:
{!! $chitchatUrl !!}

---
This email was sent to {!! $email !!}
Change your email settings: {!! $settingsUrl !!}
Unsubscribe: {!! $unsubscribeUrl !!}

Freegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers.
Registered address: {{ config('freegle.branding.registered_address') }}
