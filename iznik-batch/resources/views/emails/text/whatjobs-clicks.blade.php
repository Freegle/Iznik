@foreach ($sections as $section)
@if ($section['heading'])
{!! $section['heading'] !!}

@endif
@foreach ($section['paragraphs'] as $paragraph)
{!! $paragraph !!}

@endforeach
@endforeach
@if ($table)
{!! implode(' | ', $table['head']) !!}
@foreach ($table['rows'] as $row)
{!! implode(' | ', $row) !!}
@endforeach
@endif
