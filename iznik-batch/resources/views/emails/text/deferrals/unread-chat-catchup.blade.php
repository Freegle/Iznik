Hi {{ $recipientName }},

@if ($provider){{ $provider }} stopped @else Your email provider stopped @endif accepting our emails on {{ $delayedSince }}, so we held off sending you notifications rather than fill your inbox with messages that would not have arrived. That is now fixed.

@if ($chatCount > 0)@if ($chatCount === 1)While it was going on you had {{ $messageCount === 1 ? 'a message' : $messageCount . ' messages' }} in one chat.@else While it was going on you had {{ $messageCount === 1 ? 'a message' : $messageCount . ' messages' }} across {{ $chatCount }} chats.@endif
@endif
@if ($modChatCount > 0)@if ($chatCount > 0)You also had @else While it was going on you had @endif{{ $modMessageCount === 1 ? 'a message' : $modMessageCount . ' messages' }} @if ($modChatCount === 1)in one chat with members of a community you moderate.@else across {{ $modChatCount }} chats with members of communities you moderate.@endif Those are in ModTools.
@endif
We are sending this one email rather than all of them.

@if ($chatCount > 0)Read your messages: {{ $chatsUrl }}
@endif
@if ($modChatCount > 0)Read your messages in ModTools: {{ $modChatsUrl }}
@endif

Sorry about the gap. It was our problem, not yours, and nothing you sent was lost.

--
Change your email settings: {{ $settingsUrl }}
