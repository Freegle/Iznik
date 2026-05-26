{{ $postCount }} new post{{ $postCount === 1 ? '' : 's' }} near you
====================================

Dear {{ $user->displayname ?? 'there' }},

Here {{ $postCount === 1 ? 'is' : 'are' }} {{ $postCount }} new post{{ $postCount === 1 ? '' : 's' }} from your Freegle communities:

@foreach($posts as $post)
{{ strtoupper($post['type']) }}: {{ $post['itemName'] }}
{{ $post['arrivalFormatted'] }}
@if($post['messageText'])
{{ \Illuminate\Support\Str::limit($post['messageText'], 150) }}
@endif
@if($post['postedToText'])
{{ $post['postedToText'] }}
@endif
View: {{ $post['messageUrl'] }}

@endforeach
------------------------------------

Browse all posts: {{ $browseUrl }}
@if($sponsors->isNotEmpty())

Sponsored by:
@foreach($sponsors as $sponsor)
- {{ $sponsor->name }}{{ $sponsor->tagline ? ' - ' . $sponsor->tagline : '' }}{{ $sponsor->linkurl ? ' (' . $sponsor->linkurl . ')' : '' }}
@endforeach
@endif

------------------------------------

You're receiving this because you're a member of Freegle. These emails are sent {{ $mode === 'immediate' ? 'when new posts are available' : 'daily' }}.

Update your settings: {{ $settingsUrl }}
Unsubscribe: {{ $unsubscribeUrl ?? config('freegle.sites.user') . '/unsubscribe' }}

------------------------------------

Freegle - Don't throw it away, give it away!
{{ $browseUrl }}
