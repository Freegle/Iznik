{{-- Plain-text body. Use {!! !!} (no HTML-escape) so names and addresses read as typed. --}}
@if($ended)
Sponsorship ended without renewal
=================================

{!! $partnershipName !!} ({!! $authorityName !!}) ended on {{ $endDate }}, and nothing has been agreed to follow it.
@else
Sponsorship renewal due
=======================

{!! $partnershipName !!} ({!! $authorityName !!}) ends on {{ $endDate }}, which is {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} away.
@endif

Value of the deal: £{{ number_format($amount) }}
@if(count($contacts))

Council {{ Str::plural('contact', count($contacts)) }}:
@foreach($contacts as $contact)
  {!! trim(($contact['name'] ?? '') . ($contact['email'] ? ' <' . $contact['email'] . '>' : '')) !!} ({{ $contact['role'] }})
@endforeach
@endif

@if($ended)
By now the council should have renewed and paid for the next year. The sponsor logo and
tagline have stopped showing to members. Worth chasing, or recording on the page that they
are not renewing.
@else
This is the point to ask the council about next year. The sponsor logo and tagline stop
showing to members on the day it ends, and a council's budget round takes months.
@endif

Manage this partnership: {{ $modToolsUrl }}
