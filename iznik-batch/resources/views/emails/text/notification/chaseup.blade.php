You have {!! $count !!} notification{!! $count === 1 ? '' : 's' !!} on Freegle.

@foreach ($notifications as $notif)
---
@if ($notif['type'] === 'CommentOnCommented')
{!! $notif['fromname'] !!} commented on "{!! $notif['newsfeed']['replyto']['message'] ?? '' !!}":

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
Your application to {!! $notif['url'] ?? '' !!} requires approval. We'll let you know soon.
@elseif ($notif['type'] === 'MembershipApproved')
Your application to {!! $notif['url'] ?? '' !!} has been approved!
@elseif ($notif['type'] === 'MembershipRejected')
Sorry, your application to {!! $notif['url'] ?? '' !!} was rejected.
@endif

View on Freegle: {!! $notif['trackedUrl'] !!}
@endforeach

---
This email was sent to {!! $email !!}
Change your email settings: {!! $settingsUrl !!}
Unsubscribe: {!! $unsubscribeUrl !!}

Freegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers.
Registered address: 64a North Road, Ormesby, Great Yarmouth, Norfolk NR29 3LE
