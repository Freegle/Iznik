Community News for {{ $areaName }}
==============================

{{ trim($intro) }}

@foreach ($items as $item)
* {{ $item['title'] }}
{{ trim($item['blurb']) }}
@if (!empty($item['url']))  {{ $item['url'] }}
@endif

@endforeach
--
You're getting this because you're a Freegle member near {{ $areaName }} and haven't turned off "Newsletters & stories".

Give & find things near you: {{ $findUrl }}
Change your email settings: {{ $settingsUrl }}
Unsubscribe: {{ $unsubscribeUrl }}

{{ config('freegle.branding.name') }} is a charity run by volunteers.
