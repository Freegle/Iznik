Hi {{ $recipientName }},

@if ($provider){{ $provider }} stopped @else Your email provider stopped @endif accepting our emails on {{ $delayedSince }}, so we held off sending you notifications rather than fill your inbox with messages that would not have arrived. That is now fixed.

@if ($chatCount === 1)While it was going on you had {{ $messageCount === 1 ? 'a message' : $messageCount . ' messages' }} in one chat.@else While it was going on you had {{ $messageCount === 1 ? 'a message' : $messageCount . ' messages' }} across {{ $chatCount }} chats.@endif We are sending this one email rather than all of them.

Read your messages: {{ $chatsUrl }}

Sorry about the gap. It was our problem, not yours, and nothing you sent was lost.

--
Change your email settings: {{ $settingsUrl }}
