Server sizing
=============

We pay about {{ $monthlyPaid }} a month for servers and disk.
@if ($possibleSaving)
The moves down suggested below would save {{ $possibleSaving }} a month.
@else
No change that saves money is suggested this week.
@endif
@if ($possibleCost)
The moves up suggested below would cost {{ $possibleCost }} a month more.
@endif
@if ($historyDays < $minHistoryDays)

There are {{ $historyDays }} day(s) of readings so far. A move down is only suggested after readings on {{ $minHistoryDays }} days, so this report shows where things stand and what the readings point to.
@endif

@if ($review)
@foreach (['changes' => 'Changes worth making', 'watch' => 'Worth watching', 'caveats' => 'What the rules cannot see'] as $key => $heading)
@if (!empty($review[$key]))
{{ $heading }}
@foreach ($review[$key] as $line)
- {!! $line !!}
@endforeach

@endif
@endforeach
This review was written by AI from the figures below. The verdict on each server comes from fixed rules, not from the AI.
@else
The AI review was not available this week. The figures and verdicts below are complete without it.
@endif

@foreach ($cards as $card)
{!! $card['name'] !!}
{{ str_repeat('-', mb_strlen($card['name'])) }}
{!! $card['package'] !!}. {!! $card['cost'] !!}.
{!! $card['verdict'] !!}
@foreach ($card['reasons'] as $reason)
{!! $reason !!}
@endforeach
@foreach ($card['lines'] as $line)
  {!! $line[0] !!}: {!! $line[1] !!}
@endforeach
@if (!empty($card['trend']))
  Week by week:
@foreach ($card['trend'] as $row)
    {!! $row !!}
@endforeach
@endif

@endforeach
@if (!empty($unmatchedHosts))
Sampled, but not a server Katapult lists
{!! implode(', ', $unmatchedHosts) !!}. Its readings are stored but it is not in this report. Check the name it gives itself against its name on Katapult.

@endif
@if (!empty($unattached))
Disks to look at
@foreach ($unattached as $line)
- {!! $line !!}
@endforeach

@endif
How to read this. "Busy end" is the level a server stayed under for 95% of the week. A smaller package is suggested only if the busiest week in up to eight would leave headroom on it: load at most 70% of the processors, processor time at most 60% in use, memory at most 80% used. Only packages of the same kind are compared. A bigger one is suggested when load is above the number of processors, processor time is above 85% in use, or memory is above 92% used. A disk is flagged at 85% full, or when its growth would take it to 90% within eight weeks.

Costs are Katapult's monthly prices for our account ({{ $monthlyList }} in all){{ $discountPct > 0 ? ", less our {$discountPct}% discount" : "" }}. They leave out VAT, data transfer and backups, so check a figure against an invoice before relying on it. Taking processors or memory away needs the server shut down; adding them does not. Disks can grow but never shrink.

This is an automated report from {{ $siteName }}, sent weekly by servers:rightsize-report.
