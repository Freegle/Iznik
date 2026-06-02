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

@php($summaryPosts = collect($posts))
@php($summaryVisible = \App\Mail\Digest\DigestStyle::SUMMARY_VISIBLE_LINES)
@php($summaryHidden = $summaryPosts->slice($summaryVisible))
@if($summaryPosts->count() >= 2)
In this digest:
@if(!empty($morePosts) && $morePosts > 0)
We've limited this to {{ \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP }} posts, but if you're keen there are even more on the website: {{ $browseUrl }}

@endif
@foreach($summaryPosts->take($summaryVisible) as $summaryPost)
- {!! $summaryPost['subject'] !!}: {{ $summaryPost['summaryUrl'] }}
@endforeach
@if($summaryHidden->isNotEmpty())
...and {{ $summaryHidden->count() }} more below.
@endif

@endif
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
@if(!empty($post['bulkItems']))

{{ count($post['bulkItems']) }} items in this offer:
@foreach($post['bulkItems'] as $bi)
  - {{ $bi['quantity'] }}x {!! $bi['name'] !!}@if($bi['condition']) ({{ $bi['condition'] }})@endif

@endforeach
@endif

Posted by {!! $post['posterName'] !!}{!! ($post['groupName'] ?? null) ? ' on ' . $post['groupName'] : '' !!}
@if(!empty($post['firstPostedFormatted']))
First posted {!! $post['firstPostedFormatted'] !!}
@endif
Reply: {{ $post['messageUrl'] }}

@endforeach
------------------------------------
@if(count($completedPosts ?? []) > 0)

CAME AND WENT
These were posted since your last email but have already gone. If you'd like to catch them in time, try a more frequent digest in Settings: {{ $settingsUrl }}
@foreach($completedPosts as $cp)
- {{ $cp['itemName'] }}@if($cp['locationName']) ({{ $cp['locationName'] }})@endif — {{ $cp['metaText'] }}: {{ $cp['messageUrl'] }}
@endforeach
------------------------------------
@endif

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
