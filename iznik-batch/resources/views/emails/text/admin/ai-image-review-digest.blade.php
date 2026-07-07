AI Image Review Daily Digest
============================

Verdicts today: {{ $todayVerdicts }}
Images with quorum (5+ votes): {{ $totalReviewed }} of {{ $totalImages }} ({{ $percentReviewed }}%)
Images needing improvement: {{ $needsImproving }}

@if(count($topProblems) > 0)
Top {{ count($topProblems) }} Images Needing Improvement
(ordered by usage count, outlier voters excluded)

@foreach($topProblems as $img)
- {{ $img['name'] }} | Uses: {{ $img['usage_count'] }} | Good: {{ $img['approve_count'] }} | Bad: {{ $img['reject_count'] }} | People: {{ $img['people_count'] }}
@endforeach
@endif
