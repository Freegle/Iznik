Community News near you
==============================

{{ trim($intro) }}

@foreach ($items as $item)
* {{ $item['title'] }}
{{ trim($item['blurb']) }}
@if (!empty($item['url']))  {{ $item['url'] }}
@endif

@endforeach
@if (!empty($story))
A freegler near you says...
"{{ $story['headline'] }}"
{{ trim($story['story']) }}
-- {{ $story['name'] }}

@endif
--
You're getting this because you're a Freegle member near {{ $areaName }}.
To stop these, turn off "Newsletters & stories" in your email settings: {{ $settingsUrl }}

Give & ask for things near you: {{ $askUrl }}

{{ config('freegle.branding.name') }} is a charity run by volunteers.
