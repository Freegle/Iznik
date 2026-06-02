@php($isSingle = $mode === 'immediate')
@php($heading = $isSingle ? ($postCount.' new post'.($postCount === 1 ? '' : 's').' near you') : "What's New")
{{-- Plain-text body. Use {!! !!} (no HTML-escape) so apostrophes and
     emoji-bearing strings render literally — Blade's default {{ }}
     runs htmlspecialchars, which turns "What's New" into the literal
     "What&#039;s New" in the text/plain MIME part. --}}
{!! $heading !!}
====================================

@unless($isSingle)
Here are {{ $postCount }} new posts from your Freegle communities:

@endunless
@foreach($posts as $post)
{!! strtoupper($post['type']) !!}: {!! $post['itemName'] !!}
@if($post['locationName'] ?? null)
Location: {!! $post['locationName'] !!}
@endif
{!! $post['distanceText'] ? $post['distanceText'].' away, ' : '' !!}{!! $post['arrivalFormatted'] !!}
@if($post['messageText'])

{!! $isSingle ? $post['messageText'] : \Illuminate\Support\Str::limit($post['messageText'], 200) !!}
@endif

Posted by {!! $post['posterName'] !!}@if($post['groupName'] ?? null) on {!! $post['groupName'] !!}@endif
@if(!empty($post['firstPostedFormatted']))
First posted {!! $post['firstPostedFormatted'] !!}
@endif
Reply: {{ $post['messageUrl'] }}

@endforeach
------------------------------------

Browse all posts: {{ $browseUrl }}
@if(isset($jobAds) && $jobAds->isNotEmpty())

Jobs near you:
@foreach($jobAds as $job)
- {!! $job->title !!}{{ ($job->location ?? null) ? ' (' . $job->location . ')' : '' }}: {{ $job->tracked_url }}
@endforeach
If you are interested and click, it raises a little to help keep Freegle running and free to use.
View more jobs: {{ $jobsUrl }}
@endif
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
