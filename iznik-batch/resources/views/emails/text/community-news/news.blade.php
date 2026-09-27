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
We send this now and then to freeglers who like a bit of local goings-on.
To stop these, turn off "Newsletters & stories" in your email settings: {{ $settingsUrl }}

Give & ask for things near you: {{ $askUrl }}

{{ config('freegle.branding.name') }} is a charity run by volunteers.
