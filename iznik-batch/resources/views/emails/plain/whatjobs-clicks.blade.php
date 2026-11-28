<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:16px;background:#ffffff;color:#222222;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.45">
<div style="max-width:700px">
@foreach ($sections as $i => $section)
    @if ($section['heading'])
        @if ($i === 0)
            <h1 style="font-size:20px;margin:0 0 12px">{{ $section['heading'] }}</h1>
        @else
            <h2 style="font-size:16px;margin:22px 0 8px">{{ $section['heading'] }}</h2>
        @endif
    @endif
    @foreach ($section['paragraphs'] as $paragraph)
        <p style="margin:0 0 10px">{{ $paragraph }}</p>
    @endforeach
    @if ($i === $chartAfter && $chartPng)
        <p style="margin:16px 0"><img src="{{ $message->embedData($chartPng, 'whatjobs-clicks.png', 'image/png') }}" width="680" alt="{{ $chartAlt }}" style="max-width:100%;height:auto;border:1px solid #e5e5e5"></p>
    @endif
@endforeach
@if ($table)
    <table cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-top:8px">
        <tr>
            @foreach ($table['head'] as $h)
                <th style="text-align:{{ $loop->first ? 'left' : 'right' }};border-bottom:2px solid #cccccc;padding:5px 8px">{{ $h }}</th>
            @endforeach
        </tr>
        @foreach ($table['rows'] as $row)
            <tr>
                @foreach ($row as $cell)
                    <td style="text-align:{{ $loop->first ? 'left' : 'right' }};border-bottom:1px solid #eeeeee;padding:5px 8px;white-space:nowrap">{{ $cell }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endif
</div>
</body>
</html>
